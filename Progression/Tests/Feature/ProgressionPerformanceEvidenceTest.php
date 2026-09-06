<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Performance\Data\ObservationDraft;
use App\Domains\People\Performance\Data\ReviewDraft;
use App\Domains\People\Performance\Enums\PerformanceOutcome;
use App\Domains\People\Performance\Models\PerformanceReview;
use App\Domains\People\Performance\Services\PerformanceReviewStore;
use App\Domains\People\Progression\Enums\ProgressionPolicyRefusal;
use App\Domains\People\Progression\Enums\ProgressionPolicyStatus;
use App\Domains\People\Progression\Enums\ProgressionRuleSource;
use App\Domains\People\Progression\Enums\ProgressionRuleStatus;
use App\Domains\People\Progression\Models\ProgressionPolicy;
use App\Domains\People\Progression\Services\ProgressionEligibilityExplainer;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;

/**
 * JP-A09: a published policy may consume performance evidence, and only the
 * versioned review evidence the policy declares eligible. Missing and disputed
 * periods follow the published rule rather than an assumed zero, and a
 * correction flags governed reevaluation without rewriting anything.
 *
 * This lane still only explains. No test asserts an award, an appointment or a
 * pay change, because none should exist.
 *
 * Self-contained: helpers are prefixed perfPolicy and live here.
 *
 * @return array<string, mixed>
 */
function perfPolicyFixture(array $performanceRules, string $status = ProgressionPolicyStatus::Published->value): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'JP-A09 Tenant'], ['name' => 'JP-A09 Company', 'status' => 'active']);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $hr = User::factory()->create(['company_id' => $companyId]);
    PrincipalRole::query()->create([
        'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $hr->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', 'people_hr')->valueOrFail('id'),
    ]);
    $employee = Employee::factory()->create([
        'company_id' => $companyId, 'full_name' => 'Progression Subject',
        'status' => 'active', 'employee_type' => 'full_time',
    ]);

    ProgressionPolicy::query()->create([
        'tenant_id' => $tenantId,
        'company_entity_id' => $companyId,
        'policy_id' => 'technical-progression',
        'version' => '2026.1',
        'status' => $status,
        'effective_from' => '2026-01-01',
        'rules' => ['competence' => [], 'performance' => $performanceRules],
        'published_at' => $status === ProgressionPolicyStatus::Published->value ? '2026-01-01 00:00:00' : null,
    ]);

    return compact('tenantId', 'companyId', 'hr', 'employee');
}

function perfPolicySubject(array $f): WorkforceSubject
{
    return new WorkforceSubject($f['tenantId'], $f['companyId'], WorkforceResourceType::Employee, (string) $f['employee']->id);
}

function perfPolicyReview(array $f, bool $finalize = true, PerformanceOutcome $outcome = PerformanceOutcome::Met): PerformanceReview
{
    $store = app(PerformanceReviewStore::class);
    $observation = $store->recordObservation($f['hr'], $f['companyId'], new ObservationDraft(
        employeeEntityId: (int) $f['employee']->id,
        windowStart: new DateTimeImmutable('2026-01-01'),
        windowEnd: new DateTimeImmutable('2026-03-31'),
        evidence: 'Delivered the governed changeover.',
    ));
    $draft = $store->draftReview($f['hr'], $f['companyId'], new ReviewDraft(
        employeeEntityId: (int) $f['employee']->id,
        periodStart: new DateTimeImmutable('2026-01-01'),
        periodEnd: new DateTimeImmutable('2026-03-31'),
        cutoffAt: new DateTimeImmutable('2026-04-07T00:00:00+00:00'),
        observationIds: [(int) $observation->id],
        outcome: $outcome,
        rationale: 'Met the agreed expectation with attributable evidence.',
    ));

    return $finalize ? $store->finalize($f['hr'], $f['companyId'], (int) $draft->id) : $draft;
}

/** @return array<string, mixed> */
function perfPolicyRules(array $overrides = []): array
{
    return array_replace([
        'relevant' => true,
        'periods' => [['start' => '2026-01-01', 'end' => '2026-03-31']],
        'require_final' => true,
        'missing_evidence' => 'unknown',
    ], $overrides);
}

function perfPolicyPerformance(array $f): array
{
    $explanation = app(ProgressionEligibilityExplainer::class)->explain(perfPolicySubject($f));
    expect($explanation)->not->toBeInstanceOf(ProgressionPolicyRefusal::class);

    return array_values(array_filter(
        $explanation->rules,
        fn ($rule): bool => $rule->source === ProgressionRuleSource::Performance,
    ));
}

