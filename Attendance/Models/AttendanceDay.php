<?php

namespace App\Modules\People\Attendance\Models;

use App\Base\Database\Concerns\BelongsToCompanyAndEmployee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceDay extends Model
{
    use BelongsToCompanyAndEmployee;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_EXCEPTION_PENDING = 'exception_pending';

    public const STATUS_READY_FOR_REVIEW = 'ready_for_review';

    public const STATUS_FINALIZED = 'finalized';

    public const STATUS_EXPORTED_TO_PAYROLL = 'exported_to_payroll';

    public const STATUS_LOCKED = 'locked';

    public const DAY_TYPE_NORMAL = 'normal';

    public const DAY_TYPE_REST = 'rest';

    public const DAY_TYPE_OFF = 'off';

    public const DAY_TYPE_HOLIDAY = 'holiday';

    protected $table = 'people_attendance_days';

    protected $fillable = [
        ...self::COMPANY_EMPLOYEE_FILLABLE,
        'attendance_roster_assignment_id',
        'attendance_shift_template_id',
        'attendance_policy_group_id',
        'attendance_date',
        'status',
        'day_type',
        'shift_starts_at',
        'shift_ends_at',
        'expected_minutes',
        'worked_minutes',
        'payable_minutes',
        'late_minutes',
        'early_out_minutes',
        'absent_minutes',
        'break_minutes',
        'overtime_candidate_minutes',
        'payroll_period_date',
        'exception_tags',
        'projection_snapshot',
        'finalized_at',
        'exported_to_payroll_at',
        'locked_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'shift_starts_at' => 'datetime',
            'shift_ends_at' => 'datetime',
            'expected_minutes' => 'integer',
            'worked_minutes' => 'integer',
            'payable_minutes' => 'integer',
            'late_minutes' => 'integer',
            'early_out_minutes' => 'integer',
            'absent_minutes' => 'integer',
            'break_minutes' => 'integer',
            'overtime_candidate_minutes' => 'integer',
            'payroll_period_date' => 'date',
            'exception_tags' => 'array',
            'projection_snapshot' => 'array',
            'finalized_at' => 'datetime',
            'exported_to_payroll_at' => 'datetime',
            'locked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function shiftTemplate(): BelongsTo
    {
        return $this->belongsTo(AttendanceShiftTemplate::class, 'attendance_shift_template_id');
    }

    public function policyGroup(): BelongsTo
    {
        return $this->belongsTo(AttendancePolicyGroup::class, 'attendance_policy_group_id');
    }

    public function clockEvents(): HasMany
    {
        return $this->hasMany(AttendanceClockEvent::class, 'attendance_day_id');
    }
}
