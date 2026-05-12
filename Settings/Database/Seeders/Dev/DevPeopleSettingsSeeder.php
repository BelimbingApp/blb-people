<?php

namespace App\Modules\People\Settings\Database\Seeders\Dev;

use App\Base\Database\Seeders\DevSeeder;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Database\Seeders\Dev\DevEmployeeSeeder;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use App\Modules\People\Settings\Models\EmployeePortalAccess;
use App\Modules\People\Settings\Models\EmployeeProfileChangeRequest;
use App\Modules\People\Settings\Models\EmployeeWorkProfile;
use App\Modules\People\Settings\Models\PeopleCalendarException;
use App\Modules\People\Settings\Models\PeopleImportJob;
use App\Modules\People\Settings\Models\PeopleNotificationDeliveryLog;
use App\Modules\People\Settings\Models\PeopleReferenceAlias;
use App\Modules\People\Settings\Models\PeopleReferenceEntry;
use App\Modules\People\Settings\Models\PeopleRestrictedPersonEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DevPeopleSettingsSeeder extends DevSeeder
{
    protected array $dependencies = [
        DevEmployeeSeeder::class,
    ];

    protected function seed(): void
    {
        Employee::query()
            ->with(['company', 'department.type', 'user'])
            ->where('employee_type', '!=', 'agent')
            ->orderBy('company_id')
            ->orderBy('employee_number')
            ->get()
            ->groupBy('company_id')
            ->each(function (Collection $employees): void {
                /** @var Employee|null $firstEmployee */
                $firstEmployee = $employees->first();
                $company = $firstEmployee?->company;

                if (! $company instanceof Company) {
                    return;
                }

                $references = $this->seedReferenceEntries($company, $employees);
                $this->seedWorkProfiles($employees, $references);
                $this->seedPortalAccess($company, $employees);
                $this->seedProfileRequests($company, $employees, $references);
                $this->seedRestrictedPerson($company);
                $this->seedImportJob($company, $employees);
                $this->seedCalendarExceptions($references['work_calendars']);
            });
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return array{
     *   cost_centers: Collection<int, PeopleReferenceEntry>,
     *   cost_centers_by_code: Collection<string, PeopleReferenceEntry>,
     *   organization_units: Collection<int, PeopleReferenceEntry>,
     *   organization_units_by_name: Collection<string, PeopleReferenceEntry>,
     *   employment_groups: Collection<int, PeopleReferenceEntry>,
     *   employment_groups_by_code: Collection<string, PeopleReferenceEntry>,
     *   job_titles: Collection<int, PeopleReferenceEntry>,
     *   job_titles_by_name: Collection<string, PeopleReferenceEntry>,
     *   workforce_classes: Collection<int, PeopleReferenceEntry>,
     *   workforce_classes_by_code: Collection<string, PeopleReferenceEntry>,
     *   job_grades: Collection<int, PeopleReferenceEntry>,
     *   job_grades_by_code: Collection<string, PeopleReferenceEntry>,
     *   work_calendars: Collection<int, PeopleReferenceEntry>,
     *   work_calendars_by_code: Collection<string, PeopleReferenceEntry>
     * }
     */
    private function seedReferenceEntries(Company $company, Collection $employees): array
    {
        $costCenters = collect([
            $this->upsertReferenceEntry($company, PeopleReferenceEntry::TYPE_COST_CENTER, 'ADMIN', 'Administration', 'Category'),
            $this->upsertReferenceEntry($company, PeopleReferenceEntry::TYPE_COST_CENTER, 'OPS', 'Operations', 'Category'),
            $this->upsertReferenceEntry($company, PeopleReferenceEntry::TYPE_COST_CENTER, 'FIN', 'Finance', 'Category'),
            $this->upsertReferenceEntry($company, PeopleReferenceEntry::TYPE_COST_CENTER, 'SALES', 'Sales', 'Category'),
            $this->upsertReferenceEntry($company, PeopleReferenceEntry::TYPE_COST_CENTER, 'TECH', 'Technology', 'Category'),
        ]);

        $employmentGroups = collect([
            $this->upsertReferenceEntry($company, PeopleReferenceEntry::TYPE_EMPLOYMENT_GROUP, 'MGMT', 'Management Group', 'Category'),
            $this->upsertReferenceEntry($company, PeopleReferenceEntry::TYPE_EMPLOYMENT_GROUP, 'EXEC', 'Executive Group', 'Category'),
            $this->upsertReferenceEntry($company, PeopleReferenceEntry::TYPE_EMPLOYMENT_GROUP, 'OPS', 'Operations Group', 'Category'),
        ]);

        $workforceClasses = collect([
            $this->upsertReferenceEntry($company, PeopleReferenceEntry::TYPE_WORKFORCE_CLASS, 'MGMT', 'Management', 'Job'),
            $this->upsertReferenceEntry($company, PeopleReferenceEntry::TYPE_WORKFORCE_CLASS, 'OFFICE', 'Office Labor', 'Job'),
            $this->upsertReferenceEntry($company, PeopleReferenceEntry::TYPE_WORKFORCE_CLASS, 'DIRECT', 'Direct Labor', 'Job'),
        ]);

        $jobGrades = collect([
            $this->upsertReferenceEntry($company, PeopleReferenceEntry::TYPE_JOB_GRADE, 'G3', 'Grade 3', 'Grade'),
            $this->upsertReferenceEntry($company, PeopleReferenceEntry::TYPE_JOB_GRADE, 'G5', 'Grade 5', 'Grade'),
            $this->upsertReferenceEntry($company, PeopleReferenceEntry::TYPE_JOB_GRADE, 'G7', 'Grade 7', 'Grade'),
        ]);

        $workCalendars = collect([
            $this->upsertReferenceEntry($company, PeopleReferenceEntry::TYPE_WORK_CALENDAR, 'MY-STD', 'Malaysia Standard Work Calendar', 'Calendar'),
            $this->upsertReferenceEntry($company, PeopleReferenceEntry::TYPE_WORK_CALENDAR, 'MY-SHIFT', 'Malaysia Shift Work Calendar', 'Calendar'),
        ]);

        $organizationUnits = $employees
            ->pluck('department.type.name')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->map(fn (string $name): PeopleReferenceEntry => $this->upsertReferenceEntry(
                $company,
                PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
                $this->code($name, 'OU'),
                $name,
                'Department',
            ))
            ->pipe(function (Collection $entries) use ($company): Collection {
                return $entries->isNotEmpty()
                    ? $entries
                    : collect([$this->upsertReferenceEntry(
                        $company,
                        PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
                        'GENERAL',
                        'General',
                        'Department',
                    )]);
            });

        $jobTitles = $employees
            ->pluck('designation')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->take(12)
            ->map(fn (string $designation): PeopleReferenceEntry => $this->upsertReferenceEntry(
                $company,
                PeopleReferenceEntry::TYPE_JOB_TITLE,
                $this->code($designation, 'JOB'),
                $designation,
                'Occupation',
            ))
            ->pipe(function (Collection $entries) use ($company): Collection {
                return $entries->isNotEmpty()
                    ? $entries
                    : collect([$this->upsertReferenceEntry(
                        $company,
                        PeopleReferenceEntry::TYPE_JOB_TITLE,
                        'STAFF',
                        'Staff',
                        'Occupation',
                    )]);
            });

        return [
            'cost_centers' => $costCenters,
            'cost_centers_by_code' => $costCenters->keyBy('code'),
            'organization_units' => $organizationUnits,
            'organization_units_by_name' => $organizationUnits->keyBy('name'),
            'employment_groups' => $employmentGroups,
            'employment_groups_by_code' => $employmentGroups->keyBy('code'),
            'job_titles' => $jobTitles,
            'job_titles_by_name' => $jobTitles->keyBy('name'),
            'workforce_classes' => $workforceClasses,
            'workforce_classes_by_code' => $workforceClasses->keyBy('code'),
            'job_grades' => $jobGrades,
            'job_grades_by_code' => $jobGrades->keyBy('code'),
            'work_calendars' => $workCalendars,
            'work_calendars_by_code' => $workCalendars->keyBy('code'),
        ];
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  array<string, Collection>  $references
     */
    private function seedWorkProfiles(Collection $employees, array $references): void
    {
        $employees
            ->where('status', '!=', 'terminated')
            ->values()
            ->each(function (Employee $employee, int $index) use ($references): void {
                $organizationUnit = $references['organization_units_by_name']->get($employee->department?->type?->name)
                    ?? $references['organization_units']->first();
                $jobTitle = $references['job_titles_by_name']->get((string) $employee->designation)
                    ?? $references['job_titles']->first();

                $workforceCode = $this->workforceClassCodeFor($employee);
                $employmentGroupCode = $this->employmentGroupCodeFor($workforceCode);
                $jobGradeCode = $this->jobGradeCodeFor($workforceCode);
                $calendarCode = $workforceCode === 'DIRECT' ? 'MY-SHIFT' : 'MY-STD';
                $costCenterCode = $this->costCenterCodeFor($employee);
                $payRateType = $workforceCode === 'DIRECT' ? 'hourly' : 'monthly';

                EmployeeWorkProfile::query()->updateOrCreate(
                    ['employee_id' => $employee->id],
                    [
                        'cost_center_id' => $references['cost_centers_by_code']->get($costCenterCode)?->id,
                        'organization_unit_id' => $organizationUnit?->id,
                        'employment_group_id' => $references['employment_groups_by_code']->get($employmentGroupCode)?->id,
                        'job_title_id' => $jobTitle?->id,
                        'workforce_class_id' => $references['workforce_classes_by_code']->get($workforceCode)?->id,
                        'job_grade_id' => $references['job_grades_by_code']->get($jobGradeCode)?->id,
                        'work_calendar_id' => $references['work_calendars_by_code']->get($calendarCode)?->id,
                        'pay_rate_type' => $payRateType,
                        'hired_on' => $employee->employment_start?->toDateString(),
                        'resigned_on' => $employee->employment_end?->toDateString(),
                        'metadata' => [
                            'source_system' => 'dev-seeder',
                            'source_label' => 'iPayroll employee bridge fixture',
                            'sequence' => $index + 1,
                        ],
                    ],
                );

                $metadata = $employee->metadata ?? [];
                $metadata['payroll_bank'] ??= [
                    'bank_name' => $index % 2 === 0 ? 'Belimbing Bank' : 'Maybank',
                    'bank_account_number' => 'AC'.str_pad((string) ($employee->id + 700000), 8, '0', STR_PAD_LEFT),
                ];
                $metadata['source_system'] ??= 'dev-seeder';

                $employee->forceFill(['metadata' => $metadata])->save();
            });
    }

    /**
     * @param  Collection<int, Employee>  $employees
     */
    private function seedPortalAccess(Company $company, Collection $employees): void
    {
        $employees
            ->where('status', 'active')
            ->take(3)
            ->values()
            ->each(function (Employee $employee, int $index) use ($company): void {
                $access = EmployeePortalAccess::query()->updateOrCreate(
                    ['employee_id' => $employee->id],
                    [
                        'user_id' => $employee->user?->id,
                        'login_identifier' => $employee->user?->email ?? $employee->email ?? $employee->employee_number,
                        'display_name' => $employee->displayName(),
                        'email' => $employee->user?->email ?? $employee->email,
                        'status' => $index === 0 ? EmployeePortalAccess::STATUS_ACTIVE : EmployeePortalAccess::STATUS_PENDING,
                        'activated_at' => $index === 0 ? now()->subDays(14) : null,
                        'last_invited_at' => now()->subDays($index + 1),
                        'metadata' => ['source_system' => 'dev-seeder'],
                    ],
                );

                PeopleNotificationDeliveryLog::query()->updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'notifiable_type' => $access::class,
                        'notifiable_id' => $access->id,
                        'channel' => 'email',
                        'recipient' => $access->email,
                        'subject' => 'Employee access invitation',
                    ],
                    [
                        'status' => $index === 0 ? 'sent' : 'queued',
                        'sent_at' => $index === 0 ? now()->subDays(1) : null,
                        'metadata' => [
                            'login_identifier' => $access->login_identifier,
                            'employee_id' => $employee->id,
                        ],
                    ],
                );
            });
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  array<string, Collection>  $references
     */
    private function seedProfileRequests(Company $company, Collection $employees, array $references): void
    {
        $employee = $employees->where('status', 'active')->values()->get(1) ?? $employees->first();

        if (! $employee instanceof Employee) {
            return;
        }

        $requester = User::query()->where('company_id', $company->id)->orderBy('id')->first();
        $costCenter = $references['cost_centers_by_code']->get('ADMIN') ?? $references['cost_centers']->first();

        EmployeeProfileChangeRequest::query()->updateOrCreate(
            [
                'employee_id' => $employee->id,
                'request_type' => 'profile_update',
                'status' => EmployeeProfileChangeRequest::STATUS_SUBMITTED,
            ],
            [
                'requested_by_user_id' => $requester?->id,
                'requested_changes' => [
                    'mobile_number' => '+60 12-'.str_pad((string) ($employee->id + 1000), 7, '0', STR_PAD_LEFT),
                    'work_profile' => [
                        'cost_center_id' => $costCenter?->id,
                        'pay_rate_type' => 'monthly',
                    ],
                ],
                'review_notes' => null,
                'submitted_at' => now()->subDays(2),
                'reviewed_at' => null,
            ],
        );
    }

    private function seedRestrictedPerson(Company $company): void
    {
        PeopleRestrictedPersonEntry::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'document_type' => 'passport',
                'document_number' => 'P-'.str_pad((string) $company->id, 6, '0', STR_PAD_LEFT),
            ],
            [
                'person_name' => $company->name.' Restricted Example',
                'status' => 'active',
                'visibility' => 'restricted',
                'summary' => 'Do not rehire without People review.',
                'details' => ['source_system' => 'dev-seeder'],
                'metadata' => ['source_system' => 'dev-seeder'],
            ],
        );
    }

    /**
     * @param  Collection<int, Employee>  $employees
     */
    private function seedImportJob(Company $company, Collection $employees): void
    {
        PeopleImportJob::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'source_system' => 'ipayroll',
                'source_label' => 'Employee',
                'target_type' => PeopleReferenceEntry::TYPE_COST_CENTER,
            ],
            [
                'created_by_user_id' => User::query()->where('company_id', $company->id)->value('id'),
                'dry_run' => true,
                'status' => PeopleImportJob::STATUS_VALIDATED,
                'summary' => [
                    'total_rows' => $employees->count(),
                    'accepted_rows' => max(1, $employees->count() - 1),
                    'error_rows' => min(1, $employees->count()),
                ],
                'row_results' => [
                    [
                        'row' => 1,
                        'employee_number' => $employees->first()?->employee_number,
                        'status' => 'accepted',
                    ],
                    [
                        'row' => 2,
                        'employee_number' => $employees->skip(1)->first()?->employee_number,
                        'status' => 'requires_alias',
                        'message' => 'Missing source code alias for imported cost center.',
                    ],
                ],
                'metadata' => ['source_system' => 'dev-seeder'],
            ],
        );
    }

    /**
     * @param  Collection<int, PeopleReferenceEntry>  $workCalendars
     */
    private function seedCalendarExceptions(Collection $workCalendars): void
    {
        $workCalendars->each(function (PeopleReferenceEntry $calendar): void {
            PeopleCalendarException::query()->updateOrCreate(
                [
                    'work_calendar_id' => $calendar->id,
                    'occurs_on' => '2026-02-17',
                    'kind' => 'non_working_day',
                ],
                [
                    'name' => $calendar->code === 'MY-SHIFT' ? 'Shift roster maintenance day' : 'Replacement holiday',
                    'metadata' => ['source_system' => 'dev-seeder'],
                ],
            );
        });
    }

    private function upsertReferenceEntry(
        Company $company,
        string $type,
        string $code,
        string $name,
        string $sourceLabel,
        ?string $level = null,
    ): PeopleReferenceEntry {
        $entry = PeopleReferenceEntry::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'type' => $type,
                'code' => $code,
            ],
            [
                'name' => $name,
                'level' => $level,
                'status' => PeopleReferenceEntry::STATUS_ACTIVE,
                'source_system' => 'ipayroll',
                'source_label' => $sourceLabel,
                'source_code' => $code,
                'metadata' => ['source_system' => 'dev-seeder'],
            ],
        );

        PeopleReferenceAlias::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'source_system' => 'ipayroll',
                'source_type' => $sourceLabel,
                'source_code' => $code,
            ],
            [
                'people_reference_entry_id' => $entry->id,
                'source_label' => $name,
                'status' => PeopleReferenceEntry::STATUS_ACTIVE,
                'metadata' => ['source_system' => 'dev-seeder'],
            ],
        );

        return $entry;
    }

    private function costCenterCodeFor(Employee $employee): string
    {
        $department = Str::lower((string) ($employee->department?->type?->name ?? ''));
        $designation = Str::lower((string) ($employee->designation ?? ''));

        return match (true) {
            str_contains($department, 'finance'), str_contains($designation, 'account') => 'FIN',
            str_contains($department, 'sales'), str_contains($designation, 'sales') => 'SALES',
            str_contains($department, 'it'), str_contains($designation, 'developer'), str_contains($designation, 'technology') => 'TECH',
            str_contains($department, 'operation'), str_contains($department, 'production'), str_contains($department, 'logistics') => 'OPS',
            default => 'ADMIN',
        };
    }

    private function workforceClassCodeFor(Employee $employee): string
    {
        $designation = Str::lower((string) ($employee->designation ?? ''));
        $department = Str::lower((string) ($employee->department?->type?->name ?? ''));

        return match (true) {
            str_contains($designation, 'chief'),
            str_contains($designation, 'head'),
            str_contains($designation, 'manager') => 'MGMT',
            str_contains($designation, 'supervisor'),
            str_contains($designation, 'technician'),
            str_contains($department, 'production'),
            str_contains($department, 'logistics') => 'DIRECT',
            default => 'OFFICE',
        };
    }

    private function employmentGroupCodeFor(string $workforceClassCode): string
    {
        return match ($workforceClassCode) {
            'MGMT' => 'MGMT',
            'DIRECT' => 'OPS',
            default => 'EXEC',
        };
    }

    private function jobGradeCodeFor(string $workforceClassCode): string
    {
        return match ($workforceClassCode) {
            'MGMT' => 'G7',
            'DIRECT' => 'G3',
            default => 'G5',
        };
    }

    private function code(string $value, string $fallback): string
    {
        $slug = Str::upper(str_replace('-', '_', Str::slug($value, '-')));

        return Str::limit($slug !== '' ? $slug : $fallback, 24, '');
    }
}
