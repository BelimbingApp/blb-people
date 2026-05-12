<?php

namespace App\Modules\People\Leave\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveEntitlementPolicyBand extends Model
{
    protected $table = 'leave_entitlement_policy_bands';

    /** @var list<string> */
    protected $fillable = [
        'leave_entitlement_policy_id',
        'min_years_of_service',
        'max_years_of_service',
        'entitlement_days',
        'bring_forward_cap_days_override',
        'sort_order',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'min_years_of_service' => 'decimal:2',
            'max_years_of_service' => 'decimal:2',
            'entitlement_days' => 'decimal:4',
            'bring_forward_cap_days_override' => 'decimal:4',
            'sort_order' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(LeaveEntitlementPolicy::class, 'leave_entitlement_policy_id');
    }
}
