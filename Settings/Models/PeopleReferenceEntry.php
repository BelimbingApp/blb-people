<?php

namespace App\Modules\People\Settings\Models;

use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PeopleReferenceEntry extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const TYPE_COST_CENTER = 'cost_center';

    public const TYPE_ORGANIZATION_UNIT = 'organization_unit';

    public const TYPE_EMPLOYMENT_GROUP = 'employment_group';

    public const TYPE_JOB_TITLE = 'job_title';

    public const TYPE_WORKFORCE_CLASS = 'workforce_class';

    public const TYPE_JOB_GRADE = 'job_grade';

    public const TYPE_EMPLOYEE_SEGMENT = 'employee_segment';

    public const TYPE_DEMOGRAPHIC_ATTRIBUTE = 'demographic_attribute';

    public const TYPE_BANK = 'bank';

    public const TYPE_STATUTORY_AGENCY_OFFICE = 'statutory_agency_office';

    public const TYPE_WORK_CALENDAR = 'work_calendar';

    public const TYPE_MEDICAL_PROVIDER = 'medical_provider';

    public const TYPE_TRAINING_PROVIDER = 'training_provider';

    protected $table = 'people_reference_entries';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'parent_id',
        'type',
        'code',
        'name',
        'level',
        'description',
        'status',
        'effective_from',
        'effective_to',
        'source_system',
        'source_label',
        'source_code',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::TYPE_COST_CENTER => 'Cost Center',
            self::TYPE_ORGANIZATION_UNIT => 'Organization Unit',
            self::TYPE_EMPLOYMENT_GROUP => 'Employment Group',
            self::TYPE_JOB_TITLE => 'Job Title',
            self::TYPE_WORKFORCE_CLASS => 'Workforce Class',
            self::TYPE_JOB_GRADE => 'Job Grade',
            self::TYPE_EMPLOYEE_SEGMENT => 'Employee Segment / Qualification Group',
            self::TYPE_DEMOGRAPHIC_ATTRIBUTE => 'Demographic Attribute',
            self::TYPE_BANK => 'Bank / Bank Branch',
            self::TYPE_STATUTORY_AGENCY_OFFICE => 'Statutory Agency Office',
            self::TYPE_WORK_CALENDAR => 'Work Calendar',
            self::TYPE_MEDICAL_PROVIDER => 'Medical Provider',
            self::TYPE_TRAINING_PROVIDER => 'Training Provider',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<PeopleReferenceEntry, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<PeopleReferenceAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(PeopleReferenceAlias::class, 'people_reference_entry_id');
    }

    public function scopeForCompany(Builder $query, ?int $companyId): void
    {
        $query->where('company_id', $companyId);
    }

    public function scopeOfType(Builder $query, string $type): void
    {
        $query->where('type', $type);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }
}
