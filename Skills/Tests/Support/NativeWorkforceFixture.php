<?php

namespace App\Domains\People\Skills\Tests\Support;

use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class NativeWorkforceFixture
{
    public static function create(int $tenantId, WorkforceResourceType|string $type, ?int $companyId = null): Model
    {
        $type = $type instanceof WorkforceResourceType ? $type : WorkforceResourceType::from($type);

        if ($type === WorkforceResourceType::Company) {
            return Company::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);
        }

        $companies = Company::query()->forTenant($tenantId);
        $company = $companyId === null
            ? $companies->firstOrFail()
            : $companies->findOrFail($companyId);

        return match ($type) {
            WorkforceResourceType::Employee => Employee::factory()->create([
                'company_id' => $company->id,
                'status' => 'active',
            ]),
            WorkforceResourceType::User => User::factory()->create(['company_id' => $company->id]),
            WorkforceResourceType::OrganizationUnit,
            WorkforceResourceType::Position => self::createRelationship($company, $type),
            default => throw new \LogicException("Unsupported native workforce fixture type [{$type->value}]."),
        };
    }

    private static function createRelationship(Company $company, WorkforceResourceType $type): PeopleReferenceEntry
    {
        $entry = PeopleReferenceEntry::query()->create([
            'company_id' => $company->id,
            'type' => $type === WorkforceResourceType::OrganizationUnit
                ? PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT
                : PeopleReferenceEntry::TYPE_JOB_TITLE,
            'code' => Str::lower(Str::random(12)),
            'name' => Str::random(16),
            'status' => PeopleReferenceEntry::STATUS_ACTIVE,
        ]);
        $employee = Employee::factory()->create(['company_id' => $company->id, 'status' => 'active']);
        $attribute = $type === WorkforceResourceType::OrganizationUnit
            ? 'organization_unit_id'
            : 'job_title_id';

        EmployeeWorkProfile::query()->updateOrCreate(
            ['employee_id' => $employee->id],
            [$attribute => $entry->id],
        );

        return $entry;
    }
}
