<?php

namespace App\Domains\People\Performance\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Performance\Data\JobDescriptionDraft;
use App\Domains\People\Performance\Enums\JobDescriptionStatus;
use App\Domains\People\Performance\Exceptions\JobDescriptionException;
use App\Domains\People\Performance\Models\JobDescription;
use App\Domains\People\Provider\Contracts\ResolvesWorkforceSubjects;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Enums\RequirementProfileStatus;
use App\Domains\People\Skills\Models\RequirementProfile;
use App\Domains\People\Skills\Services\CompanyAttribution;
use Illuminate\Support\Facades\DB;

final readonly class JobDescriptionStore
{
    public const PUBLISH = 'people.performance.job-description.manage';

    public function __construct(
        private TenantContext $tenants,
        private ResolvesWorkforceSubjects $subjects,
        private AuthorizationService $authorization,
        private CompanyAttribution $companies,
    ) {}

    public function draft(int $companyId, JobDescriptionDraft $draft): JobDescription
    {
        $tenantId = $this->tenantId();
        $this->assertPosition($tenantId, $companyId, $draft->positionStableId);
        $this->assertPublishedProfile($tenantId, $companyId, $draft->requirementProfileId, $draft->requirementProfileVersion);

        return JobDescription::query()->create([
            'tenant_id' => $tenantId,
            'company_entity_id' => $companyId,
            'reference' => $draft->reference,
            'position_stable_id' => $draft->positionStableId,
            'version' => $draft->version,
            'status' => JobDescriptionStatus::Draft,
            'effective_from' => $draft->effectiveFrom,
            'effective_to' => $draft->effectiveTo,
            'responsibilities' => $draft->responsibilities,
            'requirement_profile_id' => $draft->requirementProfileId,
            'requirement_profile_version' => $draft->requirementProfileVersion,
        ]);
    }

    public function publish(User $actor, int $companyId, int $descriptionId): JobDescription
    {
        [$tenantId] = $this->authorize($actor, $companyId);

        return DB::transaction(function () use ($tenantId, $companyId, $descriptionId, $actor): JobDescription {
            $draft = $this->locked($tenantId, $companyId, $descriptionId);
            $this->assertDraft($draft);
            $this->assertPublishedProfile($tenantId, $companyId, (int) $draft->requirement_profile_id, (int) $draft->requirement_profile_version);

            if (JobDescription::query()->forCompany($tenantId, $companyId)
                ->where('reference', $draft->reference)->where('status', JobDescriptionStatus::Published)->exists()) {
                throw new JobDescriptionException('A published version already exists; supersede it explicitly.');
            }

            return $this->markPublished($draft, $actor);
        });
    }

    public function supersede(User $actor, int $companyId, int $currentId, int $replacementId): JobDescription
    {
        [$tenantId] = $this->authorize($actor, $companyId);

        return DB::transaction(function () use ($tenantId, $companyId, $currentId, $replacementId, $actor): JobDescription {
            $current = $this->locked($tenantId, $companyId, $currentId);
            $replacement = $this->locked($tenantId, $companyId, $replacementId);
            $this->assertDraft($replacement);
            if ($current->status !== JobDescriptionStatus::Published
                || $current->reference !== $replacement->reference
                || (int) $replacement->version <= (int) $current->version) {
                throw new JobDescriptionException('Supersession requires a newer draft of the same published job description.');
            }
            $this->assertPublishedProfile($tenantId, $companyId, (int) $replacement->requirement_profile_id, (int) $replacement->requirement_profile_version);
            $current->forceFill(['status' => JobDescriptionStatus::Superseded, 'superseded_at' => now()])->save();

            return $this->markPublished($replacement, $actor);
        });
    }

    /** @return array{int} */
    private function authorize(User $actor, int $companyId): array
    {
        $tenantId = $this->tenantId();
        $this->authorization->authorize(Actor::forUser($actor), self::PUBLISH);
        if (! $this->companies->mayActFor($actor, $companyId)) {
            throw new JobDescriptionException('The actor may not publish job descriptions for this company.');
        }

        return [$tenantId];
    }

    private function tenantId(): int
    {
        return $this->tenants->currentTenantId()
            ?? throw new JobDescriptionException('A tenant context is required.');
    }

    private function assertPosition(int $tenantId, int $companyId, string $stableId): void
    {
        $resolution = $this->subjects->resolve(new WorkforceSubject($tenantId, $companyId, WorkforceResourceType::Position, $stableId));
        if ($resolution->record === null) {
            throw new JobDescriptionException('The position is not active in this company.');
        }
    }

    private function assertPublishedProfile(int $tenantId, int $companyId, int $profileId, int $version): void
    {
        if (! RequirementProfile::query()->forCompany($tenantId, $companyId)
            ->whereKey($profileId)->where('version', $version)
            ->where('status', RequirementProfileStatus::Published)->exists()) {
            throw new JobDescriptionException('The exact published requirement-profile version is required.');
        }
    }

    private function locked(int $tenantId, int $companyId, int $id): JobDescription
    {
        return JobDescription::query()->forCompany($tenantId, $companyId)->whereKey($id)->lockForUpdate()->first()
            ?? throw new JobDescriptionException('Job description was not found in this company.');
    }

    private function assertDraft(JobDescription $description): void
    {
        if ($description->status !== JobDescriptionStatus::Draft) {
            throw new JobDescriptionException('Only a draft job description can be published.');
        }
    }

    private function markPublished(JobDescription $description, User $actor): JobDescription
    {
        $description->forceFill([
            'status' => JobDescriptionStatus::Published,
            'published_at' => now(),
            'published_by_user_id' => $actor->getAuthIdentifier(),
        ])->save();

        return $description->refresh();
    }
}
