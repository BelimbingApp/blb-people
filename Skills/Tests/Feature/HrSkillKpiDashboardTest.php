<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Data\RequirementItemDraft;
use App\Domains\People\Skills\Data\RequirementProfileDraft;
use App\Domains\People\Skills\Data\RequirementSelectorDraft;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Data\SkillKpiSummaryResult;
use App\Domains\People\Skills\Enums\AssessmentResultBand;
use App\Domains\People\Skills\Enums\DevelopmentActionClosure;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Enums\SelectorType;
use App\Domains\People\Skills\Livewire\Assessment\Matrix;
use App\Domains\People\Skills\Livewire\DevelopmentAction\Index as DevelopmentActionIndex;
use App\Domains\People\Skills\Livewire\HrDashboard\Index;
use App\Domains\People\Skills\Models\DevelopmentAction;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\AssessmentWorkflowContext;
use App\Domains\People\Skills\Services\RequirementProfileStore;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Services\SkillKpiSummary;
use App\Domains\People\Skills\Tests\Support\CompanyIsolationFixture;
use App\Domains\People\Skills\Tests\Support\TwoCompanyTenant;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Enums\TrainingEventStatus;
use App\Domains\People\Training\Livewire\Event\Index as EventIndex;
use App\Domains\People\Training\Models\TrainingCourse;
use App\Domains\People\Training\Models\TrainingEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * 0007-e (#363): the #16 contractual KPIs per company and department.
 *
 * Self-contained: every helper is prefixed kpi and lives here. The fixture is
 * the one the issue names — four active employees with two required skills
 * each (expected 8) and six latest finalized assessments — in the alpha
 * company of a TwoCompanyTenant so the sibling-company reading is real.
 */
beforeEach(function (): void {
    $this->withoutVite();
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    Carbon::setTestNow();
});

/** @return array<string, mixed> */
function kpiFixture(bool $withProfile = true, bool $withRecords = true): array
{
    $tenant = CompanyIsolationFixture::twoCompaniesInOneTenant('KPI Alpha', 'KPI Beta');
    app(TenantContext::class)->set($tenant->tenantId);
    setupAuthzRoles();
    $companyId = $tenant->alphaCompanyEntityId;
    $tag = Str::lower(Str::random(6));

    $production = kpiUnit($companyId, 'prod-'.$tag, 'Production');
    $engineering = kpiUnit($companyId, 'eng-'.$tag, 'Engineering');
    $p1 = kpiEmployee($companyId, $production, 'Kpi Prod One');
    $p2 = kpiEmployee($companyId, $production, 'Kpi Prod Two');
    $p3 = kpiEmployee($companyId, $production, 'Kpi Prod Three');
    $e1 = kpiEmployee($companyId, $engineering, 'Kpi Eng One');

    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory($companyId, 'kpi-'.$tag, 'KPI');
    $skillA = (int) $catalog->defineSkill($companyId, new SkillDraft('kpi-'.$tag.'.a', 'KPI skill A', 'First required skill.', (int) $category->id))->id;
    $skillB = (int) $catalog->defineSkill($companyId, new SkillDraft('kpi-'.$tag.'.b', 'KPI skill B', 'Second required skill.', (int) $category->id))->id;

    if ($withProfile) {
        $store = app(RequirementProfileStore::class);
        $profile = $store->draft($companyId, new RequirementProfileDraft(
            code: 'kpi-'.$tag, name: 'KPI role',
            selectors: [new RequirementSelectorDraft(SelectorType::Company)],
            items: [
                new RequirementItemDraft($skillA, 1, 3, RequirementCriticality::Critical, 60.0),
                new RequirementItemDraft($skillB, 2, 3, RequirementCriticality::Essential, 40.0),
            ],
        ));
        $store->publish($companyId, (int) $profile->id);
    }

    $f = [
        'tenant' => $tenant, 'tenantId' => $tenant->tenantId, 'companyId' => $companyId,
        'production' => $production, 'engineering' => $engineering,
        'p1' => $p1, 'p2' => $p2, 'p3' => $p3, 'e1' => $e1, 'skillA' => $skillA, 'skillB' => $skillB,
        'hr' => kpiUser($companyId, 'people_hr'), 'hod' => kpiUser($companyId, 'people_hod'),
        'siblingHr' => kpiUser($tenant->betaCompanyEntityId, 'people_hr'),
    ];

    if ($withRecords) {
        kpiAssessment($f, $p1, $skillA, AssessmentResultBand::Meets);
        kpiAssessment($f, $p1, $skillB, AssessmentResultBand::Exceeds);
        kpiAssessment($f, $p2, $skillA, AssessmentResultBand::Meets);
        kpiAssessment($f, $p3, $skillA, AssessmentResultBand::Meets);
        kpiAssessment($f, $e1, $skillA, AssessmentResultBand::MajorGap);
        // The issue's sixth latest record is "meets but hod_verification =
        // pending". The 0330_02_05 workflow trigger refuses a finalized row
        // that is not verified, so the sixth latest record is verified and
        // lapsed instead — the other way a Meets fails "Verified + Current" —
        // and the pending one stays at pending_hod_verification, where it is
        // not finalized and so not a latest record either.
        kpiAssessment($f, $p2, $skillB, AssessmentResultBand::Meets, extra: ['valid_until' => now()->subDay()->toDateString()]);
        kpiAssessment($f, $p3, $skillB, AssessmentResultBand::Meets, verified: false);
    }

    return $f;
}

function kpiUnit(int $companyId, string $code, string $name): PeopleReferenceEntry
{
    return PeopleReferenceEntry::query()->create([
        'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => $code, 'name' => $name, 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
}

function kpiEmployee(int $companyId, ?PeopleReferenceEntry $unit, string $name, string $status = 'active'): Employee
{
    $employee = Employee::factory()->create([
        'company_id' => $companyId, 'full_name' => $name, 'short_name' => null, 'status' => $status, 'employee_type' => 'full_time',
    ]);
    if ($unit !== null) {
        EmployeeWorkProfile::query()->create(['employee_id' => $employee->id, 'organization_unit_id' => $unit->id]);
    }

    return $employee;
}

function kpiUser(int $companyId, string $roleCode): User
{
    $user = User::factory()->create(['company_id' => $companyId]);
    PrincipalRole::query()->create([
        'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value, 'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->valueOrFail('id'),
    ]);

    return $user;
}

/**
 * One finalized assessment, marched through the workflow states the way the
 * store does, so the history guards see a legitimate row. With $verified
 * false the row stops at pending_hod_verification: the schema has no
 * finalized-but-unverified state.
 *
 * @param  array<string, mixed>  $extra  columns set on the draft (valid_until, next_assessment_due, supersedes_assessment_id)
 */
function kpiAssessment(array $f, Employee $employee, int $skillId, AssessmentResultBand $band, bool $verified = true, array $extra = [], ?int $companyId = null): SkillAssessment
{
    $companyId ??= $f['companyId'];
    $assessed = match ($band) {
        AssessmentResultBand::Exceeds => 4, AssessmentResultBand::Meets => 3, AssessmentResultBand::MinorGap => 2,
        AssessmentResultBand::MajorGap => 1, default => 0,
    };

    return AssessmentWorkflowContext::runStoreMutation(function () use ($f, $employee, $skillId, $band, $verified, $extra, $companyId, $assessed): SkillAssessment {
        $assessment = SkillAssessment::query()->create([
            'tenant_id' => $f['tenantId'], 'company_entity_id' => $companyId,
            'employee_entity_id' => $employee->id, 'skill_id' => $skillId,
            'requirement_reference' => 'kpi.role', 'requirement_version' => 1, 'required_level' => 3,
            'assessed_level' => $assessed, 'gap' => max(3 - $assessed, 0), 'result_band' => $band,
            'criticality' => 'critical', 'mandatory_gate' => true,
            'method' => 'direct_observation', 'cycle' => 'annual', 'status' => 'submitted',
            'evidence' => 'Observed.', 'assessed_at' => now()->subDay(), 'assessor_user_id' => $f['hr']->id,
            'hod_verification' => 'pending', ...$extra,
        ]);
        $assessment->update(['status' => 'pending_hod_verification']);
        if (! $verified) {
            return $assessment;
        }
        $assessment->update(['hod_verification' => 'verified', 'hod_verifier_user_id' => $f['hod']->id, 'hod_verified_at' => now()]);
        $assessment->update(['status' => 'finalized', 'finalized_at' => now(), 'finalized_by_user_id' => $f['hod']->id]);

        return $assessment;
    });
}

function kpiAction(array $f, Employee $employee, DevelopmentActionClosure $closure, string $dueDate): DevelopmentAction
{
    return DevelopmentAction::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'], 'action_key' => (string) Str::uuid(),
        'employee_entity_id' => $employee->id, 'skill_id' => $f['skillA'],
        'employee_name_snapshot' => $employee->full_name, 'starting_level' => 1, 'target_level' => 3, 'gap_at_start' => 2,
        'criticality' => 'critical', 'mandatory_gate' => true, 'priority_score' => 600, 'priority_explanation' => 'Fixture.',
        'action_type' => 'coaching', 'objective' => 'Close the gap.', 'intervention' => 'Coach.', 'expected_evidence' => 'Observed.',
        'owner_employee_entity_id' => $f['p1']->id, 'hr_coordinator_employee_entity_id' => $f['p1']->id,
        'start_date' => now()->subMonth()->toDateString(), 'due_date' => $dueDate,
        'status' => in_array($closure, [DevelopmentActionClosure::ClosedCompetent, DevelopmentActionClosure::Cancelled], true) ? 'completed' : 'in_progress',
        'closure_status' => $closure,
    ]);
}

function kpiEvent(array $f, TrainingEventStatus $status, ?PeopleReferenceEntry $unit): TrainingEvent
{
    $course = TrainingCourse::query()->forCompany($f['tenantId'], $f['companyId'])->first() ?? TrainingCourse::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'], 'code' => 'kpi-'.Str::lower(Str::random(8)),
        'title' => 'KPI course', 'delivery_mode' => DeliveryMode::InternalClassroom,
        'internal_trainer_employee_entity_id' => $f['p1']->id, 'active' => true,
    ]);

    return TrainingEvent::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'], 'event_key' => (string) Str::uuid(),
        'course_id' => $course->id, 'course_code_snapshot' => $course->code, 'course_title_snapshot' => $course->title,
        'delivery_mode_snapshot' => DeliveryMode::InternalClassroom, 'target_department_entity_id' => $unit?->id,
        'organizer_employee_entity_id' => $f['p1']->id, 'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHours(2),
        'capacity' => 10, 'status' => $status,
    ]);
}

