<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Company\Models\Department;
use App\Core\Company\Models\DepartmentType;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Services\SkillAudienceAssignmentStore;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Enums\TrainingEvaluationStatus;
use App\Domains\People\Training\Livewire\Evaluations\Index;
use App\Domains\People\Training\Models\TrainingEvaluation;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use App\Domains\People\Training\Models\TrainingSession;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use Livewire\Livewire;

/**
 * 0012-b: HR reads response rate, rating means and comments per event.
 *
 * The denominator is the thing to get right. A response rate over *all*
 * participants punishes an event for people who never turned up; over
 * *attended* participants it measures what it claims to. Every rate test below
 * is really a test of that denominator.
 *
 * Self-contained: helpers are prefixed dash and live here.
 *
 * @return array<string, mixed>
 */
function dashFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Dashboard Tenant'], ['name' => 'Dashboard Company', 'status' => 'active']);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $unit = PeopleReferenceEntry::query()->create([
        'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'OPS', 'name' => 'Operations', 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $type = DepartmentType::query()->create([
        'code' => 'ops-dash', 'name' => 'Operations', 'category' => 'operational', 'is_active' => true,
    ]);
    $department = Department::query()->create([
        'company_id' => $companyId, 'department_type_id' => $type->id, 'status' => 'active',
    ]);
    $head = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $department->id,
        'full_name' => 'Dashboard Head', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $department->update(['head_id' => $head->id]);
    EmployeeWorkProfile::query()->create(['employee_id' => $head->id, 'organization_unit_id' => $unit->id]);

    $hr = User::factory()->create(['company_id' => $companyId]);
    $hod = User::factory()->create(['company_id' => $companyId, 'employee_id' => $head->id]);
    $nobody = User::factory()->create(['company_id' => $companyId]);
    EmployeePortalAccess::query()->create([
        'employee_id' => $head->id, 'user_id' => $hod->id,
        'display_name' => 'Dashboard Head', 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    foreach ([[$hr, 'people_hr'], [$hod, 'people_hod']] as [$actor, $code]) {
        PrincipalRole::query()->create([
            'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value,
            'principal_id' => $actor->id,
            'role_id' => Role::query()->whereNull('company_id')->where('code', $code)->valueOrFail('id'),
        ]);
    }
    app(SkillAudienceAssignmentStore::class)->confirmActor($hr, $hod, $companyId, (int) $head->id, 'review:dashboard-hod');

    $category = app(SkillCatalogStore::class)->defineCategory($companyId, 'safety', 'Safety');
    $skill = app(SkillCatalogStore::class)->defineSkill($companyId, new SkillDraft(
        code: 'isolation.energy', name: 'Energy isolation',
        definition: 'Isolate stored energy.', categoryId: (int) $category->id,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse($companyId, new TrainingCourseDraft(
        code: 'isolation.induction', title: 'Isolation induction',
        deliveryMode: DeliveryMode::InternalClassroom, skillIds: [(int) $skill->id],
        internalTrainerEmployeeEntityId: (int) $head->id,
    ));

    return compact('tenantId', 'companyId', 'hr', 'hod', 'nobody', 'unit', 'department', 'head', 'course');
}

function dashEvent(array $f, ?int $companyId = null, ?int $courseId = null): int
{
    $companyId ??= $f['companyId'];

    return (int) app(TrainingEventStore::class)->schedule($companyId, new TrainingEventDraft(
        courseId: $courseId ?? (int) $f['course']->id,
        startsAt: now()->addDays(2),
        endsAt: now()->addDays(3),
        capacity: 10,
        organizerEmployeeEntityId: (int) $f['head']->id,
        targetDepartmentEntityId: (int) $f['unit']->id,
    ))->id;
}

function dashParticipant(array $f, int $eventId, string $name, bool $attended = true): TrainingParticipant
{
    $employee = Employee::factory()->create([
        'company_id' => $f['companyId'], 'department_id' => $f['department']->id,
        'full_name' => $name, 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    EmployeeWorkProfile::query()->create(['employee_id' => $employee->id, 'organization_unit_id' => $f['unit']->id]);
    $participant = TrainingParticipant::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'], 'event_id' => $eventId,
        'provider_id' => 'native', 'employee_subject_id' => (string) $employee->id,
        'workforce_observed_at' => now(),
    ]);
    $event = TrainingEvent::query()->forCompany($f['tenantId'], $f['companyId'])->findOrFail($eventId);
    $session = TrainingSession::query()->firstOrCreate([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'], 'event_id' => $eventId,
        'session_reference' => 'dash-session-'.$eventId,
    ], [
        'starts_at' => $event->starts_at, 'ends_at' => $event->ends_at,
        'created_by_user_id' => $f['hr']->id,
    ]);
    TrainingParticipationFact::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'],
        'event_id' => $eventId, 'participant_id' => $participant->id, 'session_id' => $session->id,
        'attendance' => $attended ? AttendanceStatus::Present : AttendanceStatus::Absent,
        'actual_minutes' => 120, 'evidence_references' => [],
        'source' => 'fixture', 'source_reference' => 'dash-fact-'.$participant->id,
        'recorded_by_user_id' => $f['hr']->id, 'recorded_capability' => 'fixture', 'recorded_at' => now(),
    ]);

    return $participant;
}

function dashEvaluation(array $f, int $eventId, TrainingParticipant $participant, int $rating, ?string $comment = null): TrainingEvaluation
{
    return TrainingEvaluation::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'],
        'event_id' => $eventId, 'participant_id' => $participant->id,
        'employee_subject_id' => $participant->employee_subject_id,
        'criteria_version' => '0012-a.v1',
        'relevance' => $rating, 'trainer_effectiveness' => $rating, 'materials_exercises' => $rating,
        'pace_duration' => $rating, 'practical_usefulness' => $rating,
        'issues_or_improvements' => $comment,
        'status' => TrainingEvaluationStatus::Completed,
        'due_on' => now()->addDays(17)->toDateString(),
        'completed_at' => now(),
        'entry_source' => 'self',
    ]);
}

