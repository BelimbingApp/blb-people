<?php

namespace App\Domains\People\Performance\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Domains\People\Organisation\Contracts\ContributesOrganisationRecordDetail;
use App\Domains\People\Organisation\Data\OrganisationDetailSection;
use App\Domains\People\Organisation\Data\OrganisationRecordDetail;
use App\Domains\People\Organisation\Enums\OrganisationReadRefusal;
use App\Domains\People\Performance\Enums\JobDescriptionStatus;
use App\Domains\People\Performance\Models\JobDescription;
use App\Domains\People\Performance\Models\KpiDefinition;
use App\Domains\People\Performance\Models\KpiRecord;
use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use DateTimeImmutable;
use DateTimeInterface;

final readonly class OrganisationPerformanceDetail implements ContributesOrganisationRecordDetail
{
    public function __construct(
        private AuthorizationService $authorization,
        private ReadsWorkforceDirectory $workforce,
    ) {}

    public function detail(Actor $actor, WorkforceSubject $subject, DateTimeInterface $asOf): OrganisationRecordDetail
    {
        $companyId = $subject->companyId;
        $boundary = $companyId === null || $actor->tenantId !== $subject->tenantId || $actor->companyId !== $companyId
            ? OrganisationReadRefusal::WrongCompany
            : null;

        return new OrganisationRecordDetail(
            subject: $subject,
            asOf: DateTimeImmutable::createFromInterface($asOf),
            jobDescription: $boundary === null
                ? $this->jobDescription($actor, $subject, $companyId, $asOf)
                : OrganisationDetailSection::restricted($boundary),
            performance: $boundary === null
                ? $this->performance($actor, $subject, $companyId)
                : OrganisationDetailSection::restricted($boundary),
        );
    }

    private function jobDescription(
        Actor $actor,
        WorkforceSubject $subject,
        int $companyId,
        DateTimeInterface $asOf,
    ): OrganisationDetailSection {
        if (! $this->explicitlyHas($actor, 'people.performance.job-description.view')) {
            return OrganisationDetailSection::restricted(OrganisationReadRefusal::MissingCapability);
        }

        $positionId = $this->positionId($subject, $companyId);
        if ($positionId === null) {
            return OrganisationDetailSection::available([]);
        }

        $date = $asOf->format('Y-m-d');
        $description = JobDescription::query()->forCompany($subject->tenantId, $companyId)
            ->where('position_stable_id', $positionId)
            ->whereIn('status', [JobDescriptionStatus::Published, JobDescriptionStatus::Superseded])
            ->whereDate('effective_from', '<=', $date)
            ->whereRaw('(effective_to is null or effective_to >= ?)', [$date])
            ->orderByDesc('position_version')->orderByDesc('version')->first();

        return OrganisationDetailSection::available($description === null ? [] : [[
            'id' => (int) $description->id,
            'reference' => (string) $description->reference,
            'version' => (int) $description->version,
            'position_version' => (int) $description->position_version,
            'status' => $description->status->value,
            'effective_from' => $description->effective_from->toDateString(),
            'effective_to' => $description->effective_to?->toDateString(),
            'purpose' => (string) $description->purpose,
            'responsibilities' => $description->responsibilities,
            'duties' => $description->duties,
            'authority' => (string) $description->authority,
            'qualifications' => $description->qualifications,
            'competency_links' => $description->competency_links,
        ]]);
    }

    private function performance(Actor $actor, WorkforceSubject $subject, int $companyId): OrganisationDetailSection
    {
        if (! $this->explicitlyHas($actor, 'people.performance.kpi.view')) {
            return OrganisationDetailSection::restricted(OrganisationReadRefusal::MissingCapability);
        }

        if ($subject->type === WorkforceResourceType::Position) {
            $approvedDefinitionIds = KpiRecord::query()->forCompany($subject->tenantId, $companyId)
                ->whereIn('status', [KpiRecord::REVIEWED, KpiRecord::PUBLISHED])
                ->pluck('kpi_definition_id');

            $records = KpiDefinition::query()->forCompany($subject->tenantId, $companyId)
                ->where('steward_subject_type', WorkforceResourceType::Position->value)
                ->where('steward_subject_id', $subject->stableId)
                ->whereIn('id', $approvedDefinitionIds->all())
                ->orderBy('name')->get()
                ->map(fn (KpiDefinition $definition): array => [
                    'id' => (int) $definition->id,
                    'key' => (string) $definition->kpi_key,
                    'version' => (int) $definition->version,
                    'name' => (string) $definition->name,
                    'purpose' => (string) $definition->purpose,
                    'unit' => (string) $definition->unit,
                    'measure' => (string) $definition->measure,
                    'direction' => $definition->direction->value,
                    'calculation_version' => (string) $definition->calculation_version,
                ])->all();

            return OrganisationDetailSection::available($records);
        }

        if ($subject->type !== WorkforceResourceType::Employee) {
            return OrganisationDetailSection::available([]);
        }

        $mayReadEvidence = $this->explicitlyHas($actor, 'people.performance.kpi.evidence.view');
        $records = KpiRecord::query()->forCompany($subject->tenantId, $companyId)
            ->where('owner_subject_type', WorkforceResourceType::Employee->value)
            ->where('owner_subject_id', $subject->stableId)
            ->where('status', KpiRecord::PUBLISHED)
            ->where('confidential', false)
            ->orderByDesc('period_start')->get()
            ->map(fn (KpiRecord $record): array => [
                'id' => (int) $record->id,
                'definition_id' => (int) $record->kpi_definition_id,
                'definition_version' => (int) $record->kpi_definition_version,
                'target' => (string) $record->target,
                'target_version' => (int) $record->target_version,
                'period_start' => $record->period_start->toDateString(),
                'period_end' => $record->period_end->toDateString(),
                'status' => $record->status,
                'released_outcome' => $record->review_outcome,
                'evidence_references' => $mayReadEvidence ? $record->evidence_references : [],
                'evidence_refusal' => $mayReadEvidence ? null : OrganisationReadRefusal::MissingCapability->value,
            ])->all();

        return OrganisationDetailSection::available($records);
    }

    private function positionId(WorkforceSubject $subject, int $companyId): ?string
    {
        if ($subject->type === WorkforceResourceType::Position) {
            return $subject->stableId;
        }

        if ($subject->type !== WorkforceResourceType::Employee) {
            return null;
        }

        foreach ($this->workforce->employees((string) $companyId) as $employee) {
            if ($employee->reference->externalId === $subject->stableId) {
                return $employee->positionReference?->externalId;
            }
        }

        return null;
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
            ->where('base_authz_role_capabilities.capability_key', $capability)
            ->exists();
    }
}