function kpiSummary(array $f): SkillKpiSummaryResult
{
    return app(SkillKpiSummary::class)->forCompany($f['tenantId'], $f['companyId']);
}

/** @return array<string, int|float|null> */
function kpiValues(array $metrics): array
{
    return array_map(static fn (array $metric): int|float|null => $metric['value'], $metrics);
}

function kpiPage(array $f, User $actor)
{
    return Livewire::actingAs($actor)->test(Index::class);
}

// ─── Definitions ─────────────────────────────────────────────────────────────

test('the issue fixture yields coverage 0.75, verified competency 4/6 and one major or critical gap', function (): void {
    $f = kpiFixture();
    $company = kpiSummary($f)->company;

    expect($company['active_staff']['value'])->toBe(4)
        ->and($company['expected_assessments']['value'])->toBe(8)
        ->and($company['latest_records']['value'])->toBe(6)
        ->and($company['assessment_coverage']['value'])->toBe(0.75)
        ->and($company['verified_competent']['value'])->toBe(4)
        ->and($company['verified_competency_rate']['value'])->toEqualWithDelta(4 / 6, 0.000001)
        ->and($company['major_critical_gaps']['value'])->toBe(1)
        ->and($company['assessment_coverage']['definition'])->toBe('Assessment Coverage = latest scored assessment records / expected assessments.')
        ->and($company['assessment_coverage']['as_of'])->toBeInstanceOf(DateTimeImmutable::class);
});

