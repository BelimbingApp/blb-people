<?php

namespace App\Domains\People\Performance\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Performance\Data\ObservationDraft;
use App\Domains\People\Performance\Data\ReviewDraft;
use App\Domains\People\Performance\Enums\PerformanceReviewStatus;
use App\Domains\People\Performance\Exceptions\PerformanceReviewException;
use App\Domains\People\Performance\Models\PerformanceObservation;
use App\Domains\People\Performance\Models\PerformanceReview;
use App\Domains\People\Performance\Models\PerformanceReviewResponse;
use App\Domains\People\Skills\Services\CompanyAttribution;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Observations, released reviews, employee responses and versioned corrections
 * (0009; scenarios JP-A07 and JP-A11).
 *
 * The rule the whole class exists for: a closed outcome is never mutated. Late
 * evidence and disputes produce a new version that supersedes the old one, and
 * the old one stays readable exactly as it was released — including its
 * rationale and the response it drew, "including outcomes that do not change
 * the review".
 */
final class PerformanceReviewStore
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly CompanyAttribution $companies,
    ) {}

    public function recordObservation(User $actor, int $companyId, ObservationDraft $draft): PerformanceObservation
    {
        $tenantId = $this->scope($actor, $companyId);
        if (trim($draft->evidence) === '') {
            throw new PerformanceReviewException('An observation needs attributable evidence.');
        }

        return PerformanceObservation::query()->create([
            'tenant_id' => $tenantId,
            'company_entity_id' => $companyId,
            'employee_entity_id' => $draft->employeeEntityId,
            'window_start' => $draft->windowStart,
            'window_end' => $draft->windowEnd,
            'evidence' => trim($draft->evidence),
            'source_reference' => $draft->sourceReference,
            'source_version' => $draft->sourceVersion,
            'author_user_id' => $actor->getKey(),
            'recorded_at' => now(),
        ]);
    }

    /**
     * A corrected observation is a new row. The original keeps its evidence so
     * a review that pinned it still shows what the reviewer was looking at.
     */
    public function correctObservation(
        User $actor,
        int $companyId,
        int $observationId,
        string $correctedEvidence,
        ?string $reason = null,
    ): PerformanceObservation {
        $tenantId = $this->scope($actor, $companyId);
        if (trim($correctedEvidence) === '') {
            throw new PerformanceReviewException('A correction needs the corrected evidence.');
        }
        // The reason a record changed is not the same fact as what it now says.
        // Defaulting one to the other is fine for a caller that has only the
        // text, but a caller with both must be able to keep them apart.
        $reason = $reason === null || trim($reason) === '' ? $correctedEvidence : $reason;

        return DB::transaction(function () use ($tenantId, $companyId, $observationId, $correctedEvidence, $reason, $actor): PerformanceObservation {
            $original = $this->observation($tenantId, $companyId, $observationId);

            $replacement = PerformanceObservation::query()->create([
                'tenant_id' => $tenantId,
                'company_entity_id' => $companyId,
                'employee_entity_id' => $original->employee_entity_id,
                'window_start' => $original->window_start,
                'window_end' => $original->window_end,
                'evidence' => trim($correctedEvidence),
                'source_reference' => $original->source_reference,
                'source_version' => $original->source_version,
                'author_user_id' => $actor->getKey(),
                'recorded_at' => now(),
                'supersedes_observation_id' => $original->id,
                'correction_reason' => trim($reason),
            ]);

            $original->update(['corrected_at' => now()]);

            return $replacement;
        });
    }

    public function draftReview(User $actor, int $companyId, ReviewDraft $draft): PerformanceReview
    {
        $tenantId = $this->scope($actor, $companyId);
        $this->assertRationale($draft);

        return DB::transaction(function () use ($tenantId, $companyId, $draft, $actor): PerformanceReview {
            $review = PerformanceReview::query()->create([
                'tenant_id' => $tenantId,
                'company_entity_id' => $companyId,
                'employee_entity_id' => $draft->employeeEntityId,
                'review_key' => (string) Str::uuid(),
                'version' => 1,
                'status' => PerformanceReviewStatus::Draft,
                'period_start' => $draft->periodStart,
                'period_end' => $draft->periodEnd,
                'cutoff_at' => $draft->cutoffAt,
                'outcome' => $draft->outcome,
                'rationale' => trim($draft->rationale),
                'reviewer_user_id' => $actor->getKey(),
            ]);
            $this->pinObservations($tenantId, $companyId, $review, $draft->observationIds);

            return $review;
        });
    }

    public function finalize(User $actor, int $companyId, int $reviewId): PerformanceReview
    {
        $tenantId = $this->scope($actor, $companyId);

        return DB::transaction(function () use ($tenantId, $companyId, $reviewId): PerformanceReview {
            $review = $this->review($tenantId, $companyId, $reviewId);
            if ($review->status !== PerformanceReviewStatus::Draft) {
                throw new PerformanceReviewException('Only a draft review can be finalized.');
            }
            $review->update([
                'status' => PerformanceReviewStatus::Finalized,
                'finalized_at' => now(),
            ]);

            return $review->refresh();
        });
    }

    /**
     * Late evidence or a dispute produces the next version. The original is
     * marked superseded and nothing else about it changes.
     */
    public function correct(User $actor, int $companyId, int $reviewId, ReviewDraft $draft, string $reason): PerformanceReview
    {
        $tenantId = $this->scope($actor, $companyId);
        if (trim($reason) === '') {
            throw new PerformanceReviewException('A correction needs a stated reason: who changed what, and why.');
        }
        $this->assertRationale($draft);

        return DB::transaction(function () use ($tenantId, $companyId, $reviewId, $draft, $reason, $actor): PerformanceReview {
            $original = $this->review($tenantId, $companyId, $reviewId);
            if ($original->status !== PerformanceReviewStatus::Finalized) {
                throw new PerformanceReviewException('Only a finalized review can be corrected.');
            }

            $next = PerformanceReview::query()->create([
                'tenant_id' => $tenantId,
                'company_entity_id' => $companyId,
                'employee_entity_id' => $original->employee_entity_id,
                'review_key' => $original->review_key,
                'version' => (int) $original->version + 1,
                'status' => PerformanceReviewStatus::Finalized,
                'period_start' => $original->period_start,
                'period_end' => $original->period_end,
                'cutoff_at' => $original->cutoff_at,
                'outcome' => $draft->outcome,
                'rationale' => trim($draft->rationale),
                'reviewer_user_id' => $actor->getKey(),
                'finalized_at' => now(),
                'supersedes_review_id' => $original->id,
                'correction_reason' => trim($reason),
            ]);
            $this->pinObservations($tenantId, $companyId, $next, $draft->observationIds);

            $original->update([
                'status' => PerformanceReviewStatus::Superseded,
                'superseded_at' => now(),
            ]);

            return $next;
        });
    }

    /**
     * The employee's answer to a review they were actually shown.
     *
     * Three things this refuses, because a response is a record of what one
     * named person said about one released decision: a draft, which the
     * employee has not seen; a subject other than the one the review is about;
     * and an actor outside the company, like every sibling write on this class.
     */
    public function recordEmployeeResponse(
        User $actor,
        int $companyId,
        int $reviewId,
        int $employeeEntityId,
        string $response,
    ): PerformanceReviewResponse {
        $tenantId = $this->scope($actor, $companyId);
        if (trim($response) === '') {
            throw new PerformanceReviewException('An employee response cannot be empty.');
        }
        $review = $this->review($tenantId, $companyId, $reviewId);
        if ($review->status === PerformanceReviewStatus::Draft) {
            throw new PerformanceReviewException('A response answers a released review; this one is not released yet.');
        }
        if ((int) $employeeEntityId !== (int) $review->employee_entity_id) {
            throw new PerformanceReviewException('A response must name the subject the review is about.');
        }

        return PerformanceReviewResponse::query()->create([
            'tenant_id' => $tenantId,
            'company_entity_id' => $companyId,
            'review_id' => $review->id,
            'employee_entity_id' => $employeeEntityId,
            'response' => trim($response),
            'recorded_at' => now(),
        ]);
    }

    /** @return list<PerformanceReviewResponse> */
    public function responsesFor(int $companyId, int $reviewId): array
    {
        return PerformanceReviewResponse::query()->forCompany($this->tenantId(), $companyId)
            ->where('review_id', $reviewId)->orderBy('id')->get()->all();
    }

    /** @return list<PerformanceObservation> */
    public function observationsFor(int $companyId, int $reviewId): array
    {
        $tenantId = $this->tenantId();
        $ids = DB::table('people_performance_review_observations')
            ->where('tenant_id', $tenantId)
            ->where('company_entity_id', $companyId)
            ->where('review_id', $reviewId)
            ->pluck('observation_id');

        return PerformanceObservation::query()->forCompany($tenantId, $companyId)
            ->whereIn('id', $ids)->orderBy('id')->get()->all();
    }

    /**
     * The review that was in force on a date, and whether it is the original or
     * a correction (JP-A11). Returns the cutoff and version alongside it so a
     * historical report can say what it resolved rather than implying currency.
     *
     * @return array{review: PerformanceReview|null, corrected: bool, version: int|null, cutoff_at: DateTimeInterface|null}
     */
    public function asOf(int $companyId, int $employeeEntityId, DateTimeInterface $asOf): array
    {
        $review = PerformanceReview::query()->forCompany($this->tenantId(), $companyId)
            ->where('employee_entity_id', $employeeEntityId)
            ->whereNotNull('finalized_at')
            ->where('finalized_at', '<=', $asOf->format('Y-m-d H:i:s'))
            ->orderByDesc('finalized_at')
            ->orderByDesc('version')
            ->first();

        return [
            'review' => $review,
            'corrected' => $review !== null && $review->supersedes_review_id !== null,
            'version' => $review === null ? null : (int) $review->version,
            'cutoff_at' => $review?->cutoff_at,
        ];
    }

    /** @param list<int> $observationIds */
    private function pinObservations(int $tenantId, int $companyId, PerformanceReview $review, array $observationIds): void
    {
        foreach ($observationIds as $observationId) {
            $observation = $this->observation($tenantId, $companyId, (int) $observationId);
            DB::table('people_performance_review_observations')->insert([
                'tenant_id' => $tenantId,
                'company_entity_id' => $companyId,
                'review_id' => $review->id,
                'observation_id' => $observation->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function assertRationale(ReviewDraft $draft): void
    {
        if (trim($draft->rationale) === '') {
            throw new PerformanceReviewException('A review needs a released rationale a reader can act on.');
        }
    }

    private function review(int $tenantId, int $companyId, int $reviewId): PerformanceReview
    {
        return PerformanceReview::query()->forCompany($tenantId, $companyId)->lockForUpdate()->find($reviewId)
            ?? throw new PerformanceReviewException('The performance review was not found in this company.');
    }

    private function observation(int $tenantId, int $companyId, int $observationId): PerformanceObservation
    {
        return PerformanceObservation::query()->forCompany($tenantId, $companyId)->find($observationId)
            ?? throw new PerformanceReviewException('The observation was not found in this company.');
    }

    private function scope(User $actor, int $companyId): int
    {
        $tenantId = $this->tenantId();
        if ((int) $actor->tenant_id !== $tenantId || ! $this->companies->mayActFor($actor, $companyId)) {
            throw new PerformanceReviewException('The performance review is unavailable in the current company scope.');
        }

        return $tenantId;
    }

    private function tenantId(): int
    {
        return $this->tenants->currentTenantId()
            ?? throw new PerformanceReviewException('A tenant context is required for performance reviews.');
    }
}
