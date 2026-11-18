<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $age = $this->birth_year ? Carbon::now()->year - $this->birth_year : null;
        $goalHours = $this->set_your_goal ?? ($this->weekly_goal_minutes ? (int) ($this->weekly_goal_minutes / 60) : null);
        $goalMinutes = $this->weekly_goal_minutes;

        return [
            'user_name' => $this->user?->name,
            'email' => $this->user?->email,
            'country_name' => $this->country?->name,
            'gender' => $this->gender,
            'birth_year' => $this->birth_year,
            'age' => $age,
            'set_your_goal' => $goalHours !== null ? (float) $goalHours : null,
            'Set_your_goal_minutes' => $goalMinutes,
            'category_ids' => $this->hobbies->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
            'category' => $this->hobbies->pluck('name')->values()->all(),
            'onboarding_completed' => $this->onboarding_completed,
        ];
    }
}
