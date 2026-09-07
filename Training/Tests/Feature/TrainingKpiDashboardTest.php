<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\DevelopmentActionClosure;
use App\Domains\People\Skills\Models\DevelopmentAction;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Tests\Support\CompanyIsolationFixture;
use App\Domains\People\Training\Data\TrainingKpiSummaryResult;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Enums\EffectivenessOutcome;
use App\Domains\People\Training\Enums\EffectivenessReviewStage;
use App\Domains\People\Training\Enums\EffectivenessReviewState;
use App\Domains\People\Training\Enums\TrainingEvaluationStatus;
use App\Domains\People\Training\Enums\TrainingEventStatus;
use App\Domains\People\Training\Enums\TrainingNeedSource;
use App\Domains\People\Training\Enums\TrainingPriority;
use App\Domains\People\Training\Enums\TrainingRequestStatus;
use App\Domains\People\Training\Livewire\Requests\Register;
use App\Domains\People\Training\Livewire\TrainingKpi\Index;
use App\Domains\People\Training\Models\TrainingCourse;
use App\Domains\People\Training\Models\TrainingEffectivenessReview;
use App\Domains\People\Training\Models\TrainingEvaluation;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use App\Domains\People\Training\Models\TrainingRequest;
use App\Domains\People\Training\Models\TrainingSession;
use App\Domains\People\Training\Services\TrainingEffectivenessAggregate;
use App\Domains\People\Training\Services\TrainingKpiSummary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * 0007-f (#389): the revised-workbook training controls per company and
 * department, with definition, as-of and drill-down.
 *
 * Self-contained: every helper is prefixed tkpi and lives here. The clock is
 * pinned so "as of" is a real date the fixtures sit around: the event ended
 * on 2026-08-01, so evaluations fall due on 2026-08-15.
 */
beforeEach(function (): void {
    $this->withoutVite();
    Carbon::setTestNow('2026-09-07 10:00:00');
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    Carbon::setTestNow();
});

/** @return array<string, mixed> */
function tkpiFixture(): array
{
    $tenant = CompanyIsolationFixture::twoCompaniesInOneTenant('TKPI Alpha', 'TKPI Beta');
    app(TenantContext::class)->set($tenant->tenantId);
    setupAuthzRoles();
    $companyId = $tenant->alphaCompanyEntityId;
    $tag = Str::lower(Str::random(6));

    $f = [
        'tenant' => $tenant, 'tenantId' => $tenant->tenantId, 'companyId' => $companyId, 'betaId' => $tenant->betaCompanyEntityId,
        'ops' => tkpiUnit($companyId, 'ops-'.$tag, 'Operations'),
        'qa' => tkpiUnit($companyId, 'qa-'.$tag, 'Quality'),
        'betaUnit' => tkpiUnit($tenant->betaCompanyEntityId, 'bops-'.$tag, 'Beta Operations'),
        'hr' => tkpiUser($companyId, 'people_hr'), 'hod' => tkpiUser($companyId, 'people_hod'),
        'siblingHr' => tkpiUser($tenant->betaCompanyEntityId, 'people_hr'),
    ];
    $f['o1'] = tkpiEmployee($companyId, $f['ops'], 'Tkpi Ops One');
    $f['o2'] = tkpiEmployee($companyId, $f['ops'], 'Tkpi Ops Two');
    $f['q1'] = tkpiEmployee($companyId, $f['qa'], 'Tkpi Quality One');
    $f['b1'] = tkpiEmployee($tenant->betaCompanyEntityId, $f['betaUnit'], 'Tkpi Beta One');
    $f['event'] = tkpiEvent($f, '2026-08-01 12:00:00');
    $category = app(SkillCatalogStore::class)->defineCategory($companyId, 'tkpi-'.$tag, 'TKPI');
    $f['skillId'] = (int) app(SkillCatalogStore::class)->defineSkill($companyId, new SkillDraft('tkpi-'.$tag.'.a', 'TKPI skill', 'Follow-up skill.', (int) $category->id))->id;

    return $f;
}

function tkpiUnit(int $companyId, string $code, string $name): PeopleReferenceEntry
{
    return PeopleReferenceEntry::query()->create([
        'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => $code, 'name' => $name, 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
}

function tkpiEmployee(int $companyId, PeopleReferenceEntry $unit, string $name): Employee
{
    $employee = Employee::factory()->create(['company_id' => $companyId, 'full_name' => $name, 'short_name' => null, 'status' => 'active', 'employee_type' => 'full_time']);
    EmployeeWorkProfile::query()->create(['employee_id' => $employee->id, 'organization_unit_id' => $unit->id]);

    return $employee;
}

function tkpiUser(int $companyId, string $roleCode): User
{
    $user = User::factory()->create(['company_id' => $companyId]);
    PrincipalRole::query()->create([
        'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value, 'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->valueOrFail('id'),
    ]);

    return $user;
}

/** A completed event of a fresh course, ended at the given moment. */
function tkpiEvent(array $f, string $endsAt, ?int $companyId = null): TrainingEvent
{
    $companyId ??= $f['companyId'];
    $course = TrainingCourse::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $companyId, 'code' => 'tkpi-'.Str::lower(Str::random(8)),
        'title' => 'TKPI course', 'delivery_mode' => DeliveryMode::InternalClassroom,
        'internal_trainer_employee_entity_id' => $f['o1']->id, 'active' => true,
    ]);

    return TrainingEvent::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $companyId, 'event_key' => (string) Str::uuid(),
        'course_id' => $course->id, 'course_code_snapshot' => $course->code, 'course_title_snapshot' => $course->title,
        'delivery_mode_snapshot' => DeliveryMode::InternalClassroom, 'target_department_entity_id' => null,
        'organizer_employee_entity_id' => $f['o1']->id, 'starts_at' => Carbon::parse($endsAt)->subHours(4), 'ends_at' => $endsAt,
        'capacity' => 10, 'status' => TrainingEventStatus::Completed,
    ]);
}

