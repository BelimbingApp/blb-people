<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Progression\Enums\ProgressionPolicyRefusal;
use App\Domains\People\Progression\Enums\ProgressionPolicyStatus;
use App\Domains\People\Progression\Enums\ProgressionRuleSource;
use App\Domains\People\Progression\Enums\ProgressionRuleStatus;
use App\Domains\People\Progression\Models\ProgressionPolicy;
use App\Domains\People\Progression\Services\ProgressionEligibilityExplainer;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\CriticalClassification;
use App\Domains\People\Skills\Enums\SkillScope;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use Illuminate\Support\Carbon;

/*
 * Self-contained: helpers are prefixed explain and live here.
 *
 * This lane explains; it never decides. No test asserts an award, a pay change
 * or an appointment, because none should exist.
 */

afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

/** @param array<string, mixed> $rules */
function explainPolicy(int $tenantId, int $companyId, array $rules): ProgressionPolicy
{
    return ProgressionPolicy::query()->create([
        'tenant_id' => $tenantId,
        'company_entity_id' => $companyId,
        'policy_id' => 'technical-progression',
        'version' => '2026.1',
        'status' => ProgressionPolicyStatus::Published,
        'effective_from' => '2026-01-01',
        'rules' => $rules,
        'published_at' => '2026-01-01 00:00:00',
    ]);
}

/** @return array{tenantId: int, companyId: int, employeeId: int, siblingCompanyId: int} */
function explainFixture(array $rules = []): array
{
    [$tenant, $company] = createTenantWithCompany();
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'status' => 'active']);
    explainPolicy($tenantId, (int) $company->id, $rules === [] ? explainDefaultRules() : $rules);

    return [
        'tenantId' => $tenantId,
        'companyId' => (int) $company->id,
        'employeeId' => (int) $employee->id,
        'siblingCompanyId' => (int) Company::factory()->create(['tenant_id' => $tenantId, 'status' => 'active'])->id,
    ];
}

/** @return array<string, mixed> */
function explainDefaultRules(): array
{
    return [
        'competence' => [
            ['code' => 'forklift.operation', 'required_level' => 4],
        ],
    ];
}

function explainSubject(array $f): WorkforceSubject
{
    return new WorkforceSubject($f['tenantId'], $f['companyId'], WorkforceResourceType::Employee, (string) $f['employeeId']);
}

/**
 * A real skill and a real draft assessment behind the score.
 *
 * The score row carries foreign keys to both; inventing ids fails the
 * constraint, and a fixture the application cannot produce is not worth testing
 * against.
 */
function explainScore(array $f, int $currentLevel, int $requirementVersion = 3, ?string $assessedAt = null): EmployeeSkillScore
{
    static $seq = 0;
    $seq++;
    $category = app(SkillCatalogStore::class)->defineCategory($f['companyId'], 'ops-'.$seq, 'Operations '.$seq);
    $skillId = (int) app(SkillCatalogStore::class)->defineSkill($f['companyId'], new SkillDraft(
        code: 'forklift.operation.'.$seq,
        name: 'Forklift Operation '.$seq,
        definition: 'Operates a counterbalance forklift.',
        categoryId: (int) $category->id,
        scope: SkillScope::Shared,
        criticalClassification: CriticalClassification::Safety,
        evidenceGuide: 'Observed lift cycle.',
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
        defaultReassessmentMonths: 12,
    ))->id;

    $assessment = SkillAssessment::query()->create([
        'tenant_id' => $f['tenantId'],
        'company_entity_id' => $f['companyId'],
        'employee_entity_id' => $f['employeeId'],
        'skill_id' => $skillId,
        'requirement_reference' => 'forklift.operation',
        'requirement_version' => $requirementVersion,
        'required_level' => 4,
        'criticality' => 'critical',
        'mandatory_gate' => true,
        'assessed_level' => $currentLevel,
        'gap' => max(4 - $currentLevel, 0),
        'method' => 'direct_observation',
        'cycle' => 'annual',
        'status' => 'draft',
        'evidence' => 'Observed lift cycle.',
        'assessed_at' => $assessedAt ?? now(),
        'assessor_user_id' => 9,
    ]);

    return EmployeeSkillScore::query()->create([
        'tenant_id' => $f['tenantId'],
        'company_entity_id' => $f['companyId'],
        'employee_entity_id' => $f['employeeId'],
        'skill_id' => $skillId,
        'source_assessment_id' => (int) $assessment->id,
        'requirement_reference' => 'forklift.operation',
        'requirement_version' => $requirementVersion,
        'required_level' => 4,
        'current_level' => $currentLevel,
        'gap' => max(4 - $currentLevel, 0),
        'mandatory_gate' => true,
        'criticality' => 'critical',
        'assessed_at' => $assessedAt ?? now(),
    ]);
}

test('a competence rule with evidence at or above the required level reads met', function (): void {
    $f = explainFixture();
    explainScore($f, currentLevel: 4);

    $explanation = app(ProgressionEligibilityExplainer::class)->explain(explainSubject($f));

    expect($explanation->rules)->toHaveCount(1)
        ->and($explanation->rules[0]->source)->toBe(ProgressionRuleSource::Competence)
        ->and($explanation->rules[0]->status)->toBe(ProgressionRuleStatus::Met)
        ->and($explanation->rules[0]->requirementVersion)->toBe(3);
});

