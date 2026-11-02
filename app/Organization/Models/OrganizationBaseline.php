<?php

namespace App\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationBaseline extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'baseline_start',
        'baseline_end',
        'active_users',
        'avg_focus_minutes',
        'avg_access_attempts',
        'avg_reclaimed_minutes',
        'avg_mood_score',
        'source',
        'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'baseline_start' => 'date',
            'baseline_end' => 'date',
            'active_users' => 'integer',
            'avg_focus_minutes' => 'decimal:2',
            'avg_access_attempts' => 'decimal:2',
            'avg_reclaimed_minutes' => 'decimal:2',
            'avg_mood_score' => 'decimal:2',
            'locked_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