test('a superseded assessment is not a latest record and a not-assessed band is not counted in coverage', function (): void {
    $f = kpiFixture();
    $before = kpiSummary($f)->company;

    $old = kpiAssessment($f, $f['p3'], $f['skillB'], AssessmentResultBand::Meets);
    kpiAssessment($f, $f['p3'], $f['skillB'], AssessmentResultBand::Exceeds, extra: ['supersedes_assessment_id' => $old->id]);
    $company = kpiSummary($f)->company;

    // Guard: the supersedes chain drops the older row, so one new latest record, not two.
    expect($company['latest_records']['value'])->toBe($before['latest_records']['value'] + 1);

    kpiAssessment($f, $f['e1'], $f['skillB'], AssessmentResultBand::NotAssessed);

    // Guard: not_assessed is excluded from latest records and so from coverage.
    expect(kpiSummary($f)->company['latest_records']['value'])->toBe($company['latest_records']['value'])
        ->and(kpiSummary($f)->company['assessment_coverage']['value'])->toBe($company['assessment_coverage']['value']);
});

test('a verified meets assessment valid until yesterday is not verified competent and one valid today is', function (): void {
    Carbon::setTestNow('2026-09-07 15:00:00');
    $f = kpiFixture();
    $base = kpiSummary($f)->company['verified_competent']['value'];

    // Inserted with a time part: the column is a date, SQLite keeps the text.
    $expired = kpiAssessment($f, $f['p1'], $f['skillB'], AssessmentResultBand::Meets, extra: ['valid_until' => '2026-09-06 23:59:00']);
    expect(DB::table('people_connector_skill_assessments')->where('id', $expired->id)->value('valid_until'))->toBe('2026-09-06 23:59:00');

    // Guard: valid_until strictly before the as-of date is not current.
    expect(kpiSummary($f)->company['verified_competent']['value'])->toBe($base);

    kpiAssessment($f, $f['e1'], $f['skillB'], AssessmentResultBand::Meets, extra: ['valid_until' => '2026-09-07 00:30:00']);

    // Guard: a time part on today's date still counts as valid today (whereDate-safe).
    expect(kpiSummary($f)->company['verified_competent']['value'])->toBe($base + 1);
});