/** An enrolled participant with one current attendance fact. */
function tkpiAttendee(array $f, Employee $employee, AttendanceStatus $attendance = AttendanceStatus::Present, ?TrainingEvent $event = null, int $minutes = 240, ?string $certificate = null): TrainingParticipant
{
    $event ??= $f['event'];
    $companyId = (int) $event->company_entity_id;
    $participant = TrainingParticipant::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $companyId, 'event_id' => $event->id,
        'provider_id' => 'native', 'employee_subject_id' => (string) $employee->id, 'workforce_observed_at' => now(),
    ]);
    $session = TrainingSession::query()->firstOrCreate(
        ['tenant_id' => $f['tenantId'], 'company_entity_id' => $companyId, 'event_id' => $event->id, 'session_reference' => 'tkpi-'.$event->id],
        ['starts_at' => $event->starts_at, 'ends_at' => $event->ends_at, 'created_by_user_id' => $f['hr']->id],
    );
    TrainingParticipationFact::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $companyId, 'event_id' => $event->id,
        'participant_id' => $participant->id, 'session_id' => $session->id, 'attendance' => $attendance,
        'actual_minutes' => $minutes, 'certificate_reference' => $certificate, 'evidence_references' => [],
        'source' => 'fixture', 'source_reference' => 'tkpi-'.$participant->id,
        'recorded_by_user_id' => $f['hr']->id, 'recorded_capability' => 'fixture', 'recorded_at' => $event->ends_at,
    ]);

    return $participant;
}

function tkpiEvaluation(array $f, TrainingParticipant $participant, TrainingEvaluationStatus $status, int $rating, string $dueOn = '2026-08-15'): TrainingEvaluation
{
    return TrainingEvaluation::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $participant->company_entity_id,
        'event_id' => $participant->event_id, 'participant_id' => $participant->id,
        'employee_subject_id' => $participant->employee_subject_id, 'criteria_version' => '0012-a.v1',
        'relevance' => $rating, 'trainer_effectiveness' => $rating, 'materials_exercises' => $rating,
        'pace_duration' => $rating, 'practical_usefulness' => $rating,
        'status' => $status, 'due_on' => $dueOn, 'entry_source' => 'self',
        'completed_at' => $status === TrainingEvaluationStatus::Completed ? '2026-08-10 09:00:00' : null,
    ]);
}

