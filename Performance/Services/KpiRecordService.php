<?php

namespace App\Domains\People\Performance\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Performance\Exceptions\KpiRecordException;
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

    /** @param list<string> $evidenceReferences */
    public function propose(
        User $actor,
        int $companyEntityId,
        WorkforceSubject $owner,
        string $kpiKey,
        string $measure,
        string $target,
        DateTimeInterface $periodStart,
        DateTimeInterface $periodEnd,
        array $evidenceReferences = [],
        bool $confidential = false,
    ): KpiRecord {
        $tenantId = $this->authorizeCompany($actor, $companyEntityId, 'people.performance.kpi.submit');

        if ($owner->tenantId !== $tenantId
            || $owner->companyId !== $companyEntityId
            || ! in_array($owner->type, [WorkforceResourceType::Employee, WorkforceResourceType::OrganizationUnit], true)
            || $this->subjects->resolve($owner)->record === null
            || ! $this->hodOwns($actor, $companyEntityId, $owner)) {
            throw new KpiRecordException('The KPI owner is outside the proposer’s assigned scope.');
        }

        if (trim($kpiKey) === '' || trim($measure) === '' || trim($target) === '') {
            throw new KpiRecordException('KPI key, measure, and target are required.');
        }

        if ($periodStart > $periodEnd || array_filter($evidenceReferences, fn (mixed $value): bool => ! is_string($value) || trim($value) === '') !== []) {
            throw new KpiRecordException('KPI period and evidence references must be valid.');
        }

        return KpiRecord::query()->create([
            'tenant_id' => $tenantId,
            'company_entity_id' => $companyEntityId,
            'kpi_key' => trim($kpiKey),
            'owner_subject_type' => $owner->type->value,
            'owner_subject_id' => $owner->stableId,
            'measure' => trim($measure),
            'target' => trim($target),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'evidence_references' => array_values($evidenceReferences),
            'confidential' => $confidential,
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
