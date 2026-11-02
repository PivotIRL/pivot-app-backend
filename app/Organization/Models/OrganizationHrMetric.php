<?php

namespace App\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationHrMetric extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'period_start',
        'period_end',
        'metric_key',
        'metric_value',
        'cohort_key',
        'source_system',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'metric_value' => 'decimal:4',
            'imported_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
