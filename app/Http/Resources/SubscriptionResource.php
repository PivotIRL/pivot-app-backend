<?php

namespace App\Http\Resources;

use App\Models\Tier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function __construct($resource, private readonly ?array $membership = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $tier = $this->tier ? [
            'id' => $this->tier->id,
            'name' => $this->tier->name,
        ] : null;
        $active = $this->active;
        $productId = $this->product_id;
        $expiresAt = $this->expires_at;

        if ($this->membership !== null) {
            $tierName = match ($this->membership['effective_package']) {
                'standard' => '2',
                'premium' => '3',
                default => '1',
            };
            $tier = [
                'id' => (int) (Tier::query()->where('name', $tierName)->value('id') ?? $tierName),
                'name' => $tierName,
            ];
            $active = in_array($this->membership['access_status'], ['active', 'trial', 'billing_restricted'], true)
                && $this->membership['effective_package'] !== null;
            $productId = $this->membership['product_id'];
            $expiresAt = $this->membership['access_ends_at'];
        }

        return [
            'id' => $this->id,
            'tier' => $tier,
            'type' => $this->type,
            'environment' => $this->environment,
            'active' => $active,
            'store' => $this->store,
            'product_id' => $productId,
            'started_at' => $this->started_at,
            'expires_at' => $expiresAt,
        ];
    }
}
