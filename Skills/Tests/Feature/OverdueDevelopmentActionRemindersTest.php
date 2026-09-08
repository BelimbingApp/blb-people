<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Data\DevelopmentActionDraft;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\CriticalClassification;
use App\Domains\People\Skills\Enums\DevelopmentActionClosure;
use App\Domains\People\Skills\Enums\DevelopmentActionType;
use App\Domains\People\Skills\Enums\ReminderRule;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Enums\SkillScope;
use App\Domains\People\Skills\Models\DevelopmentAction;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\DevelopmentActionStore;
use App\Domains\People\Skills\Services\ReminderRules;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Self-contained: every helper is prefixed devActionReminder and lives here.
 *
 * Overdue actions are seeded through the store's own lifecycle
 * (proposeManual + approve) so the rows are ones the application can produce;
 * only the closure/status variants the rule must exclude-or-include are set
 * by direct update, each naming the store transition that writes that value
 * in production. Nothing here sends anything.
 */

afterEach(function (): void {
    app(TenantContext::class)->clear();
    Carbon::setTestNow();
});

/** @return array{tenantId: int, companyId: int, employeeId: int, ownerId: int, hrId: int, trainerId: int, skillId: int, otherCompanyId: int, otherEmployeeId: int} */
function devActionReminderFixture(string $name): array
{
    static $seq = 0;
    $seq++;

    $tenant = createTenant(['name' => $name]);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);

    $companyId = (int) NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Company)->id;
    $skillId = devActionReminderSkill($companyId, 'dev-action.skill.'.$seq);

    $otherCompanyId = (int) NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Company)->id;

    return [
        'tenantId' => $tenantId,
        'companyId' => $companyId,
        'employeeId' => (int) NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId)->id,
        'ownerId' => (int) NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId)->id,
        'hrId' => (int) NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId)->id,
        'trainerId' => (int) NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId)->id,
        'skillId' => $skillId,
        'otherCompanyId' => $otherCompanyId,
        'otherEmployeeId' => (int) NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $otherCompanyId)->id,
    ];
}

function devActionReminderSkill(int $companyId, string $code): int
{
    $category = app(SkillCatalogStore::class)->defineCategory($companyId, 'ops-'.$code, 'Operations '.$code);

    return (int) app(SkillCatalogStore::class)->defineSkill($companyId, new SkillDraft(
        code: $code,
        name: 'Skill '.$code,
        definition: 'Does the thing.',
        categoryId: (int) $category->id,
        scope: SkillScope::Shared,
        criticalClassification: CriticalClassification::Safety,
        evidenceGuide: 'Observed.',
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
        defaultReassessmentMonths: 12,
    ))->id;
}

function devActionReminderSeed(array $f, int $companyId, int $employeeId, array $overrides = []): DevelopmentAction
{
    $store = app(DevelopmentActionStore::class);

    $action = $store->proposeManual($companyId, new DevelopmentActionDraft(...array_merge([
        'employeeEntityId' => $employeeId,
        'type' => DevelopmentActionType::Coaching,
        'objective' => 'Reach permit level four safely.',
        'intervention' => 'Four supervised permit cycles with feedback.',
        'expectedEvidence' => 'Signed observation checklist for four cycles.',
        'ownerEmployeeEntityId' => $f['ownerId'],
        'hrCoordinatorEmployeeEntityId' => $f['hrId'],
        'trainerEmployeeEntityId' => $f['trainerId'],
        'startDate' => Carbon::now()->subDays(10),
        'dueDate' => Carbon::now()->subDay(),
        'skillId' => $f['skillId'],
        'startingLevel' => 1,
        'targetLevel' => 3,
        'criticality' => RequirementCriticality::Critical,
        'manualReason' => 'HOD requested coaching after the gap review.',
    ], $overrides)));

    return $store->approve($companyId, (int) $action->id, 10);
}

function devActionReminderDue(array $f, int $companyId): array
{
    return app(ReminderRules::class)->due($companyId, Carbon::now()->toImmutable());
}

test('an open action past its due date is listed once naming action and owner', function (): void {
    $f = devActionReminderFixture('Action Overdue Tenant');
    $action = devActionReminderSeed($f, $f['companyId'], $f['employeeId']);

    $due = devActionReminderDue($f, $f['companyId']);

    expect($due)->toHaveCount(1)
        ->and($due[0]->rule)->toBe(ReminderRule::OverdueDevelopmentAction)
        ->and($due[0]->employeeEntityId)->toBe($f['employeeId'])
        ->and($due[0]->developmentActionId)->toBe((int) $action->id)
        ->and($due[0]->ownerEmployeeEntityId)->toBe($f['ownerId'])
        ->and($due[0]->requirementReference)->toBe((string) $action->action_key);
});

