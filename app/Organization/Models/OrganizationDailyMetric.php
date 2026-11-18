<?php

namespace App\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationDailyMetric extends Model
{
    protected $fillable = [
        'organization_id',
        'metric_date',
        'eligible_members',
        'active_members',
        'access_attempts',
        'challenge_acceptances',
        'focus_minutes',
        'reclaimed_minutes',
        'monotasking_blocks',
        'context_switches_prevented',
        'active_streak_users',
        'mood_response_count',
        'average_mood_score',
        'onboarding_completed',
    ];

    protected function casts(): array
    {
        return [
            'metric_date' => 'date',
            'eligible_members' => 'integer',
            'active_members' => 'integer',
            'access_attempts' => 'integer',
            'challenge_acceptances' => 'integer',
            'focus_minutes' => 'integer',
            'reclaimed_minutes' => 'integer',
            'monotasking_blocks' => 'integer',
            'context_switches_prevented' => 'integer',
            'active_streak_users' => 'integer',
            'mood_response_count' => 'integer',
            'average_mood_score' => 'decimal:2',
            'onboarding_completed' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
