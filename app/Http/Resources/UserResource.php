<?php

namespace App\Http\Resources;

use App\Services\Membership\MembershipAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $membership = app(MembershipAccessService::class)->snapshot($this->resource);
        $subscription = $this->resource->relationLoaded('subscription')
            ? $this->resource->getRelation('subscription')
            : $this->resource->subscription;

        return [
            'id'       => $this->id,
            'name'     => $this->name,
            'email'    => $this->email,
            'status'   => $this->status,
            'provider' => $this->provider,
            'created_at' => $this->created_at,
            'subscription' => $subscription ? new SubscriptionResource($subscription, $membership) : null,
            'membership' => $membership,
        ];
    }
}
