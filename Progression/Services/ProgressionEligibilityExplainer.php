<?php

namespace App\Domains\People\Progression\Services;

use App\Base\Tenancy\Contracts\TenantContext;
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
                ...self::performanceRules($rules),
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
     * Performance appears only when the policy says it is relevant.
     *
     * Omitted is not the same as unknown. A policy that never mentions
     * performance is not one whose performance evidence is missing, and
     * reporting an unknown row would invite somebody to go and find evidence
     * for a rule that does not exist.
     *
     * @param  array<string, mixed>  $rules
     * @return list<ProgressionRuleExplanation>
     */
    private static function performanceRules(array $rules): array
    {
        $performance = $rules['performance'] ?? null;

        if (! is_array($performance) || ($performance['relevant'] ?? false) !== true) {
            return [];
        }

        // Declared relevant, and no performance evidence is wired up yet. The
        // honest answer is unknown rather than an invented pass.
        return [new ProgressionRuleExplanation(
            code: 'performance',
            source: ProgressionRuleSource::Performance,
            status: ProgressionRuleStatus::Unknown,
        )];
    }
}
