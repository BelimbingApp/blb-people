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
use DateTimeInterface;
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
        $this->assertComplete($draft);
        $this->assertPublishedProfiles($tenantId, $companyId, $draft->competencyLinks);

        return JobDescription::query()->create([
            'tenant_id' => $tenantId,
            'company_entity_id' => $companyId,
            'reference' => $draft->reference,
            'position_stable_id' => $draft->positionStableId,
            'position_version' => $draft->positionVersion,
            'version' => $draft->version,
            'status' => JobDescriptionStatus::Draft,
            'effective_from' => $draft->effectiveFrom,
            'effective_to' => $draft->effectiveTo,
            'purpose' => $draft->purpose,
            'responsibilities' => $draft->responsibilities,
            'duties' => $draft->duties,
            'authority' => $draft->authority,
            'qualifications' => $draft->qualifications,
            'competency_links' => $draft->competencyLinks,
        ]);
    }

    public function publish(User $actor, int $companyId, int $descriptionId): JobDescription
    {
        [$tenantId] = $this->authorize($actor, $companyId);

        return DB::transaction(function () use ($tenantId, $companyId, $descriptionId, $actor): JobDescription {
            $draft = $this->locked($tenantId, $companyId, $descriptionId);
            $this->assertDraft($draft);

            // Publishing is the moment a version becomes policy. Whatever put
            // this row into its current state, the sections are checked again
            // here rather than trusted from draft time.
            self::assertSectionsComplete(
                (string) $draft->purpose,
                (string) $draft->authority,
                (array) $draft->responsibilities,
                (array) $draft->duties,
                (array) $draft->qualifications,
            );
            $this->assertPublishedProfiles($tenantId, $companyId, $draft->competency_links);

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
            $this->assertPublishedProfiles($tenantId, $companyId, $replacement->competency_links);
            $current->forceFill(['status' => JobDescriptionStatus::Superseded, 'superseded_at' => now()])->save();

            return $this->markPublished($replacement, $actor);
        });
    }

    public function applicable(
        int $companyId,
        string $positionStableId,
        int $positionVersion,
        DateTimeInterface $asOf,
    ): ?JobDescription {
        $tenantId = $this->tenantId();
        $date = $asOf->format('Y-m-d');

        return JobDescription::query()->forCompany($tenantId, $companyId)
            ->where('position_stable_id', $positionStableId)
            ->where('position_version', $positionVersion)
            ->whereIn('status', [JobDescriptionStatus::Published, JobDescriptionStatus::Superseded])
            ->whereDate('effective_from', '<=', $date)
            ->whereRaw('(effective_to is null or effective_to >= ?)', [$date])
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->first();
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

    /** @param list<array{requirement_profile_id: int, requirement_profile_version: int}> $links */
    private function assertPublishedProfiles(int $tenantId, int $companyId, array $links): void
    {
        foreach ($links as $link) {
            if (! RequirementProfile::query()->forCompany($tenantId, $companyId)
                ->whereKey($link['requirement_profile_id'] ?? null)
                ->where('version', $link['requirement_profile_version'] ?? null)
                ->where('status', RequirementProfileStatus::Published)->exists()) {
                throw new JobDescriptionException('Every competency link requires an exact published requirement-profile version.');
            }
        }
    }

    private function assertComplete(JobDescriptionDraft $draft): void
    {
        if ($draft->positionVersion < 1 || $draft->version < 1
            || array_any([$draft->reference, $draft->positionStableId], fn (string $value): bool => trim($value) === '')
            || $draft->competencyLinks === []) {
            throw new JobDescriptionException('A job-description version requires complete structured content and version links.');
        }

        self::assertSectionsComplete(
            $draft->purpose,
            $draft->authority,
            $draft->responsibilities,
            $draft->duties,
            $draft->qualifications,
        );
    }

    /**
     * Every structured section must carry something a reader can act on.
     *
     * An empty list was already refused, but ['  '] is not an empty list: a
     * non-empty list of nothing is exactly the shape that slips past a
     * "the list is not empty" check while saying nothing at all. Each entry is
     * held to the same standard as the free-text sections.
     *
     * @param  list<mixed>  $responsibilities
     * @param  list<mixed>  $duties
     * @param  list<mixed>  $qualifications
     */
    private static function assertSectionsComplete(
        string $purpose,
        string $authority,
        array $responsibilities,
        array $duties,
        array $qualifications,
    ): void {
        if (array_any([$purpose, $authority], static fn (string $value): bool => trim($value) === '')) {
            throw new JobDescriptionException('A job-description version requires complete structured content and version links.');
        }

        foreach ([$responsibilities, $duties, $qualifications] as $section) {
            if ($section === []) {
                throw new JobDescriptionException('A job-description version requires complete structured content and version links.');
            }

            foreach ($section as $entry) {
                if (! is_string($entry) || trim($entry) === '') {
                    throw new JobDescriptionException('A job-description section entry cannot be blank.');
                }
            }
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
