<?php

namespace App\Domains\People\Provider\Services;

use App\Core\Company\Models\Company;
use App\Core\Company\Models\Department;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceCompany;
use App\Domains\People\Provider\Data\WorkforceDeactivation;
use App\Domains\People\Provider\Data\WorkforceEmployee;
use App\Domains\People\Provider\Data\WorkforceOrganizationUnit;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Provider\Exceptions\WorkforceProjectionException;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Projects native Company, Department and Employee rows into the provider
 * vocabulary. Shared by the bootstrap and incremental readers so both emit
 * byte-identical records for the same row; the readers own only paging and
 * the choice of which rows.
 *
 * Every rule about what may be projected lives here: human employees only,
 * related identities only when they stay inside the tenant and the company,
 * a user link only when HR has confirmed it, and a revoked portal access as
 * a positive statement that the link is gone.
 */
final class WorkforceRecordProjector
{
    /** @return list<WorkforceCompany> */
    public function companies(int $tenantId, DateTimeImmutable $fallback, ?DateTimeImmutable $since = null): array
    {
        return Company::query()
            ->forTenant($tenantId)
            ->when($since !== null, static fn (Builder $query): Builder => $query->where('updated_at', '>=', $since))
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'status', 'created_at', 'updated_at'])
            ->map(function (Company $company) use ($fallback): WorkforceCompany {
                $observedAt = $this->observedAt($company->updated_at, $company->created_at, $fallback);

                return new WorkforceCompany(
                    reference: $this->reference(WorkforceResourceType::Company, (int) $company->getKey()),
                    name: (string) $company->name,
                    active: $company->status === 'active',
                    observedAt: $observedAt,
                    code: $company->code,
                    sourceVersion: $this->sourceVersion($observedAt),
                );
            })
            ->all();
    }

    /**
     * Companies soft-deleted at or after $since. A soft-deleted company is not
     * "a company with active = false": its row is gone from every ordinary
     * read, so the connector is told the reference stopped existing.
     *
     * @return list<WorkforceDeactivation>
     */
    public function deletedCompanies(int $tenantId, DateTimeImmutable $since): array
    {
        return Company::onlyTrashed()
            ->forTenant($tenantId)
            ->where('deleted_at', '>=', $since)
            ->orderBy('id')
            ->get(['id', 'deleted_at'])
            ->map(fn (Company $company): WorkforceDeactivation => new WorkforceDeactivation(
                $this->reference(WorkforceResourceType::Company, (int) $company->getKey()),
                $this->observedAt($company->deleted_at, null, $since),
            ))
            ->all();
    }

    /** @return list<WorkforceOrganizationUnit> */
    public function organizationUnits(int $tenantId, DateTimeImmutable $fallback, ?DateTimeImmutable $since = null): array
    {
        return Department::query()
            ->whereHas('company', static fn (Builder $query): Builder => $query->forTenant($tenantId))
            ->when($since !== null, static fn (Builder $query): Builder => $query->where('updated_at', '>=', $since))
            ->with('type:id,code,name,category')
            ->orderBy('id')
            ->get(['id', 'company_id', 'department_type_id', 'status', 'created_at', 'updated_at'])
            ->map(function (Department $department) use ($fallback): WorkforceOrganizationUnit {
                $name = trim((string) $department->type?->name);

                if ($name === '') {
                    throw WorkforceProjectionException::organizationUnitWithoutName((int) $department->getKey());
                }

                $effectiveAt = $this->observedAt($department->created_at, null, $fallback);
                $observedAt = $this->observedAt($department->updated_at, $department->created_at, $fallback);

                return new WorkforceOrganizationUnit(
                    reference: $this->reference(WorkforceResourceType::OrganizationUnit, (int) $department->getKey()),
                    companyReference: $this->reference(WorkforceResourceType::Company, (int) $department->company_id),
                    name: $name,
                    active: $department->status === 'active',
                    effectiveAt: $effectiveAt,
                    observedAt: $observedAt,
                    code: $department->type?->code,
                    kind: $department->type?->category,
                    sourceVersion: $this->sourceVersion($observedAt),
                );
            })
            ->all();
    }

    /**
     * Every department in the tenant, as department ID => company ID. The
     * employee projector needs the whole map, not only the units on a page,
     * to decide whether an organization reference stays inside the company.
     *
     * @return Collection<int, int>
     */
    public function organizationCompanies(int $tenantId): Collection
    {
        return Department::query()
            ->whereHas('company', static fn (Builder $query): Builder => $query->forTenant($tenantId))
            ->pluck('company_id', 'id')
            ->map(static fn (mixed $companyId): int => (int) $companyId);
    }

    /**
     * Human employees of the tenant in an ID window, oldest ID first, limited.
     * With $since, only those whose own row, department row or portal-access
     * row changed at or after it: a department-head change or an HR
     * confirmation does not touch employees.updated_at, but it changes what
     * the employee projects to.
     *
     * @return EloquentCollection<int, Employee>
     */
    public function employeeRows(
        int $tenantId,
        int $afterEmployeeId,
        int $throughEmployeeId,
        int $limit,
        ?DateTimeImmutable $since = null,
    ): EloquentCollection {
        return $this->humanEmployees()
            ->whereHas('company', static fn (Builder $query): Builder => $query->forTenant($tenantId))
            ->where('id', '>', $afterEmployeeId)
            ->where('id', '<=', $throughEmployeeId)
            ->when($since !== null, static function (Builder $query) use ($since): void {
                $query->where(static function (Builder $changed) use ($since): void {
                    $changed
                        ->where('updated_at', '>=', $since)
                        ->orWhereHas('department', static fn (Builder $department): Builder => $department->where('updated_at', '>=', $since))
                        ->orWhereIn('id', EmployeePortalAccess::query()->where('updated_at', '>=', $since)->select('employee_id'));
                });
            })
            ->with([
                'department:id,company_id,department_type_id,head_id',
                'user:id,company_id,employee_id',
            ])
            ->orderBy('id')
            ->limit($limit)
            ->get([
                'id',
                'company_id',
                'department_id',
                'supervisor_id',
                'employee_number',
                'full_name',
                'short_name',
                'employee_type',
                'email',
                'status',
                'employment_start',
                'employment_end',
                'created_at',
                'updated_at',
            ]);
    }

    public function employeeWatermark(int $tenantId): int
    {
        return (int) ($this->humanEmployees()
            ->whereHas('company', static fn (Builder $query): Builder => $query->forTenant($tenantId))
            ->max('id') ?? 0);
    }

    /**
     * @param  EloquentCollection<int, Employee>  $rows
     * @param  Collection<int, int>  $organizationCompanies
     * @return list<WorkforceEmployee>
     */
    public function projectEmployees(int $tenantId, EloquentCollection $rows, DateTimeImmutable $fallback, Collection $organizationCompanies): array
    {
        $relatedEmployeeCompanies = $this->relatedEmployeeCompanies($tenantId, $rows);
        $confirmedPortalUserIds = $this->confirmedPortalUserIds($rows);
        $revokedPortalAccessEmployeeIds = $this->revokedPortalAccessEmployeeIds($rows);

        return $rows
            ->map(fn (Employee $employee): WorkforceEmployee => $this->projectEmployee(
                $employee,
                $fallback,
                $organizationCompanies,
                $relatedEmployeeCompanies,
                $confirmedPortalUserIds,
                $revokedPortalAccessEmployeeIds,
            ))
            ->all();
    }

    public function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface(now())->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Resolve only related identities that remain inside the current tenant.
     *
     * @param  EloquentCollection<int, Employee>  $employees
     * @return Collection<int, int> employee ID => company ID
     */
    private function relatedEmployeeCompanies(int $tenantId, EloquentCollection $employees): Collection
    {
        $ids = $employees
            ->flatMap(static fn (Employee $employee): array => [
                $employee->supervisor_id,
                $employee->department?->head_id,
            ])
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return $this->humanEmployees()
            ->whereKey($ids->all())
            ->whereHas('company', static fn (Builder $query): Builder => $query->forTenant($tenantId))
            ->pluck('company_id', 'id')
            ->map(static fn (mixed $companyId): int => (int) $companyId);
    }

    /**
     * The employee-to-platform-user link is an HR identity assertion, not a
     * platform account setting: see docs/contracts/hr-data-boundary.md rule
     * 8.3. `users.employee_id` alone is gated only by Core's generic
     * `admin.user.update`, so it is necessary but not sufficient here. This
     * additionally requires an active `EmployeePortalAccess` record, which is
     * written only by a principal holding the HR-specific
     * `people.employee.manage` permission and carries its own revocation.
     *
     * @param  EloquentCollection<int, Employee>  $employees
     * @return Collection<int, int> employee ID => confirmed user ID
     */
    private function confirmedPortalUserIds(EloquentCollection $employees): Collection
    {
        $employeeIds = $employees->map(static fn (Employee $employee): int => (int) $employee->getKey());

        if ($employeeIds->isEmpty()) {
            return collect();
        }

        return EmployeePortalAccess::query()
            ->whereIn('employee_id', $employeeIds->all())
            ->where('status', EmployeePortalAccess::STATUS_ACTIVE)
            ->whereNotNull('user_id')
            ->pluck('user_id', 'employee_id')
            ->map(static fn (mixed $userId): int => (int) $userId);
    }

    /**
     * An explicitly revoked EmployeePortalAccess row is a positive statement
     * that a previously-confirmed user link is gone, distinct from a link
     * that was simply never confirmed. The connector uses this to clear an
     * already-projected user_entity_id instead of leaving it stale — see
     * WorkforceEmployee::$userReferenceRevoked and rule 9.1.
     *
     * @param  EloquentCollection<int, Employee>  $employees
     * @return Collection<int, true> employee ID => true, for O(1) membership checks
     */
    private function revokedPortalAccessEmployeeIds(EloquentCollection $employees): Collection
    {
        $employeeIds = $employees->map(static fn (Employee $employee): int => (int) $employee->getKey());

        if ($employeeIds->isEmpty()) {
            return collect();
        }

        return EmployeePortalAccess::query()
            ->whereIn('employee_id', $employeeIds->all())
            ->where('status', EmployeePortalAccess::STATUS_REVOKED)
            ->pluck('employee_id')
            ->mapWithKeys(static fn (mixed $employeeId): array => [(int) $employeeId => true]);
    }

    /** @return Builder<Employee> */
    private function humanEmployees(): Builder
    {
        return Employee::query()->where(static function (Builder $query): void {
            $query->whereNull('employee_type')->orWhere('employee_type', '!=', 'agent');
        });
    }

    /**
     * @param  Collection<int, int>  $organizationCompanies
     * @param  Collection<int, int>  $relatedEmployeeCompanies
     */
    private function projectEmployee(
        Employee $employee,
        DateTimeImmutable $fallback,
        Collection $organizationCompanies,
        Collection $relatedEmployeeCompanies,
        Collection $confirmedPortalUserIds,
        Collection $revokedPortalAccessEmployeeIds,
    ): WorkforceEmployee {
        $employeeId = (int) $employee->getKey();
        $companyId = (int) $employee->company_id;
        $observedAt = $this->observedAt($employee->updated_at, $employee->created_at, $fallback);
        $effectiveAt = $this->observedAt($employee->employment_start, $employee->created_at, $observedAt);
        $departmentId = is_numeric($employee->department_id) ? (int) $employee->department_id : null;
        $organizationReference = $departmentId !== null
            && $organizationCompanies->get($departmentId) === $companyId
                ? $this->reference(WorkforceResourceType::OrganizationUnit, $departmentId)
                : null;
        $managerReference = $this->sameCompanyEmployeeReference(
            $employee->supervisor_id,
            $companyId,
            $relatedEmployeeCompanies,
        );
        $departmentHeadReference = $this->sameCompanyEmployeeReference(
            $employee->department?->head_id,
            $companyId,
            $relatedEmployeeCompanies,
        );
        $user = $employee->user;
        $userReference = $user !== null
            && (int) $user->employee_id === $employeeId
            && ($user->company_id === null || (int) $user->company_id === $companyId)
            && $confirmedPortalUserIds->get($employeeId) === (int) $user->getKey()
                ? $this->reference(WorkforceResourceType::User, (int) $user->getKey())
                : null;

        return new WorkforceEmployee(
            reference: $this->reference(WorkforceResourceType::Employee, $employeeId),
            companyReference: $this->reference(WorkforceResourceType::Company, $companyId),
            displayName: $employee->displayName(),
            active: $this->employeeIsActive($employee, $fallback),
            effectiveAt: $effectiveAt,
            observedAt: $observedAt,
            employeeNumber: $employee->employee_number,
            email: $employee->email,
            userReference: $userReference,
            organizationReference: $organizationReference,
            managerReference: $managerReference,
            departmentHeadReference: $departmentHeadReference,
            sourceVersion: $this->sourceVersion($observedAt),
            userReferenceRevoked: $userReference === null && $revokedPortalAccessEmployeeIds->has($employeeId),
        );
    }

    /** @param Collection<int, int> $relatedEmployeeCompanies */
    private function sameCompanyEmployeeReference(
        mixed $employeeId,
        int $companyId,
        Collection $relatedEmployeeCompanies,
    ): ?ExternalReference {
        if (! is_numeric($employeeId) || $relatedEmployeeCompanies->get((int) $employeeId) !== $companyId) {
            return null;
        }

        return $this->reference(WorkforceResourceType::Employee, (int) $employeeId);
    }

    private function employeeIsActive(Employee $employee, DateTimeImmutable $asOf): bool
    {
        if (! in_array($employee->status, ['active', 'probation'], true)) {
            return false;
        }

        if ($employee->employment_end === null) {
            return true;
        }

        $employmentEnd = $this->observedAt($employee->employment_end, null, $asOf);

        return $employmentEnd->format('Y-m-d') >= $asOf->format('Y-m-d');
    }

    private function reference(WorkforceResourceType $type, int $id): ExternalReference
    {
        return new ExternalReference($type, (string) $id);
    }

    private function observedAt(mixed $preferred, mixed $fallback, DateTimeImmutable $default): DateTimeImmutable
    {
        $value = $preferred ?? $fallback;

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
        }

        if (is_string($value) && trim($value) !== '') {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        }

        return $default;
    }

    private function sourceVersion(DateTimeImmutable $observedAt): string
    {
        return $observedAt->format(DateTimeInterface::RFC3339_EXTENDED);
    }
}
