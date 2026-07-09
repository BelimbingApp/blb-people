<?php

namespace App\Modules\People\Payroll\Models;

use App\Modules\Core\Company\Models\Company;
use App\Modules\People\Attendance\Models\AttendanceAllowanceRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps an attendance allowance rule to a payroll pay-item code.
 *
 * Owned by Payroll. The cross-module FK (attendance_allowance_rule_id →
 * people_attendance_allowance_rules.id) is legal because Payroll depends
 * on Attendance — not the other way around.
 *
 * One mapping row is "effective" at a given date when the date falls
 * between effective_from (inclusive) and effective_to (exclusive, or
 * null = open-ended). The listener picks the row whose effective_from is
 * latest but not after the contribution's occurred-on date.
 */
class PayrollAttendanceRulePayItem extends Model
{
    protected $table = 'people_payroll_attendance_rule_pay_items';

    protected $fillable = [
        'company_id',
        'attendance_allowance_rule_id',
        'payroll_pay_item_code',
        'effective_from',
        'effective_to',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AttendanceAllowanceRule::class, 'attendance_allowance_rule_id');
    }
}
