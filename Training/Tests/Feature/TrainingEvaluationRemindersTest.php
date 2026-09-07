<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Enums\TrainingEvaluationStatus;
use App\Domains\People\Training\Models\TrainingEvaluation;
use App\Domains\People\Training\Models\TrainingEvaluationReminder;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use App\Domains\People\Training\Models\TrainingSession;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * 0012-d: participants are reminded three days before their evaluation is
 * due and every day it stays overdue, once per participant per day.
 *
 * The unit is the attended participant, not the evaluation row. Nothing in
 * the platform writes a draft row ahead of submission, so a rule that only
 * read rows would never fire; the due date of a participant without a row is
 * the same clock submit() uses, fourteen days after the event ends.
 *
 * Self-contained: helpers are prefixed evdue and live here.
 */
afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

/**
 * A course a company can schedule, with its trainer.
 *
 * @return array{course: int, trainer: Employee}
 */
function evdueCourse(int $companyId, string $label): array
{
    $trainer = Employee::factory()->create([
        'company_id' => $companyId, 'full_name' => 'Trainer '.$label,
        'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory($companyId, 'safety', 'Safety');
    $skill = $catalog->defineSkill($companyId, new SkillDraft(
        code: 'isolation.energy', name: 'Energy isolation',
        definition: 'Isolate stored energy.', categoryId: (int) $category->id,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse($companyId, new TrainingCourseDraft(
        code: 'isolation.induction', title: 'Isolation induction',
        deliveryMode: DeliveryMode::InternalClassroom, skillIds: [(int) $skill->id],
        internalTrainerEmployeeEntityId: (int) $trainer->id,
    ));

    return ['course' => (int) $course->id, 'trainer' => $trainer];
}

/** @return array<string, mixed> */
function evdueFixture(string $label = 'Evdue'): array
{
    [$tenant, $company] = createTenantWithCompany(
        ['name' => $label.' Tenant'],
        ['name' => $label.' Company', 'status' => 'active'],
    );
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $recorder = User::factory()->create(['company_id' => $companyId]);
    $kit = evdueCourse($companyId, $label);
    $eventId = evdueEvent($companyId, $kit);

    return compact('tenantId', 'companyId', 'recorder', 'eventId');
}

/** @param  array{course: int, trainer: Employee}  $kit */
function evdueEvent(int $companyId, array $kit): int
{
    // Scheduled ahead because the store refuses an event that ends in the
    // past. Tests that need the event clock travel forward from ends_at.
    return (int) app(TrainingEventStore::class)->schedule($companyId, new TrainingEventDraft(
        courseId: $kit['course'],
        startsAt: now()->addDays(2),
        endsAt: now()->addDays(3),
        capacity: 10,
        organizerEmployeeEntityId: (int) $kit['trainer']->id,
    ))->id;
}

/** An attended participant of the fixture's event, in the company given. */
function evdueParticipant(array $f, string $name, ?int $companyId = null, ?int $eventId = null): TrainingParticipant
{
    $companyId ??= $f['companyId'];
    $eventId ??= $f['eventId'];
    $employee = Employee::factory()->create([
        'company_id' => $companyId, 'full_name' => $name, 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $participant = TrainingParticipant::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $companyId, 'event_id' => $eventId,
        'provider_id' => 'native', 'employee_subject_id' => (string) $employee->id,
        'workforce_observed_at' => now(),
    ]);
    $event = TrainingEvent::query()->forCompany($f['tenantId'], $companyId)->findOrFail($eventId);
    $session = TrainingSession::query()->firstOrCreate([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $companyId, 'event_id' => $eventId,
        'session_reference' => 'evdue-session-'.$eventId,
    ], [
        'starts_at' => $event->starts_at, 'ends_at' => $event->ends_at,
        'created_by_user_id' => $f['recorder']->id,
    ]);
    TrainingParticipationFact::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $companyId,
        'event_id' => $eventId, 'participant_id' => $participant->id, 'session_id' => $session->id,
        'attendance' => AttendanceStatus::Present,
        'actual_minutes' => 120, 'evidence_references' => [],
        'source' => 'fixture', 'source_reference' => 'evdue-fact-'.$participant->id,
        'recorded_by_user_id' => $f['recorder']->id, 'recorded_capability' => 'fixture', 'recorded_at' => now(),
    ]);

    return $participant;
}

function evdueEvaluation(array $f, TrainingParticipant $participant, TrainingEvaluationStatus $status, string $dueOn): TrainingEvaluation
{
    return TrainingEvaluation::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => (int) $participant->company_entity_id,
        'event_id' => (int) $participant->event_id, 'participant_id' => $participant->id,
        'employee_subject_id' => $participant->employee_subject_id,
        'criteria_version' => '0012-a.v1',
        'status' => $status,
        'due_on' => $dueOn,
        'completed_at' => $status === TrainingEvaluationStatus::Completed ? now() : null,
        'entry_source' => 'self',
    ]);
}

function evdueRun(array $f, array $options = []): int
{
    return Artisan::call('people:training:evaluations-due', array_replace([
        '--tenant' => $f['tenantId'],
        '--company' => $f['companyId'],
    ], $options));
}

function evdueRows(array $f, ?int $companyId = null): int
{
    return TrainingEvaluationReminder::query()->forCompany($f['tenantId'], $companyId ?? $f['companyId'])->count();
}

