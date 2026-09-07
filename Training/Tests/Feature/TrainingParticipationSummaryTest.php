<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Training\Contracts\SummarizesTrainingParticipation;
use App\Domains\People\Training\Data\LearningTestResult;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Data\TrainingParticipationSummary;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Livewire\Event\Index;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use App\Domains\People\Training\Models\TrainingSession;
use App\Domains\People\Training\Services\DatabaseTrainingParticipationSummary;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use App\Domains\People\Training\Services\UnavailableTrainingParticipationSummary;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Event participation counts from participant records (0011-e). Self-contained:
 * helpers are prefixed partSummary.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

/** One company side: HR user, a unit, an organiser employee, a course and one scheduled event. */
function partSummarySide(int $tenantId, Company $company, string $label): array
{
    $hr = User::factory()->create(['company_id' => $company->id, 'name' => $label.' HR']);
    PrincipalRole::query()->create([
        'company_id' => $company->id, 'principal_type' => PrincipalType::USER->value, 'principal_id' => $hr->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', 'people_hr')->sole()->id,
    ]);
    $unit = PeopleReferenceEntry::query()->create(['company_id' => $company->id, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT, 'code' => 'OPS-'.$label, 'name' => 'Operations '.$label, 'status' => PeopleReferenceEntry::STATUS_ACTIVE]);
    $organizer = Employee::factory()->create(['company_id' => $company->id, 'full_name' => $label.' Organiser', 'short_name' => null, 'supervisor_id' => null, 'status' => 'active', 'employee_type' => 'full_time']);
    $category = app(SkillCatalogStore::class)->defineCategory((int) $company->id, 'safety', 'Safety');
    $skill = app(SkillCatalogStore::class)->defineSkill((int) $company->id, new SkillDraft(code: 'isolation.energy', name: 'Energy isolation', definition: 'Isolate.', categoryId: (int) $category->id, defaultAssessmentMethod: AssessmentMethod::DirectObservation));
    $course = app(TrainingCatalogStore::class)->defineCourse((int) $company->id, new TrainingCourseDraft(code: 'isolation.induction', title: 'Isolation induction '.$label, deliveryMode: DeliveryMode::InternalClassroom, skillIds: [(int) $skill->id], internalTrainerEmployeeEntityId: (int) $organizer->id));

    return ['company' => $company, 'tenantId' => $tenantId, 'hr' => $hr, 'unit' => $unit, 'organizer' => $organizer, 'course' => $course, 'event' => partSummaryEvent($tenantId, $company, $organizer, $course, $unit)];
}

function partSummaryEvent(int $tenantId, Company $company, Employee $organizer, $course, PeopleReferenceEntry $unit, int $daysAhead = 5): int
{
    return (int) app(TrainingEventStore::class)->schedule((int) $company->id, new TrainingEventDraft(
        courseId: (int) $course->id, startsAt: now()->addDays($daysAhead), endsAt: now()->addDays($daysAhead + 1), capacity: 10,
        organizerEmployeeEntityId: (int) $organizer->id, targetDepartmentEntityId: (int) $unit->id,
    ))->id;
}

/** @return array{tenantId: int, alpha: array, beta: array} */
function partSummaryFixture(): array
{
    $tenant = createTenant(['name' => 'Participation Summary Tenant']);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();
    $alpha = Company::factory()->create(['tenant_id' => $tenantId, 'name' => 'Alpha Summary', 'status' => 'active']);
    $beta = Company::factory()->create(['tenant_id' => $tenantId, 'name' => 'Beta Summary', 'status' => 'active']);

    return ['tenantId' => $tenantId, 'alpha' => partSummarySide($tenantId, $alpha, 'Alpha'), 'beta' => partSummarySide($tenantId, $beta, 'Beta')];
}

function partSummaryParticipant(array $s, int $eventId, string $name, bool $withdrawn = false): TrainingParticipant
{
    $employee = Employee::factory()->create(['company_id' => $s['company']->id, 'full_name' => $name, 'short_name' => null, 'supervisor_id' => null, 'status' => 'active', 'employee_type' => 'full_time']);

    return TrainingParticipant::query()->create([
        'tenant_id' => $s['tenantId'], 'company_entity_id' => $s['company']->id, 'event_id' => $eventId,
        'provider_id' => 'native', 'employee_subject_id' => (string) $employee->id, 'workforce_observed_at' => now(),
        'withdrawn_at' => $withdrawn ? now() : null,
    ]);
}