test('due or expired within 30 days counts a next-due date at as-of plus 30 days and not plus 31', function (): void {
    Carbon::setTestNow('2026-09-07 09:00:00');
    $f = kpiFixture();
    $base = kpiSummary($f)->company['due_within_30_days']['value'];

    kpiAssessment($f, $f['p3'], $f['skillB'], AssessmentResultBand::Meets, extra: ['next_assessment_due' => '2026-10-07']);

    // Guard: on/before as-of + 30 days counts.
    expect(kpiSummary($f)->company['due_within_30_days']['value'])->toBe($base + 1);

    kpiAssessment($f, $f['e1'], $f['skillB'], AssessmentResultBand::Meets, extra: ['next_assessment_due' => '2026-10-08']);

    // Guard: as-of + 31 days does not.
    expect(kpiSummary($f)->company['due_within_30_days']['value'])->toBe($base + 1);
});

test('open actions count the three open closure states and overdue counts a due date of yesterday, not today', function (): void {
    Carbon::setTestNow('2026-09-07 09:00:00');
    $f = kpiFixture();

    kpiAction($f, $f['p1'], DevelopmentActionClosure::Open, '2026-09-07');
    kpiAction($f, $f['p2'], DevelopmentActionClosure::PendingReassessment, '2026-09-06');
    kpiAction($f, $f['p3'], DevelopmentActionClosure::FurtherActionRequired, '2026-09-30');
    kpiAction($f, $f['e1'], DevelopmentActionClosure::ClosedCompetent, '2026-09-01');
    kpiAction($f, $f['e1'], DevelopmentActionClosure::Cancelled, '2026-09-01');
    $company = kpiSummary($f)->company;

    // Guard: closed_competent and cancelled are not open; only the day-before due date is overdue.
    expect($company['open_actions']['value'])->toBe(3)
        ->and($company['overdue_actions']['value'])->toBe(1);
});

test('scheduled or active training counts scheduled and in-progress events and excludes completed and cancelled', function (): void {
    $f = kpiFixture();

    kpiEvent($f, TrainingEventStatus::Scheduled, $f['production']);
    kpiEvent($f, TrainingEventStatus::InProgress, $f['engineering']);
    kpiEvent($f, TrainingEventStatus::Completed, $f['production']);
    kpiEvent($f, TrainingEventStatus::Cancelled, $f['production']);

    // Guard: status filter on training events.
    expect(kpiSummary($f)->company['scheduled_training']['value'])->toBe(2);
});

test('with zero expected assessments both rates are null and the page shows n/a, not 0%', function (): void {
    $f = kpiFixture(withProfile: false, withRecords: false);
    $company = kpiSummary($f)->company;

    // Guard: a zero denominator yields null, never 0.
    expect($company['expected_assessments']['value'])->toBe(0)
        ->and($company['assessment_coverage']['value'])->toBeNull()
        ->and($company['verified_competency_rate']['value'])->toBeNull();

    $page = kpiPage($f, $f['hr'])->assertOk()->assertSee('n/a');
    expect($page->html())->not->toContain('0.0%');
});

// ─── Departments ─────────────────────────────────────────────────────────────