/** @param array<string, mixed> $extra */
function tkpiReview(array $f, TrainingParticipant $participant, EffectivenessReviewState $state, ?EffectivenessOutcome $outcome = null, array $extra = []): TrainingEffectivenessReview
{
    return TrainingEffectivenessReview::query()->create(array_replace([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $participant->company_entity_id,
        'training_participant_id' => $participant->id, 'stage' => EffectivenessReviewStage::Day30,
        'due_on' => '2026-08-31', 'due_date_policy' => 'policy:0013 thirty days after the event',
        // The reviewer is never the reviewed participant: the schema refuses that row.
        'reviewer_employee_entity_id' => (string) $f['q1']->id === (string) $participant->employee_subject_id ? $f['o1']->id : $f['q1']->id,
        'state' => $state, 'outcome' => $outcome,
        'closed_at' => $state === EffectivenessReviewState::Closed ? '2026-09-01 09:00:00' : null,
    ], $extra));
}

function tkpiRequest(array $f, Employee $requestor, PeopleReferenceEntry $unit, TrainingRequestStatus $status, ?int $eventId = null): TrainingRequest
{
    return TrainingRequest::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $unit->company_id, 'request_key' => (string) Str::uuid(),
        'requestor_provider_id' => 'native', 'requestor_subject_id' => (string) $requestor->id,
        'department_provider_id' => 'native', 'department_subject_id' => (string) $unit->id,
        'need_source' => TrainingNeedSource::LegalCertification, 'need' => 'Need.', 'learning_objective' => 'Objective.',
        'expected_result' => 'Result.', 'priority' => TrainingPriority::Medium, 'status' => $status,
        'training_event_id' => $eventId, 'created_by_user_id' => $f['hr']->id,
    ]);
}

function tkpiAction(array $f, Employee $employee, DevelopmentActionClosure $closure): DevelopmentAction
{
    return DevelopmentAction::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'], 'action_key' => (string) Str::uuid(),
        'employee_entity_id' => $employee->id, 'skill_id' => $f['skillId'],
        'employee_name_snapshot' => $employee->full_name, 'starting_level' => 1, 'target_level' => 3, 'gap_at_start' => 2,
        'criticality' => 'critical', 'mandatory_gate' => true, 'priority_score' => 600, 'priority_explanation' => 'Fixture.',
        'action_type' => 'coaching', 'objective' => 'Close the gap.', 'intervention' => 'Coach.', 'expected_evidence' => 'Observed.',
        'owner_employee_entity_id' => $f['q1']->id, 'hr_coordinator_employee_entity_id' => $f['q1']->id,
        'start_date' => '2026-09-01', 'due_date' => '2026-10-01',
        'status' => $closure === DevelopmentActionClosure::ClosedCompetent ? 'completed' : 'in_progress', 'closure_status' => $closure,
    ]);
}

function tkpiSummary(array $f, string $asOf = '2026-09-07'): TrainingKpiSummaryResult
{
    return app(TrainingKpiSummary::class)->forCompany($f['tenantId'], $f['companyId'], new DateTimeImmutable($asOf));
}

/** @return array<string, int|float|null> */
function tkpiValues(array $metrics): array
{
    return array_map(static fn (array $metric): int|float|null => $metric['value'], $metrics);
}

function tkpiDepartment(TrainingKpiSummaryResult $summary, PeopleReferenceEntry $unit): array
{
    return $summary->department((int) $unit->id)['metrics'];
}

function tkpiPage(array $f, User $actor)
{
    return Livewire::actingAs($actor)->test(Index::class);
}

// ─── Requests ────────────────────────────────────────────────────────────────