function partSummaryFact(array $s, TrainingParticipant $participant, AttendanceStatus $attendance, ?LearningTestResult $postTest = null, int $minutesAgo = 0, string $session = 'a'): TrainingParticipationFact
{
    $event = TrainingEvent::query()->forCompany($s['tenantId'], (int) $s['company']->id)->findOrFail($participant->event_id);
    $session = TrainingSession::query()->firstOrCreate(
        ['tenant_id' => $s['tenantId'], 'company_entity_id' => $s['company']->id, 'event_id' => $participant->event_id, 'session_reference' => 'summary-session-'.$participant->event_id.'-'.$session],
        ['starts_at' => $event->starts_at, 'ends_at' => $event->ends_at, 'created_by_user_id' => $s['hr']->id],
    );

    return TrainingParticipationFact::query()->create([
        'tenant_id' => $s['tenantId'], 'company_entity_id' => $s['company']->id, 'event_id' => $participant->event_id,
        'participant_id' => $participant->id, 'session_id' => $session->id, 'attendance' => $attendance, 'actual_minutes' => 120,
        'post_test' => $postTest?->toArray(), 'evidence_references' => [], 'source' => 'fixture', 'source_reference' => 'summary-fact-'.$participant->id.'-'.$minutesAgo,
        'recorded_by_user_id' => $s['hr']->id, 'recorded_capability' => 'fixture', 'recorded_at' => now()->subMinutes($minutesAgo),
    ]);
}

function partSummaryCounts(): array
{
    return [TrainingParticipant::query()->withoutGlobalScopes()->count(), TrainingParticipationFact::query()->withoutGlobalScopes()->count()];
}

test('three enrolled, two present, post-tests 80 and 40 of 100 with pass mark 60 give 3/2/2/1 and a 50.0 pass rate', function (): void {
    $f = partSummaryFixture();
    $a = $f['alpha'];
    $p1 = partSummaryParticipant($a, $a['event'], 'Passer');
    $p2 = partSummaryParticipant($a, $a['event'], 'Failer');
    partSummaryParticipant($a, $a['event'], 'Absentee');
    partSummaryFact($a, $p1, AttendanceStatus::Present, new LearningTestResult(true, 80, 100, 60));
    partSummaryFact($a, $p2, AttendanceStatus::Present, new LearningTestResult(true, 40, 100, 60));
    $before = partSummaryCounts();

    $summary = app(DatabaseTrainingParticipationSummary::class)->forEvents((int) $a['company']->id, [$a['event']])[$a['event']];
    expect([$summary->enrolled, $summary->attended, $summary->completed, $summary->passed])->toBe([3, 2, 2, 1])
        ->and($summary->passRate())->toBe(50.0)
        ->and(partSummaryCounts())->toBe($before);

    $page = Livewire::actingAs($a['hr'])->test(Index::class);
    $page->assertSee('3 enrolled · 2 attended · 2 completed · 1 passed · pass rate 50.0%')->assertSee('as of ');
});

test('enrolled participants with no facts give zero attended and a null pass rate shown as n/a, never 0%', function (): void {
    $f = partSummaryFixture();
    $a = $f['alpha'];
    partSummaryParticipant($a, $a['event'], 'One');
    partSummaryParticipant($a, $a['event'], 'Two');

    $summary = app(SummarizesTrainingParticipation::class)->forEvents((int) $a['company']->id, [$a['event']])[$a['event']];
    expect([$summary->enrolled, $summary->attended, $summary->completed, $summary->passed])->toBe([2, 0, 0, 0])
        ->and($summary->passRate())->toBeNull()
        ->and($summary->isAvailable())->toBeTrue();

    $page = Livewire::actingAs($a['hr'])->test(Index::class);
    $page->assertSee('2 enrolled · 0 attended · 0 completed · 0 passed · pass rate n/a')->assertDontSee('0%')->assertDontSee('0.0%');
});

