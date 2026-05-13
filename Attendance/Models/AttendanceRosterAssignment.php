<?php

namespace App\Modules\People\Attendance\Models;

use App\Base\Database\Concerns\BelongsToCompany;
use App\Base\Database\Concerns\BelongsToEmployee;
use App\Base\Database\Concerns\HasEffectiveDateRange;
use App\Base\Database\Concerns\TracksExternalSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRosterAssignment extends Model
{
    use BelongsToCompany;
    use BelongsToEmployee;
    use HasEffectiveDateRange;
    use TracksExternalSource;

    protected $table = 'people_attendance_roster_assignments';

    protected $fillable = [
        ...self::COMPANY_FILLABLE,
        ...self::EMPLOYEE_FILLABLE,
        'attendance_roster_pattern_id',
        'attendance_shift_template_id',
        'attendance_policy_group_id',
        'cohort_predicate',
        ...self::EFFECTIVE_DATE_RANGE_FILLABLE,
        'publish_state',
        'lock_state',
        'revision',
        'exceptions',
        ...self::EXTERNAL_SOURCE_FILLABLE,
    ];

    protected function casts(): array
    {
        return [
            'cohort_predicate' => 'array',
            ...self::EFFECTIVE_DATE_RANGE_CASTS,
            'revision' => 'integer',
            'exceptions' => 'array',
            ...self::EXTERNAL_SOURCE_CASTS,
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

    public function rosterPattern(): BelongsTo
    {
        return $this->belongsTo(AttendanceRosterPattern::class, 'attendance_roster_pattern_id');
    }
}
