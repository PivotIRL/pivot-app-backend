<?php

namespace App\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationPulseMetric extends Model
{
    protected $fillable = [
        'organization_id',
        'metric_date',
        'question_key',
        'response_count',
        'average_score',
        'cohort_key',
    ];

    protected function casts(): array
    {
        return [
            'metric_date' => 'date',
            'response_count' => 'integer',
            'average_score' => 'decimal:2',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