test('an evaluation due in three days is reminded', function (): void {
    $f = evdueFixture();
    evdueEvaluation($f, evdueParticipant($f, 'Alice'), TrainingEvaluationStatus::Draft, today()->addDays(3)->toDateString());

    expect(evdueRun($f))->toBe(0)
        ->and(evdueRows($f))->toBe(1);
});

test('an evaluation due in four days is not reminded', function (): void {
    $f = evdueFixture();
    evdueEvaluation($f, evdueParticipant($f, 'Alice'), TrainingEvaluationStatus::Draft, today()->addDays(4)->toDateString());

    expect(evdueRun($f))->toBe(0)
        ->and(evdueRows($f))->toBe(0);
});

test('an overdue evaluation is reminded once per day, not twice on a rerun', function (): void {
    $f = evdueFixture();
    evdueEvaluation($f, evdueParticipant($f, 'Alice'), TrainingEvaluationStatus::Draft, today()->subDays(2)->toDateString());

    // Both runs must *succeed*: a rerun that fails on the unique key also
    // leaves one row behind, and that is not the same promise.
    expect(evdueRun($f))->toBe(0)
        ->and(evdueRun($f))->toBe(0)
        ->and(evdueRows($f))->toBe(1);

    Carbon::setTestNow(now()->addDay());

    expect(evdueRun($f))->toBe(0)
        ->and(evdueRows($f))->toBe(2);
});

test('a completed evaluation is never reminded; a draft is', function (): void {
    $f = evdueFixture();
    $done = evdueParticipant($f, 'Done');
    $draft = evdueParticipant($f, 'Drafting');
    evdueEvaluation($f, $done, TrainingEvaluationStatus::Completed, today()->subDay()->toDateString());
    evdueEvaluation($f, $draft, TrainingEvaluationStatus::Draft, today()->subDay()->toDateString());

    evdueRun($f);

    expect(TrainingEvaluationReminder::query()->forCompany($f['tenantId'], $f['companyId'])
        ->pluck('participant_id')->map(static fn (mixed $id): int => (int) $id)->all())
        ->toBe([(int) $draft->id]);
});

test('participants of another company in the same tenant are never reminded', function (): void {
    $f = evdueFixture();
    evdueEvaluation($f, evdueParticipant($f, 'Mine'), TrainingEvaluationStatus::Draft, today()->subDay()->toDateString());

    $other = Company::factory()->create(['tenant_id' => $f['tenantId'], 'name' => 'Sibling', 'status' => 'active']);
    $otherId = (int) $other->id;
    $theirEvent = evdueEvent($otherId, evdueCourse($otherId, 'Sibling'));
    evdueEvaluation($f, evdueParticipant($f, 'Theirs', $otherId, $theirEvent), TrainingEvaluationStatus::Draft, today()->subDay()->toDateString());

    expect(evdueRun($f))->toBe(0)
        ->and(evdueRows($f))->toBe(1)
        ->and(evdueRows($f, $otherId))->toBe(0);
});

test('a dry run reports without writing', function (): void {
    $f = evdueFixture();
    evdueEvaluation($f, evdueParticipant($f, 'Alice'), TrainingEvaluationStatus::Draft, today()->subDay()->toDateString());

    expect(evdueRun($f, ['--dry-run' => true]))->toBe(0)
        ->and(evdueRows($f))->toBe(0);
});

test('an attended participant with no evaluation row is due by the event clock', function (): void {
    $f = evdueFixture();
    evdueParticipant($f, 'Silent');
    $endsAt = TrainingEvent::query()->forCompany($f['tenantId'], $f['companyId'])->findOrFail($f['eventId'])->ends_at;

    // Eleven days after the event: the fourteen-day window closes in three.
    Carbon::setTestNow($endsAt->addDays(11));
    expect(evdueRun($f))->toBe(0)
        ->and(evdueRows($f))->toBe(1);
});

test('a participant whose attendance was corrected to absent is not reminded', function (): void {
    $f = evdueFixture();
    $participant = evdueParticipant($f, 'Alice');
    evdueEvaluation($f, $participant, TrainingEvaluationStatus::Draft, today()->addDays(3)->toDateString());

    // The sibling test above shows this same setup earns a reminder. HR then
    // appends a correction saying they were not there (0011-d): the original
    // still says Present and stays exactly as recorded.
    $original = TrainingParticipationFact::query()->forCompany($f['tenantId'], $f['companyId'])
        ->where('participant_id', $participant->id)->sole();
    TrainingParticipationFact::query()->forCompany($f['tenantId'], $f['companyId'])->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'],
        'event_id' => $original->event_id, 'participant_id' => $original->participant_id,
        'session_id' => $original->session_id,
        'attendance' => AttendanceStatus::Absent,
        'actual_minutes' => 0, 'evidence_references' => [],
        'source' => 'correction', 'source_reference' => 'fact:'.$original->id,
        'supersedes_fact_id' => (int) $original->id,
        'correction_reason' => 'Signed the sheet for a colleague.',
        'recorded_by_user_id' => $f['recorder']->id, 'recorded_capability' => 'fixture', 'recorded_at' => now(),
        'confirmed_by_user_id' => $f['recorder']->id, 'confirmed_capability' => 'fixture', 'confirmed_at' => now(),
    ]);

    // Nobody chases an evaluation for training somebody did not attend.
    expect(evdueRun($f))->toBe(0)
        ->and(evdueRows($f))->toBe(0);
});