test('an action due today or tomorrow is not overdue', function (): void {
    $f = devActionReminderFixture('Action Boundary Tenant');
    devActionReminderSeed($f, $f['companyId'], $f['employeeId'], ['dueDate' => Carbon::now()]);
    devActionReminderSeed($f, $f['companyId'], $f['ownerId'], ['dueDate' => Carbon::now()->addDay()]);

    // Days Overdue > 0 means strictly before today; due today is not overdue.
    expect(devActionReminderDue($f, $f['companyId']))->toBe([]);
});

test('the date predicate is whereDate-safe under a frozen clock', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-07 12:00:00'));
    $f = devActionReminderFixture('Action Frozen Tenant');
    $action = devActionReminderSeed($f, $f['companyId'], $f['employeeId']);

    // SQLite keeps the time part on a date column; the rule must compare
    // dates, so a due timestamp late yesterday still counts and one at
    // midnight today still does not.
    $touched = DB::table('people_connector_skill_development_actions')->where('id', (int) $action->id)
        ->update(['due_date' => '2026-09-06 23:59:59']);

    expect($touched)->toBe(1);

    $due = app(ReminderRules::class)->due($f['companyId'], Carbon::parse('2026-09-07 12:00:00')->toImmutable());

    expect($due)->toHaveCount(1)
        ->and($due[0]->rule)->toBe(ReminderRule::OverdueDevelopmentAction);

    $touched = DB::table('people_connector_skill_development_actions')->where('id', (int) $action->id)
        ->update(['due_date' => '2026-09-07 00:00:00']);

    expect($touched)->toBe(1);

    expect(app(ReminderRules::class)->due($f['companyId'], Carbon::parse('2026-09-07 12:00:00')->toImmutable()))->toBe([]);
});

test('open-family closures past due are listed; closed, held and proposed work is not', function (): void {
    $f = devActionReminderFixture('Action Closure Tenant');
    $store = app(DevelopmentActionStore::class);

    // completeIntervention writes FurtherActionRequired and linkReassessment
    // writes PendingReassessment in production; ClosedCompetent is the third
    // terminal value the rule must pass over.
    $pending = devActionReminderSeed($f, $f['companyId'], $f['employeeId']);
    expect(DB::table('people_connector_skill_development_actions')->where('id', (int) $pending->id)
        ->update(['closure_status' => DevelopmentActionClosure::PendingReassessment->value]))->toBe(1);

    $further = devActionReminderSeed($f, $f['companyId'], $f['ownerId']);
    expect(DB::table('people_connector_skill_development_actions')->where('id', (int) $further->id)
        ->update(['closure_status' => DevelopmentActionClosure::FurtherActionRequired->value]))->toBe(1);

    $competent = devActionReminderSeed($f, $f['companyId'], $f['hrId']);
    expect(DB::table('people_connector_skill_development_actions')->where('id', (int) $competent->id)
        ->update(['closure_status' => DevelopmentActionClosure::ClosedCompetent->value]))->toBe(1);

    // putOnHold and cancel are the store's own transitions; a proposal is an
    // approved action's earlier self. None of the three is overdue work.
    $held = devActionReminderSeed($f, $f['companyId'], $f['trainerId']);
    $store->putOnHold($f['companyId'], (int) $held->id, 'Waiting on the new roster.');

    $cancelled = devActionReminderSeed($f, $f['companyId'], $f['employeeId']);
    $store->cancel($f['companyId'], (int) $cancelled->id, 'Role changed.');

    $store->proposeManual($f['companyId'], new DevelopmentActionDraft(
        employeeEntityId: $f['employeeId'],
        type: DevelopmentActionType::Coaching,
        objective: 'Unapproved proposal.',
        intervention: 'Nothing yet.',
        expectedEvidence: 'Nothing yet.',
        ownerEmployeeEntityId: $f['ownerId'],
        hrCoordinatorEmployeeEntityId: $f['hrId'],
        trainerEmployeeEntityId: $f['trainerId'],
        startDate: Carbon::now()->subDays(10),
        dueDate: Carbon::now()->subDay(),
        skillId: $f['skillId'],
        startingLevel: 1,
        targetLevel: 3,
        criticality: RequirementCriticality::Critical,
        manualReason: 'Still waiting on approval.',
    ));

    $due = devActionReminderDue($f, $f['companyId']);
    $listed = array_map(fn ($reminder): ?int => $reminder->developmentActionId, $due);

    expect($due)->toHaveCount(2)
        ->and($listed)->toContain((int) $pending->id, (int) $further->id)
        ->and($listed)->not->toContain((int) $competent->id, (int) $held->id, (int) $cancelled->id);
});

