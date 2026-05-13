<?php

namespace App\Modules\People\Attendance\Models;

use App\Base\Database\Concerns\HasCompanyScopedExternalLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendancePolicyGroup extends Model
{
    use HasCompanyScopedExternalLifecycle;

    protected $table = 'people_attendance_policy_groups';

    protected $fillable = [
        ...self::COMPANY_SCOPED_EXTERNAL_LIFECYCLE_FILLABLE,
        'code',
        'name',
        'cohort_predicate',
        'work_hour_rules',
        'lateness_rules',
        'overtime_rules',
        'overtime_export_rules',
        'lateness_export_rules',
        'payroll_defaults',
        'version',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'cohort_predicate' => 'array',
            'work_hour_rules' => 'array',
            'lateness_rules' => 'array',
            'overtime_rules' => 'array',
            'overtime_export_rules' => 'array',
            'lateness_export_rules' => 'array',
            'payroll_defaults' => 'array',
            ...self::COMPANY_SCOPED_EXTERNAL_LIFECYCLE_CASTS,
            'version' => 'integer',
        ];
    }

    public function allowanceRules(): HasMany
    {
        return $this->hasMany(AttendanceAllowanceRule::class, 'attendance_policy_group_id');
    }
}
