<?php

namespace Tests\Unit;

use App\Models\Users;
use App\Services\Membership\MembershipAccessService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class MembershipBenefitContractTest extends TestCase
{
    public function test_active_memberships_and_premium_trials_expose_advertised_limits(): void
    {
        foreach ([['standard', 'active', 3, 15], ['premium', 'active', 5, 30], ['premium', 'trial', 5, 30]] as [$package, $status, $challenges, $minutes]) {
            $user = (new Users())->forceFill(['id' => 42]);
            $user->setRelation('subscription', null);
            $row = (object) [
                'selected_package' => $status === 'trial' ? 'standard' : $package,
                'effective_package' => $package, 'access_status' => $status,
                'payment_path' => 'annual', 'access_source' => 'store',
                'access_ends_at' => now()->addDay(),
                'trial_ends_at' => $status === 'trial' ? now()->addDay() : null,
            ];
            $access = Mockery::mock(Builder::class);
            $access->shouldReceive('where')->with('user_id', 42)->once()->andReturnSelf();
            $access->shouldReceive('first')->once()->andReturn($row);
            DB::shouldReceive('table')->with('account_access')->once()->andReturn($access);
            $trials = Mockery::mock(Builder::class);
            $trials->shouldReceive('where')->andReturnSelf();
            $trials->shouldReceive('first')->once()->andReturnNull();
            DB::shouldReceive('table')->with('membership_trials')->once()->andReturn($trials);
            $categories = Mockery::mock(Builder::class);
            $categories->shouldReceive('where', 'orderBy')->andReturnSelf();
            $categories->shouldReceive('pluck')->once()->andReturn(collect([1, 2, 3, 4, 5]));
            DB::shouldReceive('table')->with('membership_categories')->once()->andReturn($categories);
            $ack = Mockery::mock(Builder::class);
            $ack->shouldReceive('where')->andReturnSelf();
            $ack->shouldReceive('exists')->once()->andReturnTrue();
            DB::shouldReceive('table')->with('membership_announcement_acknowledgements')->once()->andReturn($ack);

            $snapshot = (new MembershipAccessService())->snapshot($user);
            $this->assertSame($package, $snapshot['effective_package']);
            $this->assertSame($status, $snapshot['access_status']);
            $this->assertSame($challenges, $snapshot['limits']['daily_challenges']);
            $this->assertSame($minutes, $snapshot['limits']['blocked_app_usage_minutes']);
        }
    }
}
