<?php
namespace App\Services\Membership;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class LifetimeRenewalCancellation
{
    public function __construct(private readonly GooglePlaySubscriptions $play) {}

    private function orderRoot(string $order): string { return explode('..', $order)[0]; }

    /** Match Google's verified token to a receipt already owned by this authenticated account. */
    public function validateToken(array $purchase, object $receipt, string $environment): array
    {
        $actualEnvironment = array_key_exists('testPurchase', $purchase) ? 'sandbox' : 'production';
        if ($actualEnvironment !== $environment) throw new RuntimeException('Purchase environment mismatch.');
        $lines = array_values(array_filter($purchase['lineItems'] ?? [], fn ($line) =>
            ($line['productId'] ?? null) === $receipt->product_id
            && ($line['offerDetails']['basePlanId'] ?? null) === $receipt->base_plan_id));
        if (count($lines) !== 1 || count($purchase['lineItems'] ?? []) !== 1) throw new RuntimeException('Subscription product mismatch.');
        $line = $lines[0];
        $order = $line['latestSuccessfulOrderId'] ?? $purchase['latestOrderId'] ?? '';
        $known = [$receipt->transaction_id, $receipt->original_transaction_id ?? ''];
        if ($order === '' || ! collect($known)->contains(fn ($id) => $id !== '' && $this->orderRoot($id) === $this->orderRoot($order))) {
            throw new RuntimeException('Subscription ownership could not be verified.');
        }
        if (! isset($line['autoRenewingPlan']) || isset($line['autoRenewingPlan']['installmentDetails'])) {
            throw new RuntimeException('This subscription cannot be converted automatically.');
        }
        return $line;
    }

    public function prepare(User $user, string $key, string $target, string $token): void
    {
        if (! $this->play->ready()) throw new RuntimeException('Automatic renewal cancellation is not configured.');
        $environment = app(MembershipEnvironment::class)->forUser($user);
        $access = app(MembershipAccessService::class)->snapshot($user);
        if ($access['access_status'] !== 'active' || $access['access_source'] !== 'store'
            || ! in_array($access['payment_path'], ['monthly', 'annual'], true)
            || ! ($target === 'premium' || $target === $access['selected_package'])) {
            throw new RuntimeException('An active compatible subscription is required.');
        }
        $source = explode(':', $access['product_id'])[0];
        $receipts = DB::table('verified_purchases')->where('user_id', $user->id)->where('environment', $environment)
            ->whereIn('payment_path', ['monthly', 'annual'])->whereNull('revoked_at')->where('expires_at', '>', now())
            ->orderByDesc('expires_at')->get()->unique(fn ($receipt) => implode('|', [
                $receipt->store, $receipt->product_id, $receipt->base_plan_id, $this->orderRoot($receipt->transaction_id),
            ]))->values();
        if ($receipts->count() !== 1 || $receipts[0]->product_id !== $source || strtolower($receipts[0]->store) !== 'play_store') {
            throw new RuntimeException('A single verified Google Play subscription is required.');
        }
        $receipt = $receipts[0];
        $line = $this->validateToken($this->play->get($token), $receipt, $environment);
        if (empty($line['expiryTime']) || ! now()->lessThan($line['expiryTime'])) throw new RuntimeException('Subscription expired. Refresh membership.');
        DB::transaction(function () use ($user, $key, $target, $token, $receipt, $environment) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('lifetime_renewal_cancellations')->where('checkout_key', $key)->first();
            if ($existing) {
                if ($existing->user_id != $user->id || $existing->target_package !== $target || $existing->environment !== $environment
                    || $existing->token_hash !== hash('sha256', $token)) throw new RuntimeException('Checkout consent changed. Start again.');
                return;
            }
            DB::table('lifetime_renewal_cancellations')->insert([
                'user_id' => $user->id, 'checkout_key' => $key, 'environment' => $environment, 'target_package' => $target,
                'source_product' => $receipt->product_id, 'source_base_plan' => $receipt->base_plan_id,
                'source_order' => $receipt->transaction_id, 'token_hash' => hash('sha256', $token), 'encrypted_token' => Crypt::encryptString($token),
                'consented_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function prepared(User $user, string $key, string $target, string $source): ?object
    {
        if (! $this->play->ready()) return null;
        return DB::table('lifetime_renewal_cancellations')->where('user_id', $user->id)->where('checkout_key', $key)
            ->where('target_package', $target)->where('source_product', explode(':', $source)[0])
            ->where('environment', app(MembershipEnvironment::class)->forUser($user))->where('status', 'awaiting_purchase')
            ->where('consented_at', '>', now()->subMinutes(30))->first();
    }

    public function process(int $id): void
    {
        $row = DB::table('lifetime_renewal_cancellations')->where('id', $id)->first();
        if (! $row || $row->status === 'completed' || ($row->next_attempt_at && now()->lessThan($row->next_attempt_at))) return;
        $attempt = DB::table('membership_attempts')->where('user_id', $row->user_id)->where('operation', 'change')
            ->where('idempotency_key', $row->checkout_key)->where('status', 'completed')->whereNotNull('verified_transaction_id')->first();
        if (! $attempt) return; // Cancelled/failed/pending sheets can never stop renewal.
        $quote = json_decode($attempt->quote, true);
        if (($quote['cancel_renewal_after_purchase'] ?? false) !== true || ($quote['selected_package'] ?? '') !== $row->target_package
            || ($quote['payment_path'] ?? '') !== 'lifetime') return;
        $ownedLifetime = DB::table('verified_purchases')->where('user_id', $row->user_id)->where('environment', $row->environment)
            ->where('transaction_id', $attempt->verified_transaction_id)->where('product_id', $quote['product_id'])
            ->where('payment_path', 'lifetime')->where('package', $row->target_package)
            ->where('store', 'play_store')->whereNull('revoked_at')->exists();
        if (! $ownedLifetime) return;
        $claim = DB::table('lifetime_renewal_cancellations')->where('id', $id)->where('status', '!=', 'completed')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->update(['status' => 'processing', 'next_attempt_at' => now()->addMinutes(5), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if (! $claim) return;
        try {
            $token = Crypt::decryptString($row->encrypted_token);
            $receipt = (object) ['product_id' => $row->source_product, 'base_plan_id' => $row->source_base_plan,
                'transaction_id' => $row->source_order, 'original_transaction_id' => null];
            $line = $this->validateToken($this->play->get($token), $receipt, $row->environment);
            if (($line['autoRenewingPlan']['autoRenewEnabled'] ?? null) !== false) {
                $this->play->stopRenewal($token);
                $line = $this->validateToken($this->play->get($token), $receipt, $row->environment);
                if (($line['autoRenewingPlan']['autoRenewEnabled'] ?? null) !== false) throw new RuntimeException('Cancellation not yet confirmed.');
            }
            DB::table('lifetime_renewal_cancellations')->where('id', $id)->update([
                'status' => 'completed', 'encrypted_token' => null, 'completed_at' => now(), 'next_attempt_at' => null, 'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // Persist retries without serializing exceptions (which may contain credential-bearing URLs).
            DB::table('lifetime_renewal_cancellations')->where('id', $id)->update([
                'status' => 'retrying', 'next_attempt_at' => now()->addMinutes(min(60, 2 ** min(6, $row->attempts))), 'updated_at' => now(),
            ]);
        }
    }
}