test('with two approved requests, one linked, approved-not-linked is 1 and its drill reproduces the register approved_unlinked filter', function (): void {
    $f = tkpiFixture();
    tkpiRequest($f, $f['o1'], $f['ops'], TrainingRequestStatus::Approved, (int) $f['event']->id);
    $unlinked = tkpiRequest($f, $f['o2'], $f['ops'], TrainingRequestStatus::Approved);
    tkpiRequest($f, $f['q1'], $f['qa'], TrainingRequestStatus::PendingHr);
    tkpiRequest($f, $f['q1'], $f['qa'], TrainingRequestStatus::Rejected);
    $company = tkpiSummary($f)->company;

    // Guard: the linked approved request is not "approved, not linked".
    expect($company['approved_not_linked']['value'])->toBe(1)
        ->and($company['pending_requests']['value'])->toBe(1)
        ->and($company['approved_not_linked']['drill'])->toBe(['route' => 'people.training.requests.register', 'params' => ['status' => Register::FILTER_APPROVED_UNLINKED]])
        ->and($company['approved_not_linked']['definition'])->toContain('no scheduled training event satisfies yet')
        ->and($company['approved_not_linked']['as_of'])->toBeInstanceOf(DateTimeImmutable::class);

    $register = Livewire::withQueryParams($company['approved_not_linked']['drill']['params'])->actingAs($f['hr'])->test(Register::class)->assertOk();

    // Guard: the register under the drill's filter lists exactly the counted row.
    expect($register->viewData('rows')->pluck('id')->all())->toBe([(int) $unlinked->id]);

    $ops = tkpiDepartment(tkpiSummary($f), $f['ops']);
    expect($ops['requests']['value'])->toBe(2)->and($ops['approved']['value'])->toBe(2)
        ->and($ops['requests']['drill']['params'])->toBe(['department' => (string) $f['ops']->id]);

    // Guard: the pending drill names the three pending statuses, and the register honours a status list.
    $pending = Livewire::withQueryParams($company['pending_requests']['drill']['params'])->actingAs($f['hr'])->test(Register::class)->assertOk();
    expect($pending->viewData('rows')->pluck('status')->all())->toBe(['pending_hr']);
});

// ─── Evaluations ─────────────────────────────────────────────────────────────

test('pending evaluations counts a fact whose evaluation is due and not completed as of the date, and a day before the due date drops it to 0', function (): void {
    $f = tkpiFixture();
    $due = tkpiAttendee($f, $f['o1']);
    $done = tkpiAttendee($f, $f['o2']);
    tkpiEvaluation($f, $done, TrainingEvaluationStatus::Completed, 4);
    // Stored with a time part on a date column: SQLite keeps the text, so a
    // bare string predicate against '2026-08-15' would miss it.
    tkpiEvaluation($f, $due, TrainingEvaluationStatus::Draft, 3, '2026-08-15 00:30:00');
    expect(substr((string) DB::table('people_training_evaluations')->where('participant_id', $due->id)->value('due_on'), 0, 10))->toBe('2026-08-15');

    // Guard: due on the as-of date and not completed counts; the completed one does not.
    expect(tkpiSummary($f, '2026-08-15')->company['pending_evaluations']['value'])->toBe(1);

    // Guard: a day before the due date, nothing is pending.
    expect(tkpiSummary($f, '2026-08-14')->company['pending_evaluations']['value'])->toBe(0);

    $page = tkpiPage($f, $f['hr'])->assertOk()->assertSet('asOf', '2026-09-07');
    expect($page->viewData('summary')->company['pending_evaluations']['value'])->toBe(1);
    $page->call('setAsOf', '2026-08-14')->assertSet('asOf', '2026-08-14');
    expect($page->viewData('summary')->company['pending_evaluations']['value'])->toBe(0)
        ->and($page->viewData('summary')->asOf->format('Y-m-d'))->toBe('2026-08-14');

    // Guard: the picker's event reaches the page; a future date is refused and leaves the as-of alone.
    $page->dispatch('standing-as-of-changed', '2026-08-15')->assertSet('asOf', '2026-08-15');
    $page->call('setAsOf', '2026-09-08')->assertSet('asOf', '2026-08-15');
});

test('avg evaluation and evaluation completion are null, not 0, for a department with no completed evaluation', function (): void {
    $f = tkpiFixture();
    $o1 = tkpiAttendee($f, $f['o1']);
    tkpiAttendee($f, $f['o2']);
    tkpiEvaluation($f, $o1, TrainingEvaluationStatus::Completed, 4);
    tkpiRequest($f, $f['q1'], $f['qa'], TrainingRequestStatus::PendingHod);
    $summary = tkpiSummary($f);
    $ops = tkpiDepartment($summary, $f['ops']);
    $qa = tkpiDepartment($summary, $f['qa']);

    // Guard: Quality raised a request but nobody attended, so both rates are null rather than 0.
    expect($qa['attended']['value'])->toBe(0)
        ->and($qa['evaluation_completion']['value'])->toBeNull()
        ->and($qa['avg_evaluation']['value'])->toBeNull()
        ->and($ops['attended']['value'])->toBe(2)
        ->and($ops['evaluation_completion']['value'])->toBe(0.5)
        ->and($ops['avg_evaluation']['value'])->toBe(4.0)
        ->and($ops['training_hours']['value'])->toBe(8.0);

    $html = tkpiPage($f, $f['hr'])->assertOk()->html();
    expect($html)->toContain('data-kpi="avg_evaluation" data-kpi-value="null"')->toContain('n/a');
});

