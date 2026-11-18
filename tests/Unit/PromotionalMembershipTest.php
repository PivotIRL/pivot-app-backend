<?php
namespace Tests\Unit;
use App\Services\Membership\MembershipReconciliationService;
use Illuminate\Support\Carbon;
use Tests\TestCase;
class PromotionalMembershipTest extends TestCase {
    private function candidate(array $changes = []): ?array {
        $reflection = new \ReflectionClass(MembershipReconciliationService::class);
        return $reflection->getMethod('promotionalCandidate')->invoke($reflection->newInstanceWithoutConstructor(), array_replace([
            'store' => 'promotional', 'environment' => 'production', 'gives_access' => true,
            'ends_at' => now()->addDay()->getTimestampMs(), 'product_id' => 'rc_promo_test',
            'entitlements' => ['items' => [['state' => 'active', 'lookup_key' => 'Premium Android']]],
        ], $changes));
    }
    public function test_verified_promotion_uses_entitlement_and_exact_expiry(): void {
        $end = now()->addHour()->getTimestampMs();
        $result = $this->candidate(['ends_at' => $end]);
        $this->assertSame('premium', $result['effective_package']);
        $this->assertSame($end, $result['access_ends_at']->getTimestampMs());
        $this->assertNull($result['payment_path']);
    }
    public function test_lifetime_promotion_requires_explicit_null_end(): void {
        $result = $this->candidate(['ends_at' => null]);
        $this->assertSame('lifetime', $result['payment_path']);
        $this->assertNull($result['access_ends_at']);
    }
    public function test_expired_revoked_unknown_and_untrusted_records_cannot_grant_access(): void {
        foreach ([['ends_at' => now()->subSecond()->getTimestampMs()], ['gives_access' => false], ['store' => 'play_store'], ['environment' => 'sandbox'], ['entitlements' => ['items' => []]]] as $change) {
            $this->assertNull($this->candidate($change));
        }
    }
}
