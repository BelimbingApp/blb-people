<?php

namespace App\Domains\People\Skills\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Data\SkillCertificationDraft;
use App\Domains\People\Skills\Enums\CertificationRenewalStatus;
use App\Domains\People\Skills\Exceptions\InvalidSkillCertificationException;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillCertification;
use App\Domains\People\Skills\Models\SkillCertificationSkill;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Append-only write path for externally issued skill certifications.
 *
 * This store scopes records; SkillAudience is the authorization boundary.
 * Renewal inserts a successor and never edits the prior certificate or its
 * skill mappings, preserving the evidence trail for audit and history.
 */
final class SkillCertificationStore
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly WorkforceSubjects $workforce,
        private readonly SkillAudience $audience,
    ) {}

    /**
     * Record a certification for an employee.
     */
    public function record(User $actor, int $companyEntityId, SkillCertificationDraft $draft): SkillCertification
    {
        $this->audience->authorizeCertificationManagement($actor, $companyEntityId, $draft->employeeEntityId);

        return DB::transaction(fn (): SkillCertification => $this->write(
            $companyEntityId,
            $draft,
        ));
    }

    /**
     * Record a renewal as a new certificate row linked to its predecessor.
     */
    public function renew(
        User $actor,
        int $companyEntityId,
        int $certificationId,
        SkillCertificationDraft $draft,
    ): SkillCertification {
        $this->audience->authorizeCertificationManagement($actor, $companyEntityId, $draft->employeeEntityId);

        return DB::transaction(function () use ($companyEntityId, $certificationId, $draft): SkillCertification {
            $tenantId = $this->tenantContext->requireTenantId();
            $prior = SkillCertification::query()
                ->forCompany($tenantId, $companyEntityId)
                ->whereKey($certificationId)
                ->lockForUpdate()
                ->first()
                ?? throw new InvalidSkillCertificationException("Certification [$certificationId] was not found.");

            if ((int) $prior->employee_entity_id !== $draft->employeeEntityId) {
                throw new InvalidSkillCertificationException('A renewal must keep the same employee.');
            }

            if (SkillCertification::query()
                ->forCompany($tenantId, $companyEntityId)
                ->where('supersedes_certification_id', $prior->getKey())
                ->exists()) {
                throw new InvalidSkillCertificationException(
                    'This certification already has a renewal; renew the latest record instead.',
                );
            }

            return $this->write($companyEntityId, $draft, (int) $prior->getKey());
        });
    }

    /**
     * Read one company's certificate history. Authorization remains the
     * caller's responsibility, matching the other Skills stores.
     *
     * @return Collection<int, SkillCertification>
     */
    public function history(int $companyEntityId, int $employeeEntityId): Collection
    {
        return SkillCertification::query()
            ->forCompany($this->tenantContext->requireTenantId(), $companyEntityId)
            ->where('employee_entity_id', $employeeEntityId)
            ->orderByDesc('issued_on')
            ->orderByDesc('id')
            ->get();
    }

    private function write(
        int $companyEntityId,
        SkillCertificationDraft $draft,
        ?int $supersedesCertificationId = null,
    ): SkillCertification {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->assertEntity($tenantId, $companyEntityId, $draft->employeeEntityId, WorkforceResourceType::Employee);
        $skillIds = $this->normalizedSkillIds($draft->skillIds);

        if ($skillIds !== []) {
            $skills = Skill::query()
                ->forCompany($tenantId, $companyEntityId)
                ->whereIn('id', $skillIds)
                ->get(['id', 'active']);

            if ($skills->count() !== count($skillIds)) {
                throw new InvalidSkillCertificationException('Every mapped skill must belong to this company catalog.');
            }

            if ($skills->contains(fn (Skill $skill): bool => ! $skill->active)) {
                throw new InvalidSkillCertificationException('A certification cannot be mapped to an inactive skill.');
            }
        }

        $this->assertDraft($draft);

        $certification = SkillCertification::query()->create([
            'tenant_id' => $tenantId,
            'company_entity_id' => $companyEntityId,
            'employee_entity_id' => $draft->employeeEntityId,
            'issuer' => trim($draft->issuer),
            'external_reference' => trim($draft->externalReference),
            'issued_on' => $draft->issuedOn,
            'expires_on' => $draft->expiresOn,
            'renewal_status' => $draft->renewalStatus,
            'evidence_link' => trim($draft->evidenceLink),
            'supersedes_certification_id' => $supersedesCertificationId,
        ]);

        foreach ($skillIds as $skillId) {
            SkillCertificationSkill::query()->create([
                'tenant_id' => $tenantId,
                'company_entity_id' => $companyEntityId,
                'certification_id' => $certification->getKey(),
                'skill_id' => $skillId,
            ]);
        }

        return $certification->refresh();
    }

    /** @param  list<int>  $skillIds */
    private function normalizedSkillIds(array $skillIds): array
    {
        $normalized = [];

        foreach ($skillIds as $skillId) {
            if (! is_int($skillId) || $skillId < 1) {
                throw new InvalidSkillCertificationException('Mapped skill ids must be positive integers.');
            }
            $normalized[$skillId] = true;
        }

        return array_keys($normalized);
    }

    private function assertDraft(SkillCertificationDraft $draft): void
    {
        $issuer = trim($draft->issuer);
        $reference = trim($draft->externalReference);
        $evidence = trim($draft->evidenceLink);

        if ($issuer === '' || mb_strlen($issuer) > 200) {
            throw new InvalidSkillCertificationException('A certification needs an issuer of 1–200 characters.');
        }

        if ($reference === '' || mb_strlen($reference) > 160) {
            throw new InvalidSkillCertificationException('A certification needs an external reference of 1–160 characters.');
        }

        if ($evidence === '' || mb_strlen($evidence) > 2048) {
            throw new InvalidSkillCertificationException('A certification needs an evidence link of 1–2048 characters.');
        }

        if ($draft->expiresOn !== null && $this->date($draft->expiresOn) < $this->date($draft->issuedOn)) {
            throw new InvalidSkillCertificationException('A certification cannot expire before it is issued.');
        }

        if ($draft->renewalStatus === CertificationRenewalStatus::Renewed) {
            throw new InvalidSkillCertificationException(
                'A new certification row must not be created with the historical renewed status.',
            );
        }
    }

    private function date(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    private function assertEntity(
        int $tenantId,
        int $companyEntityId,
        int $entityId,
        WorkforceResourceType $type,
    ): void {
        if ($this->workforce->resolve($tenantId, $companyEntityId, $type, $entityId) === null) {
            throw new InvalidSkillCertificationException(
                "The employee [$entityId] must belong to this company's workforce.",
            );
        }
    }
}
