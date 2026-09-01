<?php

namespace App\Domains\People\Provider\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Company\Models\Department;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Provider\Contracts\ReadsWorkforceBootstrap;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceBootstrapCursor;
use App\Domains\People\Provider\Data\WorkforceBootstrapPage;
use App\Domains\People\Provider\Data\WorkforceBootstrapRequest;
use App\Domains\People\Provider\Data\WorkforceCompany;
use App\Domains\People\Provider\Data\WorkforceEmployee;
use App\Domains\People\Provider\Data\WorkforceOrganizationUnit;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Provider\Exceptions\WorkforceProjectionException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

final class NativeWorkforceBootstrapReader implements ReadsWorkforceBootstrap
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly WorkforceBootstrapCursorCodec $cursorCodec,
    ) {}

    public function read(WorkforceBootstrapRequest $request): WorkforceBootstrapPage
    {
        $tenantId = $this->tenantContext->requireTenantId();
        if ($request->pageCursor === null) {
            $startedAt = $this->now();
            $cursor = new WorkforceBootstrapCursor(
                $tenantId,
                0,
                $this->employeeWatermark($tenantId),
                $startedAt,
            );
        } else {
            $cursor = $this->cursorCodec->decodePage($request->pageCursor, $tenantId);
        }

        $firstPage = $cursor->afterEmployeeId === 0;

        $organizationSnapshot = $this->organizationUnits($tenantId, $cursor->startedAt);
        $organizationCompanies = collect($organizationSnapshot)
            ->mapWithKeys(static fn (WorkforceOrganizationUnit $unit): array => [
                (int) $unit->reference->externalId => (int) $unit->companyReference->externalId,
            ]);

        $rows = $this->employeeRows(
            $tenantId,
            $cursor->afterEmployeeId,
            $cursor->throughEmployeeId,
            $request->limit + 1,
        );
        $hasMore = $rows->count() > $request->limit;
        $pageRows = $rows->take($request->limit)->values();
        $relatedEmployeeCompanies = $this->relatedEmployeeCompanies($tenantId, $pageRows);
        $employees = $pageRows
            ->map(fn (Employee $employee): WorkforceEmployee => $this->projectEmployee(
                $employee,
                $cursor->startedAt,
                $organizationCompanies,
                $relatedEmployeeCompanies,
            ))
            ->all();

        $lastEmployeeId = $pageRows->last()?->getKey();
        $nextPageCursor = $hasMore && is_numeric($lastEmployeeId)
            ? $this->cursorCodec->encodePage(new WorkforceBootstrapCursor(
                $tenantId,
                (int) $lastEmployeeId,
                $cursor->throughEmployeeId,
                $cursor->startedAt,
            ))
            : null;

        return new WorkforceBootstrapPage(
            employees: $employees,
            companies: $firstPage ? $this->companies($tenantId, $cursor->startedAt) : [],
            organizationUnits: $firstPage ? $organizationSnapshot : [],
            asOf: $cursor->startedAt,
            nextPageCursor: $nextPageCursor,
            resumeCursor: $hasMore ? null : $this->cursorCodec->encodeResume($tenantId, $cursor->startedAt),
            complete: ! $hasMore,
        );
    }

    /** @return list<WorkforceCompany> */
    private function companies(int $tenantId, DateTimeImmutable $fallback): array
    {
        return Company::query()
            ->forTenant($tenantId)
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

    /** @return list<WorkforceOrganizationUnit> */
    private function organizationUnits(int $tenantId, DateTimeImmutable $fallback): array
    {
        return Department::query()
            ->whereHas('company', static fn (Builder $query): Builder => $query->forTenant($tenantId))
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

    /** @return EloquentCollection<int, Employee> */
    private function employeeRows(
        int $tenantId,
        int $afterEmployeeId,
        int $throughEmployeeId,
        int $limit,
    ): EloquentCollection {
        return $this->humanEmployees()
            ->whereHas('company', static fn (Builder $query): Builder => $query->forTenant($tenantId))
            ->where('id', '>', $afterEmployeeId)
            ->where('id', '<=', $throughEmployeeId)
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

    private function employeeWatermark(int $tenantId): int
    {
        return (int) ($this->humanEmployees()
            ->whereHas('company', static fn (Builder $query): Builder => $query->forTenant($tenantId))
            ->max('id') ?? 0);
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

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface(now())->setTimezone(new DateTimeZone('UTC'));
    }
}
