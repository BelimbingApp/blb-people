<?php

namespace App\Modules\People\Attendance\Models;

use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRosterCellLog extends Model
{
    protected $table = 'people_attendance_roster_cell_log';

    protected $fillable = [
        'company_id',
        'assignment_id',
        'employee_id',
        'date',
        'changed_by',
        'action',
        'previous_shift_id',
        'previous_policy_id',
        'new_shift_id',
        'new_policy_id',
        'note',
        'job',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'changed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(AttendanceRosterAssignment::class, 'assignment_id');
    }

    public function previousShift(): BelongsTo
    {
        return $this->belongsTo(AttendanceShiftTemplate::class, 'previous_shift_id');
    }

    public function newShift(): BelongsTo
    {
        return $this->belongsTo(AttendanceShiftTemplate::class, 'new_shift_id');
    }

    public function previousPolicy(): BelongsTo
    {
        return $this->belongsTo(AttendancePolicyGroup::class, 'previous_policy_id');
    }

    public function newPolicy(): BelongsTo
    {
        return $this->belongsTo(AttendancePolicyGroup::class, 'new_policy_id');
    }
}
