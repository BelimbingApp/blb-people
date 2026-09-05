<?php

namespace App\Domains\People\Provider\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceCompany;
use App\Domains\People\Provider\Data\WorkforceEmployee;
use App\Domains\People\Provider\Data\WorkforceRemapFact;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class NativeWorkforceDirectory implements ReadsWorkforceDirectory
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function companyForPlatform(int $platformCompanyId): ?WorkforceCompany
    {
        return $this->projectCompany($this->findCompany((string) $platformCompanyId));
    }

    public function company(string $companyStableId): ?WorkforceCompany
    {
        return $this->projectCompany($this->findCompany($companyStableId));
    }

    public function employees(string $companyStableId): array
    {
        $company = $this->findCompany($companyStableId);

        if ($company === null) {
            return [];
        }

        return $this->projectEmployees($this->employeeQuery((int) $company->getKey())->get());
    }

    public function employeeForUser(string $companyStableId, int $platformUserId): ?WorkforceEmployee
    {
        $company = $this->findCompany($companyStableId);

        if ($company === null) {
            return null;
        }

        $employee = $this->employeeQuery((int) $company->getKey())
            ->whereIn('id', EmployeePortalAccess::query()
                ->where('user_id', $platformUserId)
                ->where('status', EmployeePortalAccess::STATUS_ACTIVE)
                ->select('employee_id'))
            ->whereHas('user', fn (Builder $query): Builder => $query
                ->whereKey($platformUserId)
                ->where(static fn (Builder $scope): Builder => $scope
                    ->whereNull('company_id')->orWhere('company_id', $company->getKey())))
            ->first();

        return $employee === null ? null : $this->projectEmployees(new Collection([$employee]))[0];
    }

    public function remap(
        WorkforceResourceType $type,
        string $toStableId,
        string $fromStableId,
    ): ?WorkforceRemapFact {
        // Native IDs are immutable. A provider adapter must publish its own
        // audited remap; absence deliberately forbids carrying a merge over.
        return null;
    }

    private function findCompany(string $stableId): ?Company
    {
        $tenantId = $this->tenantContext->currentTenantId();

        if ($tenantId === null || ! ctype_digit($stableId)) {
            return null;
        }

        return Company::query()->forTenant($tenantId)
            ->where('status', 'active')
            ->find((int) $stableId);
    }

    private function projectCompany(?Company $company): ?WorkforceCompany
    {
        if ($company === null) {
            return null;
        }

        return new WorkforceCompany(
            $this->reference(WorkforceResourceType::Company, (int) $company->getKey()),
            (string) $company->name,
            true,
            $this->time($company->updated_at ?? $company->created_at),
            $company->code,
        );
    }

    /** @return Builder<Employee> */
    private function employeeQuery(int $companyId): Builder
    {
        return Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where(static fn (Builder $query): Builder => $query
                ->whereNull('employee_type')->orWhere('employee_type', '!=', 'agent'))
            ->with(['department', 'user', 'workProfile.organizationUnit', 'workProfile.jobTitle']);
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return list<WorkforceEmployee>
     */
    private function projectEmployees(Collection $employees): array
    {
        return $employees->map(function (Employee $employee): WorkforceEmployee {
            $companyId = (int) $employee->company_id;
            $employeeId = (int) $employee->getKey();
            $portalUserId = EmployeePortalAccess::query()
                ->where('employee_id', $employeeId)
                ->where('status', EmployeePortalAccess::STATUS_ACTIVE)
                ->value('user_id');

            return new WorkforceEmployee(
                reference: $this->reference(WorkforceResourceType::Employee, $employeeId),
                companyReference: $this->reference(WorkforceResourceType::Company, $companyId),
                displayName: $employee->displayName(),
                active: true,
                effectiveAt: $this->time($employee->employment_start ?? $employee->created_at),
                observedAt: $this->time($employee->updated_at ?? $employee->created_at),
                employeeNumber: $employee->employee_number,
                email: $employee->email,
                userReference: is_numeric($portalUserId)
                    && (int) $employee->user?->getKey() === (int) $portalUserId
                        ? $this->reference(WorkforceResourceType::User, (int) $portalUserId)
                        : null,
                organizationReference: $this->relationshipReference(
                    $companyId,
                    $employee->workProfile?->organizationUnit,
                    WorkforceResourceType::OrganizationUnit,
                ),
                positionReference: $this->relationshipReference(
                    $companyId,
                    $employee->workProfile?->jobTitle,
                    WorkforceResourceType::Position,
                ),
                managerReference: $this->relatedEmployeeReference($employee->supervisor_id, $companyId),
                departmentHeadReference: $this->relatedEmployeeReference($employee->department?->head_id, $companyId),
            );
        })->all();
    }

    private function relationshipReference(
        int $companyId,
        ?PeopleReferenceEntry $entry,
        WorkforceResourceType $type,
    ): ?ExternalReference {
        $expectedType = $type === WorkforceResourceType::Position
            ? PeopleReferenceEntry::TYPE_JOB_TITLE
            : PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT;

        if ($entry === null
            || (int) $entry->company_id !== $companyId
            || $entry->type !== $expectedType
            || $entry->status !== PeopleReferenceEntry::STATUS_ACTIVE) {
            return null;
        }

        return $this->reference($type, (int) $entry->getKey());
    }

    private function relatedEmployeeReference(mixed $employeeId, int $companyId): ?ExternalReference
    {
        if (! is_numeric($employeeId) || ! $this->employeeQuery($companyId)->whereKey((int) $employeeId)->exists()) {
            return null;
        }

        return $this->reference(WorkforceResourceType::Employee, (int) $employeeId);
    }

    private function reference(WorkforceResourceType $type, int $id): ExternalReference
    {
        return new ExternalReference($type, (string) $id);
    }

    private function time(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
        }

        return new DateTimeImmutable(is_string($value) ? $value : 'now', new DateTimeZone('UTC'));
    }
}
