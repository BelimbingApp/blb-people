<?php

namespace App\Modules\People\Leave\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveEntitlementPolicy extends Model
{
    public const ACCRUAL_ANNUAL_LUMP_NO_PRORATE = 'annual_lump_no_prorate';
    public const ACCRUAL_MONTHLY = 'monthly_accrual';
    public const ACCRUAL_EARNED_UNTIL_MONTH_N = 'earned_until_month_n';
    public const ACCRUAL_ANNIVERSARY = 'anniversary';

    public const ROUNDING_NONE = 'none';
    public const ROUNDING_NEAREST_DAY = 'nearest_1_day';
    public const ROUNDING_NEAREST_HALF_DAY = 'nearest_half_day';

    public const ANCHOR_YEAR_START = 'year_start';
    public const ANCHOR_ANNIVERSARY = 'anniversary';

    protected $table = 'people_leave_entitlement_policies';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'leave_type_id',
        'code',
        'name',
        'accrual_method',
        'earned_until_month',
        'entitlement_rounding',
        'prorate_for_joiners',
        'prorate_for_leavers',
        'bring_forward_cap_days',
        'bring_forward_expiry_month',
        'bring_forward_anchor',
        'eligibility_predicate',
        'effective_from',
        'effective_to',
        'statutory_floor_pack_identifier',
        'statutory_floor_pack_version',
        'version',
        'status',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'earned_until_month' => 'integer',
            'bring_forward_expiry_month' => 'integer',
            'prorate_for_joiners' => 'bool',
            'prorate_for_leavers' => 'bool',
            'bring_forward_cap_days' => 'decimal:4',
            'eligibility_predicate' => 'array',
            'metadata' => 'array',
            'version' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function bands(): HasMany
    {
        return $this->hasMany(LeaveEntitlementPolicyBand::class)->orderBy('sort_order');
    }
}