// ─── Effectiveness ───────────────────────────────────────────────────────────

test('effective % is closed-effective over closed reviews per department and open follow-up matches the effectiveness aggregate', function (): void {
    $f = tkpiFixture();
    $o1 = tkpiAttendee($f, $f['o1']);
    $o2 = tkpiAttendee($f, $f['o2'], certificate: 'CERT-1');
    $q1 = tkpiAttendee($f, $f['q1']);
    tkpiReview($f, $o1, EffectivenessReviewState::Closed, EffectivenessOutcome::Effective, ['baseline_level' => 2, 'post_level' => 3]);
    tkpiReview($f, $o2, EffectivenessReviewState::Closed, EffectivenessOutcome::NotYetEffective, ['development_action_id' => tkpiAction($f, $f['o2'], DevelopmentActionClosure::Open)->id]);
    tkpiReview($f, $o2, EffectivenessReviewState::Closed, EffectivenessOutcome::Effective, ['development_action_id' => tkpiAction($f, $f['o2'], DevelopmentActionClosure::ClosedCompetent)->id]);
    tkpiReview($f, $o1, EffectivenessReviewState::OutcomeRecorded, EffectivenessOutcome::Effective);
    tkpiReview($f, $q1, EffectivenessReviewState::Open);
    $summary = tkpiSummary($f);
    $ops = tkpiDepartment($summary, $f['ops']);
    $qa = tkpiDepartment($summary, $f['qa']);

    // Guard: two of three closed Operations reviews are effective; an outcome that is not closed is not counted.
    expect($ops['effectiveness_reviews']['value'])->toBe(4)
        ->and($ops['effective_pct']['value'])->toEqualWithDelta(2 / 3, 0.000001)
        ->and($ops['skill_improvement']['value'])->toBe(1)
        ->and($ops['certificates']['value'])->toBe(1)
        ->and($ops['open_followup']['value'])->toBe(1)
        ->and($qa['effectiveness_reviews']['value'])->toBe(1)
        ->and($qa['effective_pct']['value'])->toBeNull()
        ->and($summary->company['closed_effective']['value'])->toBe(2)
        ->and($summary->company['overdue_effectiveness']['value'])->toBe(3);

    $perCourse = app(TrainingEffectivenessAggregate::class)->perCourse($f['tenantId'], $f['companyId']);
    $openFollowUps = array_sum(array_map(static fn (object $row): int => count($row->openFollowUpActionIds), $perCourse));

    // Guard: the same fixtures yield the same open follow-up count from the aggregate.
    expect($openFollowUps)->toBe($ops['open_followup']['value'] + $qa['open_followup']['value']);
});

// ─── Scope ───────────────────────────────────────────────────────────────────

