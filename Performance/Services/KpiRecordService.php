<?php

namespace App\Domains\People\Performance\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Performance\Data\TeamKpiAttribution;
use App\Domains\People\Performance\Enums\KpiDirection;
use App\Domains\People\Performance\Exceptions\KpiRecordException;
use App\Domains\People\Performance\Models\KpiDefinition;
use App\Domains\People\Performance\Models\KpiRecord;
use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;
use App\Domains\People\Provider\Contracts\ResolvesWorkforceSubjects;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Services\CompanyAttribution;
use DateTimeInterface;

final readonly class KpiRecordService
{
    public function __construct(
        private TenantContext $tenantContext,
        private AuthorizationService $authorization,
        private CompanyAttribution $companies,
        private ResolvesWorkforceSubjects $subjects,
        private ReadsWorkforceDirectory $directory,
    ) {}

    public function define(
        User $actor,
        int $companyEntityId,
        WorkforceSubject $steward,
        string $key,
        int $version,
        string $name,
        string $purpose,
        string $unit,
        string $measure,
        string $sourceReference,
        KpiDirection $direction,
        ?string $rubric,
        string $calculationVersion,
        int $precision,
        string $interpretation,
    ): KpiDefinition {
        $tenantId = $this->authorizeCompany($actor, $companyEntityId, 'people.performance.kpi.submit');

        if ($steward->tenantId !== $tenantId || $steward->companyId !== $companyEntityId
            || $this->subjects->resolve($steward)->record === null || ! $this->hodOwns($actor, $companyEntityId, $steward)) {
            throw new KpiRecordException('The KPI steward is outside the proposer’s assigned scope.');
        }

        $required = [$key, $name, $purpose, $unit, $measure, $sourceReference, $calculationVersion, $interpretation];
        if ($version < 1 || $precision < 0 || $precision > 8 || array_any($required, fn (string $value): bool => trim($value) === '')
            || ($direction === KpiDirection::Rubric && trim((string) $rubric) === '')) {
            throw new KpiRecordException('The KPI definition is incomplete or invalid.');
        }

        return KpiDefinition::query()->create([
            'tenant_id' => $tenantId,
            'company_entity_id' => $companyEntityId,
            'kpi_key' => trim($key),
            'version' => $version,
            'name' => trim($name),
            'purpose' => trim($purpose),
            'steward_subject_type' => $steward->type->value,
            'steward_subject_id' => $steward->stableId,
            'unit' => trim($unit),
            'measure' => trim($measure),
            'source_reference' => trim($sourceReference),
            'direction' => $direction,
            'rubric' => $rubric === null ? null : trim($rubric),
            'calculation_version' => trim($calculationVersion),
            'precision' => $precision,
            'interpretation' => trim($interpretation),
        ]);
    }

    /** @param list<string> $evidenceReferences */
    public function propose(
        User $actor,
        int $companyEntityId,
        WorkforceSubject $owner,
        int $definitionId,
        string $target,
        DateTimeInterface $periodStart,
        DateTimeInterface $periodEnd,
        array $evidenceReferences = [],
        bool $confidential = false,
        ?TeamKpiAttribution $attribution = null,
    ): KpiRecord {
        $tenantId = $this->authorizeCompany($actor, $companyEntityId, 'people.performance.kpi.submit');
        $definition = KpiDefinition::query()->forCompany($tenantId, $companyEntityId)->find($definitionId)
            ?? throw new KpiRecordException('The KPI definition was not found in this company.');

        if ($owner->tenantId !== $tenantId
            || $owner->companyId !== $companyEntityId
            || ! in_array($owner->type, [WorkforceResourceType::Employee, WorkforceResourceType::OrganizationUnit], true)
            || $this->subjects->resolve($owner)->record === null
            || ! $this->hodOwns($actor, $companyEntityId, $owner)) {
            throw new KpiRecordException('The KPI owner is outside the proposer’s assigned scope.');
        }

        if (trim($target) === '') {
            throw new KpiRecordException('A KPI target is required.');
        }

        if ($periodStart > $periodEnd || array_filter($evidenceReferences, fn (mixed $value): bool => ! is_string($value) || trim($value) === '') !== []) {
            throw new KpiRecordException('KPI period and evidence references must be valid.');
        }

        $attribution ??= TeamKpiAttribution::notAttributed();
        if ($owner->type === WorkforceResourceType::Employee && $attribution->employeeSubjectIds !== []) {
            throw new KpiRecordException('An individual KPI cannot carry team attribution.');
        }

        return KpiRecord::query()->create([
            'tenant_id' => $tenantId,
            'company_entity_id' => $companyEntityId,
            'kpi_definition_id' => $definition->id,
            'kpi_definition_version' => $definition->version,
            'owner_subject_type' => $owner->type->value,
            'owner_subject_id' => $owner->stableId,
            'target' => trim($target),
            'attributed_employee_subject_ids' => $attribution->employeeSubjectIds,
            'target_version' => 1,
            'effective_from' => $periodStart,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'evidence_references' => array_values($evidenceReferences),
            'confidential' => $confidential,
            'status' => KpiRecord::PROPOSED,
            'proposed_by_user_id' => $actor->getAuthIdentifier(),
        ]);
    }

    public function amendTarget(
        User $actor,
        int $companyEntityId,
        int $recordId,
        string $target,
        DateTimeInterface $effectiveFrom,
        string $reason,
    ): KpiRecord {
        $tenantId = $this->authorizeCompany($actor, $companyEntityId, 'people.performance.kpi.submit');
        $current = $this->record($tenantId, $companyEntityId, $recordId);

        if (! in_array($current->status, [KpiRecord::REVIEWED, KpiRecord::PUBLISHED], true)
            || trim($target) === '' || trim($reason) === ''
            || $effectiveFrom < $current->period_start || $effectiveFrom > $current->period_end) {
            throw new KpiRecordException('A governed target amendment requires an approved target, reason, and in-period effective date.');
        }

        return KpiRecord::query()->create([
            'tenant_id' => $tenantId,
            'company_entity_id' => $companyEntityId,
            'kpi_definition_id' => $current->kpi_definition_id,
            'kpi_definition_version' => $current->kpi_definition_version,
            'owner_subject_type' => $current->owner_subject_type,
            'owner_subject_id' => $current->owner_subject_id,
            'target' => trim($target),
            'attributed_employee_subject_ids' => $current->attributed_employee_subject_ids,
            'target_version' => $current->target_version + 1,
            'supersedes_assignment_id' => $current->id,
            'amendment_reason' => trim($reason),
            'effective_from' => $effectiveFrom,
            'period_start' => $current->period_start,
            'period_end' => $current->period_end,
            'evidence_references' => [],
            'confidential' => $current->confidential,
            'status' => KpiRecord::PROPOSED,
            'proposed_by_user_id' => $actor->getAuthIdentifier(),
        ]);
    }

    public function review(User $actor, int $companyEntityId, int $recordId, string $outcome): KpiRecord
    {
        $tenantId = $this->authorizeCompany($actor, $companyEntityId, 'people.performance.kpi.review');
        $record = $this->record($tenantId, $companyEntityId, $recordId);

        if ($record->status !== KpiRecord::PROPOSED || trim($outcome) === '') {
            throw new KpiRecordException('Only a proposed KPI can receive a non-empty review outcome.');
        }

        $record->forceFill([
            'review_outcome' => trim($outcome),
            'status' => KpiRecord::REVIEWED,
            'reviewed_by_user_id' => $actor->getAuthIdentifier(),
            'reviewed_at' => now(),
        ])->save();

        return $record->refresh();
    }

    public function publishToEmployee(User $actor, int $companyEntityId, int $recordId): KpiRecord
    {
        $tenantId = $this->authorizeCompany($actor, $companyEntityId, 'people.performance.kpi.approve');
        $record = $this->record($tenantId, $companyEntityId, $recordId);

        if ($record->status !== KpiRecord::REVIEWED || $record->confidential) {
            throw new KpiRecordException('Only a reviewed, non-confidential KPI can be published to an employee.');
        }

        $record->forceFill([
            'status' => KpiRecord::PUBLISHED,
            'published_by_user_id' => $actor->getAuthIdentifier(),
            'published_at' => now(),
        ])->save();

        return $record->refresh();
    }

    public function readForEmployee(User $actor, int $companyEntityId, int $recordId): KpiRecord
    {
        $tenantId = $this->authorizeCompany($actor, $companyEntityId, 'people.performance.kpi.view');
        $record = $this->record($tenantId, $companyEntityId, $recordId);
        $company = $this->directory->companyForPlatform($companyEntityId);
        $employee = $company === null ? null : $this->directory->employeeForUser(
            $company->reference->externalId,
            (int) $actor->getAuthIdentifier(),
        );

        if ($record->status !== KpiRecord::PUBLISHED
            || $record->confidential
            || $record->owner_subject_type !== WorkforceResourceType::Employee->value
            || $employee?->reference->externalId !== $record->owner_subject_id) {
            throw new KpiRecordException('This KPI is not published for the employee.');
        }

        return $record;
    }

    private function authorizeCompany(User $actor, int $companyEntityId, string $capability): int
    {
        $tenantId = $this->tenantContext->currentTenantId();

        if ($tenantId === null) {
            throw new KpiRecordException('A tenant context is required for KPI records.');
        }

        $this->authorization->authorize(Actor::forUser($actor), $capability);

        if (! $this->companies->mayActFor($actor, $companyEntityId)) {
            throw new KpiRecordException('The actor may not access KPI records for this company.');
        }

        return $tenantId;
    }

    private function record(int $tenantId, int $companyEntityId, int $recordId): KpiRecord
    {
        return KpiRecord::query()->forCompany($tenantId, $companyEntityId)->find($recordId)
            ?? throw new KpiRecordException('The KPI record was not found in this company.');
    }

    private function hodOwns(User $actor, int $companyEntityId, WorkforceSubject $owner): bool
    {
        $company = $this->directory->companyForPlatform($companyEntityId);
        $hod = $company === null ? null : $this->directory->employeeForUser(
            $company->reference->externalId,
            (int) $actor->getAuthIdentifier(),
        );

        if ($company === null || $hod === null) {
            return false;
        }

        foreach ($this->directory->employees($company->reference->externalId) as $employee) {
            if ($employee->departmentHeadReference?->externalId !== $hod->reference->externalId) {
                continue;
            }

            if (($owner->type === WorkforceResourceType::Employee && $owner->stableId === $employee->reference->externalId)
                || ($owner->type === WorkforceResourceType::OrganizationUnit && $owner->stableId === $employee->organizationReference?->externalId)) {
                return true;
            }
        }

        return false;
    }
}
