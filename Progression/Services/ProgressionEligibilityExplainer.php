<?php

namespace App\Domains\People\Progression\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Performance\Enums\PerformanceOutcome;
use App\Domains\People\Performance\Enums\PerformanceReviewStatus;
use App\Domains\People\Performance\Models\PerformanceReview;
use App\Domains\People\Progression\Contracts\ReadsPublishedProgressionPolicy;
use App\Domains\People\Progression\Data\ProgressionEligibilityExplanation;
use App\Domains\People\Progression\Data\ProgressionRuleExplanation;
use App\Domains\People\Progression\Enums\ProgressionPolicyRefusal;
use App\Domains\People\Progression\Enums\ProgressionRuleSource;
use App\Domains\People\Progression\Enums\ProgressionRuleStatus;
use App\Domains\People\Progression\Models\ProgressionPolicy;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Skills\Models\EmployeeSkillScore;

/**
 * What the published policy asks of one person, and what the evidence says —
 * without deciding anything.
 *
 * The refusals are the policy reader's own, unchanged: this adds evidence to a
 * policy it was already allowed to read, so it must not become a second, weaker
 * way to reach the same rows.
 */
final class ProgressionEligibilityExplainer
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ReadsPublishedProgressionPolicy $policies,
    ) {}

    public function explain(WorkforceSubject $subject): ProgressionEligibilityExplanation|ProgressionPolicyRefusal
    {
        $policy = $this->policies->read($subject);

        if ($policy instanceof ProgressionPolicyRefusal) {
            return $policy;
        }

        $rules = ProgressionPolicy::query()
            ->forCompany($policy->tenantId, $policy->companyId)
            ->where('policy_id', $policy->policyId)
            ->where('version', $policy->version)
            ->value('rules') ?? [];

        return new ProgressionEligibilityExplanation(
            tenantId: $policy->tenantId,
            companyId: $policy->companyId,
            policyId: $policy->policyId,
            policyVersion: $policy->version,
            rules: [
                ...$this->competenceRules($policy->tenantId, $policy->companyId, (int) $subject->stableId, $rules),
                ...$this->performanceRules($policy->tenantId, $policy->companyId, (int) $subject->stableId, $rules),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return list<ProgressionRuleExplanation>
     */
    private function competenceRules(int $tenantId, int $companyId, int $employeeEntityId, array $rules): array
    {
        $declared = is_array($rules['competence'] ?? null) ? $rules['competence'] : [];
        $explanations = [];

        foreach ($declared as $rule) {
            if (! is_array($rule) || ! isset($rule['code'])) {
                continue;
            }

            $required = isset($rule['required_level']) ? (int) $rule['required_level'] : null;
            $score = EmployeeSkillScore::query()
                ->forCompany($tenantId, $companyId)
                ->where('employee_entity_id', $employeeEntityId)
                ->where('requirement_reference', (string) $rule['code'])
                ->orderByDesc('assessed_at')
                ->first();

            // No score is not a score of zero. Nobody has assessed this person
            // against this rule, and saying "not met" would put a failure on
            // the record that no assessor ever observed.
            if ($score === null) {
                $explanations[] = new ProgressionRuleExplanation(
                    code: (string) $rule['code'],
                    source: ProgressionRuleSource::Competence,
                    status: ProgressionRuleStatus::Unknown,
                    requiredLevel: $required,
                );

                continue;
            }

            $observed = (int) $score->current_level;
            $explanations[] = new ProgressionRuleExplanation(
                code: (string) $rule['code'],
                source: ProgressionRuleSource::Competence,
                status: $required === null || $observed >= $required
                    ? ProgressionRuleStatus::Met
                    : ProgressionRuleStatus::NotMet,
                requiredLevel: $required,
                observedLevel: $observed,
                requirementVersion: (int) $score->requirement_version,
            );
        }

        return $explanations;
    }

    /**
     * Performance appears only when the policy says it is relevant, and only
     * the versioned review evidence the policy declared eligible.
     *
     * Omitted is not the same as unknown. A policy that never mentions
     * performance is not one whose performance evidence is missing, and
     * reporting an unknown row would invite somebody to go and find evidence
     * for a rule that does not exist.
     *
     * @param  array<string, mixed>  $rules
     * @return list<ProgressionRuleExplanation>
     */
    private function performanceRules(int $tenantId, int $companyId, int $employeeEntityId, array $rules): array
    {
        $performance = $rules['performance'] ?? null;

        if (! is_array($performance) || ($performance['relevant'] ?? false) !== true) {
            return [];
        }

        $review = $this->eligibleReview($tenantId, $companyId, $employeeEntityId, $performance);

        // No eligible review is not a failed review. The policy has to have
        // published what an absence means; this reads that rule rather than
        // assuming the harsher answer.
        if ($review === null) {
            return [new ProgressionRuleExplanation(
                code: 'performance',
                source: ProgressionRuleSource::Performance,
                status: ($performance['missing_evidence'] ?? 'unknown') === 'not_met'
                    ? ProgressionRuleStatus::NotMet
                    : ProgressionRuleStatus::Unknown,
            )];
        }

        return [new ProgressionRuleExplanation(
            code: 'performance',
            source: ProgressionRuleSource::Performance,
            status: in_array($review->outcome, [PerformanceOutcome::Met, PerformanceOutcome::Exceeded], true)
                ? ProgressionRuleStatus::Met
                : ProgressionRuleStatus::NotMet,
            reviewId: (int) $review->id,
            reviewVersion: (int) $review->version,
            period: $review->period_start->format('Y-m-d').'/'.$review->period_end->format('Y-m-d'),
            reevaluationRequired: $review->supersedes_review_id !== null,
            supersededReviewId: $review->supersedes_review_id === null ? null : (int) $review->supersedes_review_id,
        )];
    }

    /**
     * The most recent review the policy declares eligible.
     *
     * Periods are matched in PHP rather than as an OR over the query, because
     * the company scope refuses a disjunction before the company axis is
     * pinned, and a person's reviews are few.
     *
     * @param  array<string, mixed>  $performance
     */
    private function eligibleReview(int $tenantId, int $companyId, int $employeeEntityId, array $performance): ?PerformanceReview
    {
        $requireFinal = ($performance['require_final'] ?? true) !== false;
        $periods = is_array($performance['periods'] ?? null) ? $performance['periods'] : [];

        $query = PerformanceReview::query()
            ->forCompany($tenantId, $companyId)
            ->where('employee_entity_id', $employeeEntityId);

        if ($requireFinal) {
            $query->where('status', PerformanceReviewStatus::Finalized->value);
        }

        foreach ($query->orderByDesc('version')->orderByDesc('id')->get() as $review) {
            if ($this->withinDeclaredPeriod($review, $periods)) {
                return $review;
            }
        }

        return null;
    }

    /** @param array<int, mixed> $periods */
    private function withinDeclaredPeriod(PerformanceReview $review, array $periods): bool
    {
        foreach ($periods as $period) {
            if (! is_array($period) || ! isset($period['start'], $period['end'])) {
                continue;
            }
            $start = $review->period_start->format('Y-m-d');
            $end = $review->period_end->format('Y-m-d');
            if ($start >= (string) $period['start'] && $end <= (string) $period['end']) {
                return true;
            }
        }

        return false;
    }
}
