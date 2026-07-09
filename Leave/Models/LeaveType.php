<?php

namespace App\Modules\People\Leave\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    public const UNIT_DAY = 'day';

    public const UNIT_HALF_DAY = 'half_day';

    public const UNIT_HOUR = 'hour';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const PAYROLL_CODE_UNPAID_LEAVE = 'unpaid_leave';

    public const PAYROLL_CODE_UNAUTHORIZED_ABSENCE = 'unauthorized_absence';

    public const PAYROLL_CODE_LEAVE_ENCASHMENT = 'leave_encashment';

    public const PAYROLL_CODE_REPLACEMENT_LEAVE_PAYOUT = 'replacement_leave_payout';

    protected $table = 'people_leave_types';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'code',
        'name',
        'paid',
        'default_unit',
        'hour_quantum_minutes',
        'default_approval_depth',
        'interacts_with_payroll',
        'compulsory_attachment',
        'audit_tag',
        'description',
        'status',
        'pack_identifier',
        'pack_version',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'paid' => 'bool',
            'interacts_with_payroll' => 'bool',
            'compulsory_attachment' => 'bool',
            'hour_quantum_minutes' => 'integer',
            'default_approval_depth' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function entitlementPolicies(): HasMany
    {
        return $this->hasMany(LeaveEntitlementPolicy::class);
    }

    public function requestPolicies(): HasMany
    {
        return $this->hasMany(LeaveRequestPolicy::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LeaveAssignment::class);
    }
}