test('an unpublished policy consumes no performance evidence at all', function (): void {
    $f = perfPolicyFixture(perfPolicyRules(), ProgressionPolicyStatus::Draft->value);
    perfPolicyReview($f);

    // The reader refuses before any evidence is read. A draft policy is not a
    // weaker route to the same rows.
    expect(app(ProgressionEligibilityExplainer::class)->explain(perfPolicySubject($f)))
        ->toBe(ProgressionPolicyRefusal::NoPolicyPublished);
});

test('a published policy consumes the finalized review and records the version it used', function (): void {
    $f = perfPolicyFixture(perfPolicyRules());
    $review = perfPolicyReview($f);

    $rules = perfPolicyPerformance($f);

    expect($rules)->toHaveCount(1)
        ->and($rules[0]->status)->toBe(ProgressionRuleStatus::Met)
        ->and($rules[0]->reviewId)->toBe((int) $review->id)
        ->and($rules[0]->reviewVersion)->toBe(1)
        ->and($rules[0]->period)->toBe('2026-01-01/2026-03-31');
});

test('a review that is not final is refused when the policy requires finality', function (): void {
    $f = perfPolicyFixture(perfPolicyRules());
    perfPolicyReview($f, finalize: false);

    // A draft review is evidence of nothing yet; the policy asked for final.
    $rules = perfPolicyPerformance($f);

    expect($rules)->toHaveCount(1)
        ->and($rules[0]->status)->toBe(ProgressionRuleStatus::Unknown)
        ->and($rules[0]->reviewId)->toBeNull();
});

test('missing evidence follows the published rule rather than an assumed zero', function (): void {
    $f = perfPolicyFixture(perfPolicyRules());

    // Nobody has reviewed this person for the declared period.
    $rules = perfPolicyPerformance($f);

    expect($rules)->toHaveCount(1)
        ->and($rules[0]->status)->toBe(ProgressionRuleStatus::Unknown)
        ->and($rules[0]->status)->not->toBe(ProgressionRuleStatus::NotMet);
});

test('a policy may publish not_met as its missing-evidence rule, and that is honoured', function (): void {
    $f = perfPolicyFixture(perfPolicyRules(['missing_evidence' => 'not_met']));

    // The point is that the rule is published, not that it is lenient.
    expect(perfPolicyPerformance($f)[0]->status)->toBe(ProgressionRuleStatus::NotMet);
});

test('a review outside the policy periods is not consumed', function (): void {
    $f = perfPolicyFixture(perfPolicyRules(['periods' => [['start' => '2025-01-01', 'end' => '2025-03-31']]]));
    perfPolicyReview($f);

    expect(perfPolicyPerformance($f)[0]->status)->toBe(ProgressionRuleStatus::Unknown);
});

test('a corrected review flags governed reevaluation and never rewrites the prior answer', function (): void {
    $f = perfPolicyFixture(perfPolicyRules());
    $store = app(PerformanceReviewStore::class);
    $original = perfPolicyReview($f);
    $corrected = $store->correct($f['hr'], $f['companyId'], (int) $original->id, new ReviewDraft(
        employeeEntityId: (int) $f['employee']->id,
        periodStart: new DateTimeImmutable('2026-01-01'),
        periodEnd: new DateTimeImmutable('2026-03-31'),
        cutoffAt: new DateTimeImmutable('2026-04-07T00:00:00+00:00'),
        observationIds: [],
        outcome: PerformanceOutcome::PartiallyMet,
        rationale: 'Late source correction reduced the attributable result.',
    ), 'Source system corrected the stop attribution.');

    $rules = perfPolicyPerformance($f);

    expect($rules[0]->reviewId)->toBe((int) $corrected->id)
        ->and($rules[0]->reviewVersion)->toBe(2)
        ->and($rules[0]->reevaluationRequired)->toBeTrue()
        ->and($rules[0]->supersededReviewId)->toBe((int) $original->id)
        ->and($original->refresh()->outcome)->toBe(PerformanceOutcome::Met);
});

test('a policy that never mentions performance reports no performance rule', function (): void {
    $f = perfPolicyFixture([]);
    perfPolicyReview($f);

    // Omitted is not unknown: reporting an unknown row would invite somebody to
    // hunt evidence for a rule the policy does not have.
    expect(perfPolicyPerformance($f))->toBe([]);
});