test('department rows sum to the company row for every count metric and a department filter shows only that row', function (): void {
    $f = kpiFixture();
    kpiAction($f, $f['p1'], DevelopmentActionClosure::Open, now()->subDay()->toDateString());
    kpiEvent($f, TrainingEventStatus::Scheduled, $f['engineering']);
    // An event with no target department still belongs to the company, so it
    // gets its own row rather than vanishing from the department table.
    kpiEvent($f, TrainingEventStatus::Scheduled, null);
    $summary = kpiSummary($f);

    expect(array_column($summary->departments, 'department'))->toBe(['Engineering', 'Production', 'No department']);

    foreach (SkillKpiSummaryResult::countMetrics() as $metric) {
        $sum = array_sum(array_map(static fn (array $row): int => $row['metrics'][$metric]['value'], $summary->departments));

        // Guard: department attribution partitions the company for every count.
        expect($sum)->toBe($summary->company[$metric]['value'], $metric);
    }

    expect(kpiValues($summary->department((int) $f['production']->id)['metrics']))->toMatchArray([
        'active_staff' => 3, 'expected_assessments' => 6, 'latest_records' => 5, 'verified_competent' => 4, 'major_critical_gaps' => 0,
    ]);

    $page = kpiPage($f, $f['hr'])->assertOk()->assertSee('Production')->assertSee('Engineering')
        ->call('selectDepartment', (string) $f['engineering']->id)
        ->assertSet('department', (string) $f['engineering']->id);
    $rows = $page->viewData('departmentRows');

    // Guard: the department filter narrows the table to the chosen unit.
    expect(array_column($rows, 'department'))->toBe(['Engineering']);
});

// ─── Page ────────────────────────────────────────────────────────────────────

test('every cell carries its value, definition and as-of, and count cells link to the drill-down that lists their records', function (): void {
    $f = kpiFixture();
    $page = kpiPage($f, $f['hr'])->assertOk();
    $html = $page->html();
    $asOf = $page->viewData('summary')->asOf->format('Y-m-d H:i');

    expect($html)->toContain('<caption')
        ->toContain('Company skill KPIs')
        ->toContain('Skill KPIs by department')
        ->toContain('Assessment Coverage = latest scored assessment records / expected assessments.')
        ->toContain('As of '.$asOf)
        ->toContain('data-kpi="assessment_coverage" data-kpi-value="0.75"')
        ->toContain('data-kpi="major_critical_gaps" data-kpi-value="1"')
        ->toContain('75.0%');

    $links = $page->viewData('links');
    expect($links['company']['major_critical_gaps'])->toBe(route('people.skill.assessment.matrix', ['band' => 'major_gap,critical_gap']))
        ->and($links['company']['open_actions'])->toBe(route('people.skill.development-actions.index', ['closure' => 'open,pending_reassessment,further_action_required']))
        ->and($links['company']['scheduled_training'])->toBe(route('people.training.events.index', ['status' => 'scheduled,in_progress']))
        ->and($links[(string) $f['engineering']->id]['major_critical_gaps'])
        ->toBe(route('people.skill.assessment.matrix', ['band' => 'major_gap,critical_gap', 'department' => $f['engineering']->id]))
        ->and($links['company'])->not->toHaveKey('assessment_coverage');
    expect($html)->toContain('href="'.e($links['company']['major_critical_gaps']).'"');
});

test('the drill-down pages honour the filters the dashboard links carry', function (): void {
    $f = kpiFixture();
    kpiAction($f, $f['p1'], DevelopmentActionClosure::Open, now()->subDay()->toDateString());
    kpiAction($f, $f['p2'], DevelopmentActionClosure::Cancelled, now()->subDay()->toDateString());
    kpiEvent($f, TrainingEventStatus::Scheduled, $f['engineering']);
    kpiEvent($f, TrainingEventStatus::Completed, $f['production']);

    // Guard: matrix band filter — only the engineer holds a major/critical gap.
    $matrix = Livewire::withQueryParams(['band' => 'major_gap,critical_gap'])->actingAs($f['hr'])->test(Matrix::class)->assertOk();
    expect($matrix->viewData('employees')->pluck('display_name')->all())->toBe(['Kpi Eng One']);

    // Guard: matrix department filter.
    $matrix = Livewire::withQueryParams(['department' => (string) $f['engineering']->id])->actingAs($f['hr'])->test(Matrix::class)->assertOk();
    expect($matrix->viewData('employees')->pluck('display_name')->all())->toBe(['Kpi Eng One']);

    // Guard: development-action closure filter drops the cancelled commitment from the terminal register.
    $actions = Livewire::withQueryParams(['closure' => 'open,pending_reassessment,further_action_required'])->actingAs($f['hr'])->test(DevelopmentActionIndex::class)->assertOk();
    expect($actions->viewData('actions')->pluck('closure_status')->map(fn ($closure) => $closure->value)->all())->toBe(['open'])
        ->and($actions->viewData('terminalActions'))->toHaveCount(0);

    // Guard: event status filter.
    $events = Livewire::withQueryParams(['status' => 'scheduled,in_progress'])->actingAs($f['hr'])->test(EventIndex::class)->assertOk();
    expect($events->viewData('events')->pluck('status')->map(fn ($status) => $status->value)->all())->toBe(['scheduled']);
});

