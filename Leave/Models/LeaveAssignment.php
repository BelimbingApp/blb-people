<?php

namespace App\Modules\People\Leave\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveAssignment extends Model
{
    protected $table = 'people_leave_assignments';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'code',
        'name',
        'leave_type_id',
        'leave_entitlement_policy_id',
        'leave_request_policy_id',
        'cohort_predicate',
        'effective_from',
        'effective_to',
        'status',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cohort_predicate' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function entitlementPolicy(): BelongsTo
    {
        return $this->belongsTo(LeaveEntitlementPolicy::class, 'leave_entitlement_policy_id');
    }

    public function requestPolicy(): BelongsTo
    {
        return $this->belongsTo(LeaveRequestPolicy::class, 'leave_request_policy_id');
    }
}