test('a competence rule with evidence below the required level reads not met', function (): void {
    $f = explainFixture();
    explainScore($f, currentLevel: 2);

    $explanation = app(ProgressionEligibilityExplainer::class)->explain(explainSubject($f));

    expect($explanation->rules[0]->status)->toBe(ProgressionRuleStatus::NotMet);
});

test('a competence rule with no evidence at all reads unknown, never zero', function (): void {
    $f = explainFixture();

    // Nobody has assessed this person against this skill. "Unknown" and
    // "assessed at zero" are different facts about a person, and reporting the
    // second when the first is true would put an unearned failure on the record.
    $explanation = app(ProgressionEligibilityExplainer::class)->explain(explainSubject($f));

    expect($explanation->rules[0]->status)->toBe(ProgressionRuleStatus::Unknown)
        ->and($explanation->rules[0]->observedLevel)->toBeNull()
        ->and($explanation->rules[0]->requirementVersion)->toBeNull();
});

test('a policy that does not declare performance relevant omits performance rows', function (): void {
    $f = explainFixture();
    explainScore($f, currentLevel: 4);

    $explanation = app(ProgressionEligibilityExplainer::class)->explain(explainSubject($f));

    // Omitted, not "unknown". A policy that never mentions performance is not a
    // policy whose performance evidence is missing.
    expect(collect($explanation->rules)->pluck('source')->all())->toBe([ProgressionRuleSource::Competence]);
});

test('a policy that declares performance relevant includes a performance row', function (): void {
    $f = explainFixture([
        'competence' => [['code' => 'forklift.operation', 'required_level' => 4]],
        'performance' => ['relevant' => true],
    ]);
    explainScore($f, currentLevel: 4);

    $explanation = app(ProgressionEligibilityExplainer::class)->explain(explainSubject($f));

    // No performance evidence is wired up yet, so the honest answer is unknown
    // rather than an invented pass.
    expect(collect($explanation->rules)->pluck('source')->all())
        ->toBe([ProgressionRuleSource::Competence, ProgressionRuleSource::Performance])
        ->and(collect($explanation->rules)->last()->status)->toBe(ProgressionRuleStatus::Unknown);
});

test('an explanation is refused for a subject in another company', function (): void {
    $f = explainFixture();
    $foreign = new WorkforceSubject(
        $f['tenantId'],
        $f['siblingCompanyId'],
        WorkforceResourceType::Employee,
        (string) $f['employeeId'],
    );

    // The employee belongs to the first company; asking about them under the
    // sibling's scope must not answer.
    $result = app(ProgressionEligibilityExplainer::class)->explain($foreign);

    expect($result)->toBe(ProgressionPolicyRefusal::WrongCompany);
});

test('an explanation is refused for a subject in another tenant', function (): void {
    $f = explainFixture();
    $foreign = new WorkforceSubject(
        $f['tenantId'] + 1,
        $f['companyId'],
        WorkforceResourceType::Employee,
        (string) $f['employeeId'],
    );

    expect(app(ProgressionEligibilityExplainer::class)->explain($foreign))
        ->toBe(ProgressionPolicyRefusal::TenantMismatch);
});

test('an explanation is refused when no policy is published for the company', function (): void {
    [$tenant, $company] = createTenantWithCompany();
    app(TenantContext::class)->set((int) $tenant->id);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'status' => 'active']);
    $subject = new WorkforceSubject((int) $tenant->id, (int) $company->id, WorkforceResourceType::Employee, (string) $employee->id);

    expect(app(ProgressionEligibilityExplainer::class)->explain($subject))
        ->toBe(ProgressionPolicyRefusal::NoPolicyPublished);
});

test('an explanation names the policy version it explains and decides nothing', function (): void {
    $f = explainFixture();
    explainScore($f, currentLevel: 4);

    $explanation = app(ProgressionEligibilityExplainer::class)->explain(explainSubject($f));

    // Every rule met is still not a decision. The record says what is true, and
    // whether that earns anything is somebody's judgement, not this service's.
    expect($explanation->policyId)->toBe('technical-progression')
        ->and($explanation->policyVersion)->toBe('2026.1')
        ->and(method_exists($explanation, 'eligible'))->toBeFalse();
});

test('an explanation reports the subject own evidence, not a colleague at the same company', function (): void {
    $f = explainFixture();
    $colleague = Employee::factory()->create(['company_id' => $f['companyId'], 'status' => 'active']);
    explainScore($f, currentLevel: 1, assessedAt: '2026-01-01 00:00:00');
    // Newer than the subject's, so without the subject filter the ordering
    // would pick the colleague's row and report it as this person's.
    explainScore(
        ['tenantId' => $f['tenantId'], 'companyId' => $f['companyId'], 'employeeId' => (int) $colleague->id],
        currentLevel: 5,
        assessedAt: '2026-06-01 00:00:00',
    );

    // Same company, so no company boundary is crossed and nothing refuses this.
    // The only thing keeping one person's explanation about that person is the
    // subject filter itself.
    $explanation = app(ProgressionEligibilityExplainer::class)->explain(explainSubject($f));

    expect($explanation->rules[0]->observedLevel)->toBe(1)
        ->and($explanation->rules[0]->status)->toBe(ProgressionRuleStatus::NotMet);
});
