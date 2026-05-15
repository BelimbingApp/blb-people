<?php

namespace App\Modules\People\Attendance\Models;

use App\Base\Database\Concerns\BelongsToCompanyAndEmployee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceAdjustmentRequest extends Model
{
    use BelongsToCompanyAndEmployee;

    public const MODE_MISSING_PUNCH = 'missing_punch';

    public const MODE_CORRECT_EXISTING = 'correct_existing';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'people_attendance_adjustment_requests';

    protected $fillable = [
        ...self::COMPANY_EMPLOYEE_FILLABLE,
        'attendance_day_id',
        'corrects_clock_event_id',
        'applied_clock_event_id',
        'request_mode',
        'target_event_type',
        'status',
        'proposed_occurred_at',
        'reason',
        'submitted_by_user_id',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'cancelled_at',
        'decision_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'proposed_occurred_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function attendanceDay(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class, 'attendance_day_id');
    }

    public function correctsClockEvent(): BelongsTo
    {
        return $this->belongsTo(AttendanceClockEvent::class, 'corrects_clock_event_id');
    }

    public function appliedClockEvent(): BelongsTo
    {
        return $this->belongsTo(AttendanceClockEvent::class, 'applied_clock_event_id');
    }
}