test('the route and the component require the HR audience', function (): void {
    $f = kpiFixture();

    $this->actingAs($f['hr'])->get(route('people.skill.hr-dashboard'))->assertOk();

    // Guard: authz middleware on the route and authorizeAudience in mount.
    $this->actingAs($f['hod'])->get(route('people.skill.hr-dashboard'))->assertForbidden();
    Livewire::actingAs($f['hod'])->test(Index::class)->assertForbidden();
});

test('HR of the sibling company sees none of this company records and another tenant rows are not loaded', function (): void {
    $f = kpiFixture();
    /** @var TwoCompanyTenant $tenant */
    $tenant = $f['tenant'];

    $sibling = kpiPage($f, $f['siblingHr'])->assertOk()->assertSet('companyEntityId', $tenant->betaCompanyEntityId);

    // Guard: CompanyAttribution scopes the selector to the actor's company.
    expect(kpiValues($sibling->viewData('summary')->company))->toMatchArray(['active_staff' => 0, 'latest_records' => 0, 'assessment_coverage' => null]);
    expect($sibling->viewData('companies'))->not->toHaveKey($f['companyId']);
    $sibling->call('selectCompany', $f['companyId'])->assertNotFound();

    [$otherTenant, $otherCompany] = createTenantWithCompany(['name' => 'KPI Other Tenant'], ['name' => 'KPI Other Co', 'status' => 'active']);
    app(TenantContext::class)->set((int) $otherTenant->id);
    $stranger = Employee::factory()->create(['company_id' => $otherCompany->id, 'full_name' => 'Kpi Stranger', 'status' => 'active', 'employee_type' => 'full_time']);
    $otherCategory = app(SkillCatalogStore::class)->defineCategory((int) $otherCompany->id, 'other', 'Other');
    $otherSkill = app(SkillCatalogStore::class)->defineSkill((int) $otherCompany->id, new SkillDraft('other.skill', 'Other skill', 'Another tenant.', (int) $otherCategory->id));
    DB::table('people_connector_skill_assessments')->insert([
        'tenant_id' => $otherTenant->id, 'company_entity_id' => $otherCompany->id, 'employee_entity_id' => $stranger->id,
        'skill_id' => $otherSkill->id, 'requirement_reference' => 'other', 'requirement_version' => 1, 'required_level' => 3,
        'assessed_level' => 3, 'gap' => 0, 'result_band' => 'meets', 'criticality' => 'critical', 'method' => 'direct_observation',
        'cycle' => 'annual', 'status' => 'draft', 'hod_verification' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(TenantContext::class)->set($f['tenantId']);

    // Guard: forCompany pins tenant and company on every read.
    expect(kpiValues(kpiSummary($f)->company))->toMatchArray(['active_staff' => 4, 'latest_records' => 6]);
});

test('rendering the dashboard writes no assessment, action or event row', function (): void {
    $f = kpiFixture();
    kpiAction($f, $f['p1'], DevelopmentActionClosure::Open, now()->addWeek()->toDateString());
    kpiEvent($f, TrainingEventStatus::Scheduled, $f['production']);
    $count = static fn (): array => [
        DB::table('people_connector_skill_assessments')->count(),
        DB::table('people_connector_skill_development_actions')->count(),
        DB::table('people_connector_training_events')->count(),
    ];
    $before = $count();

    kpiPage($f, $f['hr'])->assertOk()->call('selectDepartment', (string) $f['production']->id)->assertOk()->call('selectCompany', $f['companyId'])->assertOk();

    // Guard: the page is read-only.
    expect($count())->toBe($before)->and($before)->toBe([7, 1, 1]);
});
