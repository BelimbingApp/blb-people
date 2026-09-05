<?php

namespace App\Domains\People\Organisation\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Organisation\Contracts\ReadsOrganisationExplorer;
use App\Domains\People\Organisation\Data\OrganisationAggregate;
use App\Domains\People\Organisation\Data\OrganisationDrillThrough;
use App\Domains\People\Organisation\Data\OrganisationNode;
use App\Domains\People\Organisation\Enums\OrganisationIndicator;
use App\Domains\People\Organisation\Enums\OrganisationPurpose;
use App\Domains\People\Organisation\Enums\OrganisationReadRefusal;
use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;
use App\Domains\People\Provider\Data\WorkforceCompany;
use App\Domains\People\Provider\Data\WorkforceEmployee;
use App\Domains\People\Provider\Data\WorkforceOrganizationUnit;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class NativeOrganisationExplorer implements ReadsOrganisationExplorer
{
    private const AUDIENCES = ['executive', 'hod', 'employee', 'hr', 'auditor'];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuthorizationService $authorization,
        private readonly ReadsWorkforceDirectory $workforce,
    ) {}

    public function structureNode(
        Actor $actor,
        WorkforceSubject $subject,
        DateTimeInterface $asOf,
    ): OrganisationNode|OrganisationReadRefusal {
        return $this->readNode($actor, $subject, $asOf, 'people.organisation.structure.view');
    }

    public function aggregateIndicator(
        Actor $actor,
        WorkforceSubject $scope,
        OrganisationIndicator $indicator,
        DateTimeInterface $asOf,
    ): OrganisationAggregate|OrganisationReadRefusal {
        $guard = $this->guard($actor, $scope, $asOf, 'people.organisation.aggregate.view');

        if ($guard instanceof OrganisationReadRefusal) {
            return $guard;
        }

        $employees = $this->workforce->employees($guard->reference->externalId);
        $visible = match ($scope->type) {
            WorkforceResourceType::Company => $employees,
            WorkforceResourceType::OrganizationUnit => array_values(array_filter(
                $employees,
                fn (WorkforceEmployee $employee): bool => $employee->organizationReference?->externalId === $scope->stableId,
            )),
            WorkforceResourceType::Employee => array_values(array_filter(
                $employees,
                fn (WorkforceEmployee $employee): bool => $employee->reference->externalId === $scope->stableId,
            )),
            default => null,
        };

        if ($visible === null) {
            return OrganisationReadRefusal::UnsupportedSubject;
        }

        $node = $this->projectNode($scope, $guard, $asOf);

        if ($node instanceof OrganisationReadRefusal) {
            return $node;
        }

        return new OrganisationAggregate(
            scope: $scope,
            indicator: $indicator,
            value: count($visible),
            asOf: $this->immutable($asOf),
        );
    }

    public function drillThrough(
        Actor $actor,
        OrganisationNode $node,
        OrganisationPurpose $purpose,
    ): OrganisationDrillThrough|OrganisationReadRefusal {
        $capability = $purpose === OrganisationPurpose::Structure
            ? 'people.organisation.structure.view'
            : 'people.organisation.detail.view';
        $guard = $this->guard($actor, $node->subject, $node->asOf, $capability);

        if ($guard instanceof OrganisationReadRefusal) {
            return $guard;
        }

        $source = $this->projectNode($node->subject, $guard, $node->asOf);

        if ($source instanceof OrganisationReadRefusal) {
            return $source;
        }

        $subjects = $purpose === OrganisationPurpose::Structure
            ? $this->structureChildren($source->subject, $guard)
            : $this->detailChildren($source->subject, $guard);
        $nodes = [];

        foreach ($subjects as $subject) {
            $childGuard = $this->guard($actor, $subject, $node->asOf, $capability);

            if ($childGuard instanceof OrganisationReadRefusal) {
                continue;
            }

            $child = $this->projectNode($subject, $guard, $node->asOf);

            if ($child instanceof OrganisationNode) {
                $nodes[] = $child;
            }
        }

        return new OrganisationDrillThrough($source, $purpose, $nodes);
    }

    private function readNode(
        Actor $actor,
        WorkforceSubject $subject,
        DateTimeInterface $asOf,
        string $capability,
    ): OrganisationNode|OrganisationReadRefusal {
        $guard = $this->guard($actor, $subject, $asOf, $capability);

        return $guard instanceof OrganisationReadRefusal
            ? $guard
            : $this->projectNode($subject, $guard, $asOf);
    }

    private function guard(
        Actor $actor,
        WorkforceSubject $subject,
        DateTimeInterface $asOf,
        string $capability,
    ): WorkforceCompany|OrganisationReadRefusal {
        $tenantId = $this->tenantContext->currentTenantId();

        if ($tenantId === null) {
            return OrganisationReadRefusal::MissingTenant;
        }

        if ($subject->tenantId !== $tenantId || $actor->tenantId !== $tenantId) {
            return OrganisationReadRefusal::WrongTenant;
        }

        if (! $this->isCurrent($asOf)) {
            return OrganisationReadRefusal::HistoricalReadUnavailable;
        }

        if (! $this->explicitlyHas($actor, $capability)) {
            return OrganisationReadRefusal::MissingCapability;
        }

        if ($actor->companyId === null || $subject->companyId === null) {
            return OrganisationReadRefusal::WrongCompany;
        }

        $actorCompany = $this->workforce->companyForPlatform($actor->companyId);
        $subjectCompany = $this->workforce->companyForPlatform($subject->companyId);

        if ($actorCompany === null
            || $subjectCompany === null
            || $actorCompany->reference != $subjectCompany->reference) {
            return OrganisationReadRefusal::WrongCompany;
        }

        $audiences = array_values(array_filter(
            self::AUDIENCES,
            fn (string $audience): bool => $this->explicitlyHas(
                $actor,
                'people.organisation.audience.'.$audience,
            ),
        ));

        if ($this->allowedByAudience($actor, $subject, $subjectCompany, $audiences)) {
            return $subjectCompany;
        }

        return in_array('auditor', $audiences, true)
            ? OrganisationReadRefusal::AudienceScopeUnavailable
            : OrganisationReadRefusal::OutsideAudienceScope;
    }

    /** @param list<string> $audiences */
    private function allowedByAudience(
        Actor $actor,
        WorkforceSubject $subject,
        WorkforceCompany $company,
        array $audiences,
    ): bool {
        if (array_intersect($audiences, ['executive', 'hr']) !== []) {
            return true;
        }

        $employee = $this->workforce->employeeForUser($company->reference->externalId, $actor->id);

        if ($employee === null) {
            return false;
        }

        if (in_array('employee', $audiences, true) && $this->isOwnSubject($subject, $employee)) {
            return true;
        }

        return in_array('hod', $audiences, true)
            && $this->isInManagedDepartment($subject, $employee, $company);
    }

    private function isOwnSubject(WorkforceSubject $subject, WorkforceEmployee $employee): bool
    {
        return ($subject->type === WorkforceResourceType::Employee
                && $subject->stableId === $employee->reference->externalId)
            || ($subject->type === WorkforceResourceType::OrganizationUnit
                && $subject->stableId === $employee->organizationReference?->externalId);
    }

    private function isInManagedDepartment(
        WorkforceSubject $subject,
        WorkforceEmployee $actorEmployee,
        WorkforceCompany $company,
    ): bool {
        $managedUnitIds = [];

        foreach ($this->workforce->employees($company->reference->externalId) as $employee) {
            if ($employee->departmentHeadReference?->externalId === $actorEmployee->reference->externalId
                && $employee->organizationReference !== null) {
                $managedUnitIds[] = $employee->organizationReference->externalId;
            }
        }

        if ($subject->type === WorkforceResourceType::OrganizationUnit) {
            return in_array($subject->stableId, $managedUnitIds, true);
        }

        if ($subject->type !== WorkforceResourceType::Employee) {
            return false;
        }

        foreach ($this->workforce->employees($company->reference->externalId) as $employee) {
            if ($employee->reference->externalId === $subject->stableId) {
                return $employee->organizationReference !== null
                    && in_array($employee->organizationReference->externalId, $managedUnitIds, true);
            }
        }

        return false;
    }

    private function projectNode(
        WorkforceSubject $subject,
        WorkforceCompany $company,
        DateTimeInterface $asOf,
    ): OrganisationNode|OrganisationReadRefusal {
        $projection = match ($subject->type) {
            WorkforceResourceType::Company => $subject->stableId === $company->reference->externalId ? $company : null,
            WorkforceResourceType::OrganizationUnit => $this->findUnit($company, $subject->stableId),
            WorkforceResourceType::Employee => $this->findEmployee($company, $subject->stableId),
            default => false,
        };

        if ($projection === false) {
            return OrganisationReadRefusal::UnsupportedSubject;
        }

        if ($projection === null) {
            return OrganisationReadRefusal::UnknownSubject;
        }

        return new OrganisationNode(
            subject: $subject,
            label: $projection instanceof WorkforceEmployee ? $projection->displayName : $projection->name,
            active: $projection->active,
            asOf: $this->immutable($asOf),
            observedAt: $projection->observedAt,
        );
    }

    /** @return list<WorkforceSubject> */
    private function structureChildren(WorkforceSubject $source, WorkforceCompany $company): array
    {
        if ($source->type !== WorkforceResourceType::Company) {
            return [];
        }

        return array_map(
            fn (WorkforceOrganizationUnit $unit): WorkforceSubject => $this->subject(
                $source,
                WorkforceResourceType::OrganizationUnit,
                $unit->reference->externalId,
            ),
            $this->workforce->organizationUnits($company->reference->externalId),
        );
    }

    /** @return list<WorkforceSubject> */
    private function detailChildren(WorkforceSubject $source, WorkforceCompany $company): array
    {
        if (! in_array($source->type, [WorkforceResourceType::Company, WorkforceResourceType::OrganizationUnit], true)) {
            return [];
        }

        return array_values(array_map(
            fn (WorkforceEmployee $employee): WorkforceSubject => $this->subject(
                $source,
                WorkforceResourceType::Employee,
                $employee->reference->externalId,
            ),
            array_filter(
                $this->workforce->employees($company->reference->externalId),
                fn (WorkforceEmployee $employee): bool => $source->type === WorkforceResourceType::Company
                    || $employee->organizationReference?->externalId === $source->stableId,
            ),
        ));
    }

    private function findUnit(WorkforceCompany $company, string $stableId): ?WorkforceOrganizationUnit
    {
        foreach ($this->workforce->organizationUnits($company->reference->externalId) as $unit) {
            if ($unit->reference->externalId === $stableId
                && $unit->companyReference == $company->reference) {
                return $unit;
            }
        }

        return null;
    }

    private function findEmployee(WorkforceCompany $company, string $stableId): ?WorkforceEmployee
    {
        foreach ($this->workforce->employees($company->reference->externalId) as $employee) {
            if ($employee->reference->externalId === $stableId
                && $employee->companyReference == $company->reference) {
                return $employee;
            }
        }

        return null;
    }

    private function subject(
        WorkforceSubject $parent,
        WorkforceResourceType $type,
        string $stableId,
    ): WorkforceSubject {
        return new WorkforceSubject($parent->tenantId, $parent->companyId, $type, $stableId);
    }

    private function explicitlyHas(Actor $actor, string $capability): bool
    {
        $decision = $this->authorization->can($actor, $capability);

        if (! $decision->allowed) {
            return false;
        }

        if (! in_array('grant_all', $decision->appliedPolicies, true)) {
            return true;
        }

        return PrincipalRole::query()
            ->join('base_authz_roles', 'base_authz_roles.id', '=', 'base_authz_principal_roles.role_id')
            ->join('base_authz_role_capabilities', 'base_authz_role_capabilities.role_id', '=', 'base_authz_roles.id')
            ->where('base_authz_principal_roles.principal_type', PrincipalType::USER->value)
            ->where('base_authz_principal_roles.principal_id', $actor->id)
            ->where(static function ($query) use ($actor): void {
                $query->whereNull('base_authz_principal_roles.company_id')
                    ->orWhere('base_authz_principal_roles.company_id', $actor->companyId);
            })
            ->where('base_authz_role_capabilities.capability_key', $capability)
            ->exists();
    }

    private function isCurrent(DateTimeInterface $asOf): bool
    {
        return $this->immutable($asOf)->format('Y-m-d') === now('UTC')->format('Y-m-d');
    }

    private function immutable(DateTimeInterface $value): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
    }
}