test('sibling company B and another tenant never enter company A counts', function (): void {
    $f = tkpiFixture();
    tkpiRequest($f, $f['o1'], $f['ops'], TrainingRequestStatus::Approved);
    $o1 = tkpiAttendee($f, $f['o1']);
    tkpiEvaluation($f, $o1, TrainingEvaluationStatus::Completed, 5);
    tkpiReview($f, $o1, EffectivenessReviewState::Closed, EffectivenessOutcome::Effective);

    $betaEvent = tkpiEvent($f, '2026-08-01 12:00:00', $f['betaId']);
    tkpiRequest($f, $f['b1'], $f['betaUnit'], TrainingRequestStatus::Approved);
    tkpiRequest($f, $f['b1'], $f['betaUnit'], TrainingRequestStatus::PendingHr);
    $b1 = tkpiAttendee($f, $f['b1'], event: $betaEvent);
    tkpiEvaluation($f, $b1, TrainingEvaluationStatus::Completed, 1);
    tkpiReview($f, $b1, EffectivenessReviewState::Closed, EffectivenessOutcome::NotYetEffective);

    [$otherTenant, $otherCompany] = createTenantWithCompany(['name' => 'TKPI Other Tenant'], ['name' => 'TKPI Other Co', 'status' => 'active']);
    DB::table('people_training_requests')->insert([
        'tenant_id' => $otherTenant->id, 'company_entity_id' => $otherCompany->id, 'request_key' => (string) Str::uuid(),
        'requestor_provider_id' => 'native', 'requestor_subject_id' => '1', 'department_provider_id' => 'native', 'department_subject_id' => (string) $f['ops']->id,
        'need_source' => 'legal_certification', 'need' => 'Other.', 'learning_objective' => 'Other.', 'expected_result' => 'Other.',
        'priority' => 'medium', 'status' => 'approved', 'created_by_user_id' => $f['hr']->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $company = tkpiSummary($f)->company;

    // Guard: every read is pinned to tenant and company.
    expect(tkpiValues($company))->toMatchArray(['approved_not_linked' => 1, 'pending_requests' => 0, 'attended' => 1, 'closed_effective' => 1]);
    expect(tkpiValues(tkpiDepartment(tkpiSummary($f), $f['ops'])))->toMatchArray(['requests' => 1, 'attended' => 1, 'avg_evaluation' => 5.0, 'effective_pct' => 1.0]);

    $sibling = tkpiPage($f, $f['siblingHr'])->assertOk()->assertSet('companyEntityId', $f['betaId']);
    expect(tkpiValues($sibling->viewData('summary')->company))->toMatchArray(['approved_not_linked' => 1, 'pending_requests' => 1, 'attended' => 1, 'closed_effective' => 0]);
});

test('a HOD is refused at mount and HR of company A cannot select company B', function (): void {
    $f = tkpiFixture();

    $this->actingAs($f['hr'])->get(route('people.training.kpi'))->assertOk();

    // Guard: the route's authz middleware and the HR audience check in mount.
    $this->actingAs($f['hod'])->get(route('people.training.kpi'))->assertForbidden();
    Livewire::actingAs($f['hod'])->test(Index::class)->assertForbidden();

    $page = tkpiPage($f, $f['hr'])->assertOk()->assertSet('companyEntityId', $f['companyId']);
    expect($page->viewData('companies'))->not->toHaveKey($f['betaId']);

    // Guard: selectCompany refuses a company outside the actor's attribution.
    $page->call('selectCompany', $f['betaId'])->assertNotFound();
    tkpiPage($f, $f['hr'])->call('selectCompany', $f['companyId'])->assertOk()->assertSet('companyEntityId', $f['companyId']);
});

// ─── Page ────────────────────────────────────────────────────────────────────

test('every rendered table has a caption and each cell carries definition, as-of and a drill link', function (): void {
    $f = tkpiFixture();
    tkpiRequest($f, $f['o1'], $f['ops'], TrainingRequestStatus::Approved);
    tkpiAttendee($f, $f['o1']);
    $before = [DB::table('people_training_requests')->count(), DB::table('people_training_participation_facts')->count(), DB::table('people_training_evaluations')->count()];
    $page = tkpiPage($f, $f['hr'])->assertOk();
    $html = $page->html();

    // Guard: every <x-ui.table> renders a <caption>.
    expect(substr_count($html, '<table'))->toBe(2)
        ->and(substr_count($html, '<caption'))->toBe(2)
        ->and($html)->toContain('Company training controls')->toContain('Training KPIs by department')
        ->toContain('As of 2026-09-07')
        ->toContain('data-kpi="approved_not_linked" data-kpi-value="1"')
        ->toContain('href="'.e(route('people.training.requests.register', ['status' => Register::FILTER_APPROVED_UNLINKED])).'"')
        ->toContain('href="'.e(route('people.training.evaluations.index', ['department' => $f['ops']->id])).'"')
        ->toContain(e(TrainingKpiSummaryResult::COMPANY['approved_not_linked'][1]));

    // Guard: the page is read-only.
    $page->call('selectCompany', $f['companyId'])->call('setAsOf', '2026-09-01');
    expect([DB::table('people_training_requests')->count(), DB::table('people_training_participation_facts')->count(), DB::table('people_training_evaluations')->count()])->toBe($before);
});