test('an overdue score and an overdue action yield three reminders, one per rule', function (): void {
    $f = devActionReminderFixture('Action Three Rules Tenant');

    $skillId = devActionReminderSkill($f['companyId'], 'dev-action.both');
    $assessment = SkillAssessment::query()->create([
        'tenant_id' => $f['tenantId'],
        'company_entity_id' => $f['companyId'],
        'employee_entity_id' => $f['employeeId'],
        'skill_id' => $skillId,
        'requirement_reference' => 'dev-action.ops',
        'requirement_version' => 2,
        'required_level' => 4,
        // Essential, not critical: a lone critical holder is also a coverage
        // gap (0009-i), and this file measures the action rule alone.
        'criticality' => 'essential',
        'mandatory_gate' => true,
        'assessed_level' => 2,
        'gap' => 2,
        'method' => 'direct_observation',
        'cycle' => 'annual',
        'status' => 'draft',
        'evidence' => 'Observed once.',
        'assessed_at' => Carbon::now()->subYear(),
        'assessor_user_id' => 9,
    ]);
    EmployeeSkillScore::query()->create([
        'tenant_id' => $f['tenantId'],
        'company_entity_id' => $f['companyId'],
        'employee_entity_id' => $f['employeeId'],
        'skill_id' => $skillId,
        'source_assessment_id' => (int) $assessment->id,
        'requirement_reference' => 'dev-action.ops',
        'requirement_version' => 2,
        'required_level' => 4,
        'current_level' => 2,
        'gap' => 2,
        'mandatory_gate' => true,
        'criticality' => 'essential',
        'assessed_at' => Carbon::now()->subYear(),
        'next_assessment_due' => Carbon::now()->subDays(3)->toDateString(),
        'valid_until' => Carbon::now()->addDays(10)->toDateString(),
    ]);

    devActionReminderSeed($f, $f['companyId'], $f['employeeId']);

    $due = app(ReminderRules::class)->due($f['companyId'], Carbon::now()->toImmutable(), expiringWithinDays: 30);

    expect(collect($due)->pluck('rule')->all())->toBe([
        ReminderRule::OverdueReassessment,
        ReminderRule::ExpiringCertificate,
        ReminderRule::OverdueDevelopmentAction,
    ]);
});

test('a sibling company overdue action never appears in this company list', function (): void {
    $f = devActionReminderFixture('Action Sibling Tenant');

    $store = app(DevelopmentActionStore::class);
    $siblingSkill = devActionReminderSkill($f['otherCompanyId'], 'dev-action.sibling');
    $sibling = $store->proposeManual($f['otherCompanyId'], new DevelopmentActionDraft(
        employeeEntityId: $f['otherEmployeeId'],
        type: DevelopmentActionType::Coaching,
        objective: 'Beta secret coaching.',
        intervention: 'Beta sessions.',
        expectedEvidence: 'Beta checklist.',
        ownerEmployeeEntityId: $f['otherEmployeeId'],
        hrCoordinatorEmployeeEntityId: $f['otherEmployeeId'],
        trainerEmployeeEntityId: $f['otherEmployeeId'],
        startDate: Carbon::now()->subDays(10),
        dueDate: Carbon::now()->subDay(),
        skillId: $siblingSkill,
        startingLevel: 1,
        targetLevel: 3,
        criticality: RequirementCriticality::Critical,
        manualReason: 'Beta proposal.',
    ));
    $store->approve($f['otherCompanyId'], (int) $sibling->id, 10);

    expect(devActionReminderDue($f, $f['companyId']))->toBe([]);
});

test('another tenant overdue action is never loaded', function (): void {
    $f = devActionReminderFixture('Action Home Tenant');
    $g = devActionReminderFixture('Action Away Tenant');
    devActionReminderSeed($g, $g['companyId'], $g['employeeId']);
    app(TenantContext::class)->set($f['tenantId']);

    $due = devActionReminderDue($f, $f['companyId']);

    // Same query shape as production: the tenant pin, not post-filtering,
    // keeps the other tenant's rows out of the list.
    expect($due)->toBe([])
        ->and(DevelopmentAction::query()->forCompany($g['tenantId'], $g['companyId'])->count())->toBe(1);
});

test('the command prints the new case and writes nothing', function (): void {
    $f = devActionReminderFixture('Action Command Tenant');
    devActionReminderSeed($f, $f['companyId'], $f['employeeId']);
    $before = DB::table('people_connector_skill_development_actions')->count();

    $this->artisan('people:reminders-due', ['--tenant' => $f['tenantId'], '--company' => $f['companyId']])
        ->expectsOutputToContain('overdue_development_action: 1')
        ->expectsOutputToContain('Nothing was sent.')
        ->assertSuccessful();

    expect(DB::table('people_connector_skill_development_actions')->count())->toBe($before);
});