test('a withdrawn participant is not enrolled, a not-applicable post-test is attended but not completed, and the latest fact decides', function (): void {
    $f = partSummaryFixture();
    $a = $f['alpha'];
    partSummaryParticipant($a, $a['event'], 'Withdrawn', withdrawn: true);
    $notApplicable = partSummaryParticipant($a, $a['event'], 'No Test');
    partSummaryFact($a, $notApplicable, AttendanceStatus::Present, new LearningTestResult(false));
    $absentThenPresent = partSummaryParticipant($a, $a['event'], 'Late Present');
    partSummaryFact($a, $absentThenPresent, AttendanceStatus::Absent, null, minutesAgo: 30);
    partSummaryFact($a, $absentThenPresent, AttendanceStatus::Present, new LearningTestResult(true, 90, 100, 60), session: 'b');
    $missing = partSummaryParticipant($a, $a['event'], 'Missing Test');
    partSummaryFact($a, $missing, AttendanceStatus::Present, null);
    // Absent with nothing else: enrolled, never attended.
    partSummaryFact($a, partSummaryParticipant($a, $a['event'], 'Absent Only'), AttendanceStatus::Absent, null);
    // An applicable post-test that was never scored: attended, not completed.
    partSummaryFact($a, partSummaryParticipant($a, $a['event'], 'Unscored'), AttendanceStatus::Present, new LearningTestResult(true));

    $summary = app(SummarizesTrainingParticipation::class)->forEvents((int) $a['company']->id, [$a['event']])[$a['event']];
    expect([$summary->enrolled, $summary->attended, $summary->completed, $summary->passed])->toBe([5, 4, 1, 1])
        ->and($summary->passRate())->toBe(100.0);
});

test('two events are summarised in a fixed number of queries, keyed by event, an empty own event is zeros, and an event the company does not own gets no key', function (): void {
    $f = partSummaryFixture();
    $a = $f['alpha'];
    $second = partSummaryEvent($a['tenantId'], $a['company'], $a['organizer'], $a['course'], $a['unit'], 12);
    $empty = partSummaryEvent($a['tenantId'], $a['company'], $a['organizer'], $a['course'], $a['unit'], 20);
    partSummaryFact($a, partSummaryParticipant($a, $a['event'], 'E1 One'), AttendanceStatus::Present, new LearningTestResult(true, 70, 100, 60));
    partSummaryFact($a, partSummaryParticipant($a, $second, 'E2 One'), AttendanceStatus::Present, null);
    partSummaryParticipant($a, $second, 'E2 Two');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $summaries = app(SummarizesTrainingParticipation::class)->forEvents((int) $a['company']->id, [$a['event'], $second, $empty, $f['beta']['event'], 999999]);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(3)
        ->and(array_keys($summaries))->toBe([$a['event'], $second, $empty])
        ->and([$summaries[$second]->enrolled, $summaries[$second]->attended, $summaries[$second]->completed])->toBe([2, 1, 0])
        ->and([$summaries[$empty]->enrolled, $summaries[$empty]->attended, $summaries[$empty]->completed, $summaries[$empty]->passed])->toBe([0, 0, 0, 0])
        ->and($summaries[$empty]->isAvailable())->toBeTrue()
        ->and($summaries[$empty]->passRate())->toBeNull();
});

test('participants of the sibling company are not counted, and the sibling event is not summarised for this company', function (): void {
    $f = partSummaryFixture();
    $a = $f['alpha'];
    $b = $f['beta'];
    partSummaryFact($a, partSummaryParticipant($a, $a['event'], 'Alpha One'), AttendanceStatus::Present, new LearningTestResult(true, 80, 100, 60));
    partSummaryFact($b, partSummaryParticipant($b, $b['event'], 'Beta One'), AttendanceStatus::Present, new LearningTestResult(true, 80, 100, 60));
    partSummaryFact($b, partSummaryParticipant($b, $b['event'], 'Beta Two'), AttendanceStatus::Present, new LearningTestResult(true, 80, 100, 60));

    $alpha = app(SummarizesTrainingParticipation::class)->forEvents((int) $a['company']->id, [$a['event'], $b['event']]);
    expect(array_keys($alpha))->toBe([$a['event']])
        ->and([$alpha[$a['event']]->enrolled, $alpha[$a['event']]->passed])->toBe([1, 1]);
    $beta = app(SummarizesTrainingParticipation::class)->forEvents((int) $b['company']->id, [$b['event']]);
    expect($beta[$b['event']]->enrolled)->toBe(2);
});

test('the provider-outage path reports unavailable, never zero', function (): void {
    $f = partSummaryFixture();
    $a = $f['alpha'];
    partSummaryFact($a, partSummaryParticipant($a, $a['event'], 'Someone'), AttendanceStatus::Present, null);
    app()->singleton(SummarizesTrainingParticipation::class, UnavailableTrainingParticipationSummary::class);

    $summary = app(SummarizesTrainingParticipation::class)->forEvents((int) $a['company']->id, [$a['event']])[$a['event']] ?? TrainingParticipationSummary::unavailable();
    expect($summary->isAvailable())->toBeFalse();
    Livewire::actingAs($a['hr'])->test(Index::class)->assertSee('Participation unavailable')->assertDontSee('0 enrolled');
});
