<?php

namespace App\Modules\People\Attendance\Models;

use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceAllowanceRule extends Model
{
    public const TYPE_DAILY = 'daily';

    public const TYPE_MONTHLY = 'monthly';

    public const RESOLUTION_SUM = 'sum';

    public const RESOLUTION_MIN = 'min';

    public const RESOLUTION_MAX = 'max';

    protected $table = 'people_attendance_allowance_rules';

    protected $fillable = [
        'company_id',
        'attendance_policy_group_id',
        'attendance_shift_template_id',
        'code',
        'name',
        'allowance_type',
        'payroll_pay_item_code',
        'ceiling_amount',
        'resolution_method',
        'condition_rows',
        'source_script',
        'effective_from',
        'effective_to',
        'status',
        'source_system',
        'source_label',
        'source_code',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'ceiling_amount' => 'decimal:2',
            'condition_rows' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function policyGroup(): BelongsTo
    {
        return $this->belongsTo(AttendancePolicyGroup::class, 'attendance_policy_group_id');
    }

    public function shiftTemplate(): BelongsTo
    {
        return $this->belongsTo(AttendanceShiftTemplate::class, 'attendance_shift_template_id');
    }
}
