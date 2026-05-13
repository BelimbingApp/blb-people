<?php

namespace App\Modules\People\Claim\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimPolicyBand extends Model
{
    protected $table = 'people_claim_policy_bands';

    /** @var list<string> */
    protected $fillable = [
        'claim_policy_id',
        'logical_operator',
        'threshold_value',
        'rate',
        'per_day_unit_limit',
        'per_claim_limit',
        'per_month_limit',
        'per_year_limit',
        'sort_order',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'threshold_value' => 'decimal:4',
            'rate' => 'decimal:4',
            'per_day_unit_limit' => 'decimal:2',
            'per_claim_limit' => 'decimal:2',
            'per_month_limit' => 'decimal:2',
            'per_year_limit' => 'decimal:2',
            'sort_order' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ClaimPolicy::class, 'claim_policy_id');
    }
}
