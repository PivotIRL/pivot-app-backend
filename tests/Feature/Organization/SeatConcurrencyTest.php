<?php

namespace Tests\Feature\Organization;

use App\Models\User;
use App\Organization\Enums\MembershipStatus;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationMembership;
use App\Organization\Models\OrganizationRedemptionCode;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Parallel requests cannot pass the seat limit or a code's use limit.
 *
 * Each join runs in its own PHP process with its own database connection, all released at the same
 * moment. Data must be committed for the workers to see it, so this test does not use RefreshDatabase
 * and removes its own rows afterwards.
 */
class SeatConcurrencyTest extends TestCase
{
    private const WORKERS = 8;

    /** @var list<string> */
    private array $organizationIds = [];

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::table('organization_audit_logs')->whereIn('organization_id', $this->organizationIds)->delete();
        DB::table('organization_audit_logs')->whereIn('actor_user_id', $this->userIds)->delete();
        Organization::withTrashed()->whereIn('id', $this->organizationIds)->forceDelete();
        User::whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    public function test_parallel_joins_never_pass_the_seat_limit(): void
    {
        $organization = $this->organization(seatLimit: 3);
        $users = $this->users(self::WORKERS);

        $results = $this->runInParallel(array_map(fn (User $user) => ['seat', $organization->id, $user->id], $users));

        $this->assertSame(3, $this->activeCount($organization));
        $this->assertSame(3, $results['JOINED'] ?? 0, json_encode($results));
        $this->assertSame(self::WORKERS - 3, $results['SEAT_LIMIT_REACHED'] ?? 0, json_encode($results));
    }

    public function test_parallel_retries_by_the_same_user_take_one_seat(): void
    {
        $organization = $this->organization(seatLimit: 10);
        [$user] = $this->users(1);

        $results = $this->runInParallel(array_fill(0, self::WORKERS, ['seat', $organization->id, $user->id]));

        $this->assertSame(1, OrganizationMembership::where('user_id', $user->id)->count());
        $this->assertSame(1, $results['JOINED'] ?? 0, json_encode($results));
        $this->assertSame(self::WORKERS - 1, $results['ALREADY_MEMBER'] ?? 0, json_encode($results));
    }

    public function test_parallel_code_redemptions_never_pass_the_use_limit(): void
    {
        $organization = $this->organization(seatLimit: 50);
        $code = OrganizationRedemptionCode::factory()->create([
            'organization_id' => $organization->id,
            'code_hash' => hash('sha256', 'PIVOT-RACE'),
            'code_prefix' => 'PIVOT-RA',
            'starts_at' => null,
            'max_uses' => 2,
        ]);
        $users = $this->users(self::WORKERS);

        $results = $this->runInParallel(array_map(fn (User $user) => ['code', 'PIVOT-RACE', $user->id], $users));

        $this->assertSame(2, $code->fresh()->uses_count);
        $this->assertSame(2, $this->activeCount($organization));
        $this->assertSame(2, $results['JOINED'] ?? 0, json_encode($results));
        $this->assertSame(self::WORKERS - 2, $results['CODE_LIMIT_REACHED'] ?? 0, json_encode($results));
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int}>  $jobs
     * @return array<string, int> Count per join status.
     */
    private function runInParallel(array $jobs): array
    {
        $startAt = (string) (microtime(true) + 6);
        $processes = [];

        foreach ($jobs as [$mode, $target, $userId]) {
            $process = new Process(
                [PHP_BINARY, base_path('tests/Feature/Organization/Support/join_worker.php'), $mode, $target, (string) $userId, $startAt],
                base_path(),
                $this->databaseEnvironment(),
                null,
                60,
            );
            $process->start();
            $processes[] = $process;
        }

        $statuses = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $statuses[] = trim($process->getOutput());
        }

        return array_count_values($statuses);
    }

    // Workers must use the same database as this test process.
    private function databaseEnvironment(): array
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => $connection,
            'DB_HOST' => (string) $config['host'],
            'DB_PORT' => (string) $config['port'],
            'DB_DATABASE' => (string) $config['database'],
            'DB_USERNAME' => (string) $config['username'],
            'DB_PASSWORD' => (string) $config['password'],
        ];
    }

    private function organization(int $seatLimit): Organization
    {
        $organization = Organization::factory()->create(['seat_limit' => $seatLimit]);
        $this->organizationIds[] = $organization->id;

        return $organization;
    }

    /** @return list<User> */
    private function users(int $count): array
    {
        $users = [];
        for ($i = 0; $i < $count; $i++) {
            $user = User::create(['name' => 'Worker', 'email' => fake()->unique()->safeEmail(), 'password' => 'password']);
            $this->userIds[] = $user->id;
            $users[] = $user;
        }

        return $users;
    }

    private function activeCount(Organization $organization): int
    {
        return $organization->memberships()->where('status', MembershipStatus::ACTIVE)->count();
    }
}