function dashRows(array $f, ?User $actor = null): array
{
    return Livewire::actingAs($actor ?? $f['hr'])->test(Index::class)->viewData('events');
}

test('the response rate is submissions over attended participants', function (): void {
    $f = dashFixture();
    $eventId = dashEvent($f);
    $a = dashParticipant($f, $eventId, 'Alice');
    $b = dashParticipant($f, $eventId, 'Bob');
    dashParticipant($f, $eventId, 'Carol');
    dashEvaluation($f, $eventId, $a, 4);
    dashEvaluation($f, $eventId, $b, 2);

    $row = collect(dashRows($f))->firstWhere('event_id', $eventId);

    expect($row['attended'])->toBe(3)
        ->and($row['submitted'])->toBe(2)
        ->and($row['response_rate'])->toBe(67);
});

test('somebody who did not attend is not in the denominator', function (): void {
    $f = dashFixture();
    $eventId = dashEvent($f);
    $a = dashParticipant($f, $eventId, 'Alice');
    dashParticipant($f, $eventId, 'Never Came', attended: false);
    dashEvaluation($f, $eventId, $a, 5);

    // One attended, one submitted: a full response, not fifty percent. An
    // absentee was never expected to evaluate.
    $row = collect(dashRows($f))->firstWhere('event_id', $eventId);

    expect($row['attended'])->toBe(1)
        ->and($row['response_rate'])->toBe(100);
});

test('each of the five ratings gets its own mean', function (): void {
    $f = dashFixture();
    $eventId = dashEvent($f);
    dashEvaluation($f, $eventId, dashParticipant($f, $eventId, 'Alice'), 4);
    dashEvaluation($f, $eventId, dashParticipant($f, $eventId, 'Bob'), 2);

    $row = collect(dashRows($f))->firstWhere('event_id', $eventId);

    expect($row['means'])->toBe([
        'relevance' => 3.0,
        'trainer_effectiveness' => 3.0,
        'materials_exercises' => 3.0,
        'pace_duration' => 3.0,
        'practical_usefulness' => 3.0,
    ]);
});

test('an event with no attendance reports no rate rather than zero', function (): void {
    $f = dashFixture();
    $eventId = dashEvent($f);

    // Nobody attended, so there is nothing to be a percentage of. Zero would
    // read as a total failure to respond.
    $row = collect(dashRows($f))->firstWhere('event_id', $eventId);

    expect($row['attended'])->toBe(0)
        ->and($row['response_rate'])->toBeNull();
});

test('HR sees the comment with the participant name', function (): void {
    $f = dashFixture();
    $eventId = dashEvent($f);
    dashEvaluation($f, $eventId, dashParticipant($f, $eventId, 'Alice'), 4, 'The room was too cold.');

    $row = collect(dashRows($f))->firstWhere('event_id', $eventId);

    expect($row['comments'])->toBe([['participant' => 'Alice', 'comment' => 'The room was too cold.']]);
});

test('a HOD without the HR audience does not read the free text', function (): void {
    $f = dashFixture();
    $eventId = dashEvent($f);
    dashEvaluation($f, $eventId, dashParticipant($f, $eventId, 'Alice'), 4, 'The room was too cold.');

    // docs/contracts/training-evaluation.md keeps free text from the
    // departmental audience; an aggregate page must not become the way round it.
    $row = collect(dashRows($f, $f['hod']))->firstWhere('event_id', $eventId);

    expect($row['comments'])->toBe([])
        ->and($row['means']['relevance'])->toBe(4.0);
});

test('an event of another company never appears', function (): void {
    $f = dashFixture();
    $mine = dashEvent($f);
    $other = Company::factory()->create(['tenant_id' => $f['tenantId'], 'name' => 'Sibling', 'status' => 'active']);
    $theirCategory = app(SkillCatalogStore::class)->defineCategory((int) $other->id, 'safety', 'Safety');
    $theirSkill = app(SkillCatalogStore::class)->defineSkill((int) $other->id, new SkillDraft(
        code: 'isolation.energy', name: 'Energy isolation', definition: 'Isolate.',
        categoryId: (int) $theirCategory->id, defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));
    $theirHead = Employee::factory()->create([
        'company_id' => $other->id, 'full_name' => 'Their Head', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $theirCourse = app(TrainingCatalogStore::class)->defineCourse((int) $other->id, new TrainingCourseDraft(
        code: 'isolation.induction', title: 'Isolation induction',
        deliveryMode: DeliveryMode::InternalClassroom, skillIds: [(int) $theirSkill->id],
        internalTrainerEmployeeEntityId: (int) $theirHead->id,
    ));
    $theirEvent = (int) app(TrainingEventStore::class)->schedule((int) $other->id, new TrainingEventDraft(
        courseId: (int) $theirCourse->id, startsAt: now()->addDays(2), endsAt: now()->addDays(3),
        capacity: 5, organizerEmployeeEntityId: (int) $theirHead->id,
    ))->id;

    $ids = collect(dashRows($f))->pluck('event_id')->all();

    expect($ids)->toContain($mine)
        ->and($ids)->not->toContain($theirEvent);
});

test('the page is refused without the aggregate capability', function (): void {
    $f = dashFixture();
    dashEvent($f);

    Livewire::actingAs($f['nobody'])->test(Index::class)->assertForbidden();
});
