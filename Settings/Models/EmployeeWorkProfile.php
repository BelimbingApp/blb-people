<?php

namespace App\Modules\People\Settings\Models;

use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeWorkProfile extends Model
{
    protected $table = 'people_employee_work_profiles';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'cost_center_id',
        'organization_unit_id',
        'employment_group_id',
        'job_title_id',
        'workforce_class_id',
        'job_grade_id',
        'work_calendar_id',
        'pay_rate_type',
        'hired_on',
        'resigned_on',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hired_on' => 'date',
            'resigned_on' => 'date',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(PeopleReferenceEntry::class, 'cost_center_id');
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(PeopleReferenceEntry::class, 'organization_unit_id');
    }

    public function employmentGroup(): BelongsTo
    {
        return $this->belongsTo(PeopleReferenceEntry::class, 'employment_group_id');
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(PeopleReferenceEntry::class, 'job_title_id');
    }

    public function workforceClass(): BelongsTo
    {
        return $this->belongsTo(PeopleReferenceEntry::class, 'workforce_class_id');
    }

    public function jobGrade(): BelongsTo
    {
        return $this->belongsTo(PeopleReferenceEntry::class, 'job_grade_id');
    }

    public function workCalendar(): BelongsTo
    {
        return $this->belongsTo(PeopleReferenceEntry::class, 'work_calendar_id');
    }
}
