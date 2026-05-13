<?php

namespace App\Modules\People\Attendance\Models;

use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendancePolicyGroup extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'attendance_policy_groups';

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'cohort_predicate',
        'work_hour_rules',
        'lateness_rules',
        'overtime_rules',
        'overtime_export_rules',
        'lateness_export_rules',
        'payroll_defaults',
        'effective_from',
        'effective_to',
        'version',
        'status',
        'source_system',
        'source_label',
        'source_code',
        'metadata',
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
            'effective_from' => 'date',
            'effective_to' => 'date',
            'version' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function allowanceRules(): HasMany
    {
        return $this->hasMany(AttendanceAllowanceRule::class, 'attendance_policy_group_id');
    }
}
