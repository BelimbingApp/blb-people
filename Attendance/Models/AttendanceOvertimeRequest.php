<?php

namespace App\Modules\People\Attendance\Models;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceOvertimeRequest extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUS_QUEUED_FOR_PAYROLL = 'queued_for_payroll';

    public const STATUS_PAID = 'paid';

    protected $table = 'attendance_overtime_requests';

    protected $fillable = [
        'company_id',
        'employee_id',
        'attendance_day_id',
        'request_mode',
        'status',
        'starts_at',
        'ends_at',
        'requested_minutes',
        'approved_minutes',
        'payable_minutes',
        'reason',
        'attachment_count',
        'approval_profile_key',
        'approval_route_snapshot',
        'policy_snapshot',
        'submitted_by_user_id',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'cancelled_at',
        'withdrawn_at',
        'queued_for_payroll_at',
        'paid_at',
        'decision_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'requested_minutes' => 'integer',
            'approved_minutes' => 'integer',
            'payable_minutes' => 'integer',
            'attachment_count' => 'integer',
            'approval_route_snapshot' => 'array',
            'policy_snapshot' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'queued_for_payroll_at' => 'datetime',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function attendanceDay(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class, 'attendance_day_id');
    }
}
