<?php

namespace App\Modules\People\Attendance\Models;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRosterAssignment extends Model
{
    protected $table = 'attendance_roster_assignments';

    protected $fillable = [
        'company_id',
        'employee_id',
        'attendance_roster_pattern_id',
        'attendance_shift_template_id',
        'attendance_policy_group_id',
        'cohort_predicate',
        'effective_from',
        'effective_to',
        'publish_state',
        'lock_state',
        'revision',
        'exceptions',
        'source_system',
        'source_label',
        'source_code',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'cohort_predicate' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'revision' => 'integer',
            'exceptions' => 'array',
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

    public function shiftTemplate(): BelongsTo
    {
        return $this->belongsTo(AttendanceShiftTemplate::class, 'attendance_shift_template_id');
    }

    public function policyGroup(): BelongsTo
    {
        return $this->belongsTo(AttendancePolicyGroup::class, 'attendance_policy_group_id');
    }

    public function rosterPattern(): BelongsTo
    {
        return $this->belongsTo(AttendanceRosterPattern::class, 'attendance_roster_pattern_id');
    }
}
