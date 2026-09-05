<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Authz\Policies\GrantPolicy;
use App\Base\Authz\Services\AuthorizationEngine;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Exceptions\MissingCompanyScopeException;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use App\Domains\People\Training\Data\LearningTestResult;
use App\Domains\People\Training\Data\ParticipationFactDraft;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Exceptions\InvalidTrainingParticipationException;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use App\Domains\People\Training\Models\TrainingSession;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use App\Domains\People\Training\Services\TrainingParticipationStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

afterEach(function (): void {
    $this->travelBack();
    app(TenantContext::class)->clear();
});

function participationRole(User $actor, string $code): void
{
    setupAuthzRoles();
    $role = Role::query()->whereNull('company_id')->where('code', $code)->sole();
    PrincipalRole::query()->create([
        'company_id' => $actor->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $actor->id,
        'role_id' => $role->id,
    ]);
}

function participationFixture(?array $scope = null): array
{
    [$tenant, $company] = $scope ?? createTenantWithCompany();
    app(TenantContext::class)->set((int) $tenant->id);
    $trainer = NativeWorkforceFixture::create((int) $tenant->id, WorkforceResourceType::Employee, (int) $company->id);
    $employee = NativeWorkforceFixture::create((int) $tenant->id, WorkforceResourceType::Employee, (int) $company->id);
    $hr = User::factory()->create(['company_id' => $company->id]);
    participationRole($hr, 'people_hr');
    $trainerUser = User::factory()->create(['company_id' => $company->id, 'employee_id' => $trainer->id]);
    EmployeePortalAccess::query()->create([
        'employee_id' => $trainer->id, 'user_id' => $trainerUser->id,
        'display_name' => 'Assigned trainer', 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    participationRole($trainerUser, 'people_training_trainer');
    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory((int) $company->id, Str::lower(Str::random(12)), 'Participation');
    $skill = $catalog->defineSkill((int) $company->id, new SkillDraft(
        code: Str::lower(Str::random(12)), name: 'Participation', definition: 'Learning participation', categoryId: (int) $category->id,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse((int) $company->id, new TrainingCourseDraft(
        code: Str::lower(Str::random(12)), title: 'Participation', deliveryMode: DeliveryMode::InternalClassroom,
        skillIds: [(int) $skill->id], internalTrainerEmployeeEntityId: (int) $trainer->id,
    ));
    $event = app(TrainingEventStore::class)->schedule((int) $company->id, new TrainingEventDraft(
        courseId: (int) $course->id, startsAt: now()->addDay(), endsAt: now()->addDay()->addHours(4),
        capacity: 10, organizerEmployeeEntityId: (int) $trainer->id,
    ));
    $subject = new WorkforceSubject((int) $tenant->id, (int) $company->id, WorkforceResourceType::Employee,
        (string) $employee->id, new ExternalReference(WorkforceResourceType::Employee, (string) $employee->id));

    return compact('tenant', 'company', 'trainer', 'trainerUser', 'employee', 'hr', 'event', 'subject');
}

function participationDraft(array $overrides = []): ParticipationFactDraft
{
    return new ParticipationFactDraft(...array_replace([
        'attendance' => AttendanceStatus::Present, 'actualMinutes' => 90,
        'source' => 'manual', 'sourceReference' => (string) Str::uuid(),
        'preTest' => new LearningTestResult(true, 0, 100, 70),
        'postTest' => new LearningTestResult(true, 85, 100, 70),
        'certificateReference' => 'certificate:123',
        'certificateValidFrom' => new DateTimeImmutable('2026-09-02'),
        'certificateValidUntil' => new DateTimeImmutable('2027-09-02'),
        'evidenceReferences' => ['document:attendance-123'],
    ], $overrides));
}

function participationSession(array $f, string $key = 'session-1'): mixed
{
    return app(TrainingParticipationStore::class)->defineSession($f['hr'], (int) $f['company']->id,
        (int) $f['event']->id, $key, $f['event']->starts_at, $f['event']->starts_at->addHours(2));
}

test('session facts retain actual minutes, typed results, certificate validity and confirmation provenance', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-09-01T12:00:00Z'));
    $f = participationFixture();
    $session = participationSession($f);
    $this->travelTo($f['event']->ends_at->addHour());
    $store = app(TrainingParticipationStore::class);
    $fact = $store->recordAttendance($f['trainerUser'], (int) $f['company']->id, (int) $session->id, $f['subject'], participationDraft());
    $saved = $store->confirm($f['hr'], (int) $f['company']->id, (int) $fact->id);
    $participant = TrainingParticipant::query()->forCompany((int) $f['tenant']->id, (int) $f['company']->id)->findOrFail($fact->participant_id);

    expect($saved->actual_minutes)->toBe(90)->and($saved->pre_test['score'])->toBe(0)
        ->and($saved->pre_test['passed'])->toBeFalse()->and($saved->post_test['passed'])->toBeTrue()
        ->and($saved->certificate_valid_until->format('Y-m-d'))->toBe('2027-09-02')
        ->and($saved->evidence_references)->toBe(['document:attendance-123'])
        ->and((int) $saved->recorded_by_user_id)->toBe((int) $f['trainerUser']->id)
        ->and((int) $saved->confirmed_by_user_id)->toBe((int) $f['hr']->id)
        ->and($saved->confirmed_at)->not->toBeNull()
        ->and($participant->employee_subject_id)->toBe($f['subject']->stableId)
        ->and($participant->provider_id)->toBe(ExternalReference::PROVIDER_ID);
    expect(DB::table('people_connector_skill_employee_scores')->count())->toBe(0);
});

test('a participant is shared across sessions and event hours are never substituted for absence', function (): void {
    $f = participationFixture();
    $first = participationSession($f);
    $second = participationSession($f, 'session-2');
    $this->travelTo($f['event']->ends_at->addHour());
    $store = app(TrainingParticipationStore::class);
    $one = $store->recordAttendance($f['hr'], (int) $f['company']->id, (int) $first->id, $f['subject'], participationDraft());
    $two = $store->recordAttendance($f['hr'], (int) $f['company']->id, (int) $second->id, $f['subject'],
        participationDraft(['attendance' => AttendanceStatus::Absent, 'actualMinutes' => 0, 'preTest' => null, 'postTest' => new LearningTestResult(false),
            'certificateReference' => null, 'certificateValidFrom' => null, 'certificateValidUntil' => null]));
    expect($one->participant_id)->toBe($two->participant_id)->and($two->actual_minutes)->toBe(0)
        ->and($two->pre_test)->toBeNull()->and($two->post_test['applicable'])->toBeFalse()
        ->and($two->post_test['score'])->toBeNull();
});

test('trainer confirmation and missing record capability are refused', function (): void {
    $f = participationFixture();
    $session = participationSession($f);
    $this->travelTo($f['event']->ends_at->addHour());
    $store = app(TrainingParticipationStore::class);
    $fact = $store->recordAttendance($f['hr'], (int) $f['company']->id, (int) $session->id, $f['subject'], participationDraft());
    expect(fn () => $store->confirm($f['trainerUser'], (int) $f['company']->id, (int) $fact->id))->toThrow(AuthorizationDeniedException::class);
    $actor = User::factory()->create(['company_id' => $f['company']->id]);
    expect(fn () => $store->recordAttendance($actor, (int) $f['company']->id, (int) $session->id, $f['subject'], participationDraft()))
        ->toThrow(AuthorizationDeniedException::class);
});

test('a revoked trainer binding is refused on every write', function (): void {
    $f = participationFixture();
    $session = participationSession($f);
    $this->travelTo($f['event']->ends_at->addHour());
    EmployeePortalAccess::query()->where('user_id', $f['trainerUser']->id)->update(['status' => EmployeePortalAccess::STATUS_REVOKED]);
    expect(fn () => app(TrainingParticipationStore::class)->recordAttendance($f['trainerUser'], (int) $f['company']->id,
        (int) $session->id, $f['subject'], participationDraft()))->toThrow(InvalidTrainingParticipationException::class);
});

test('wrong tenant company and provider subjects fail closed', function (): void {
    $f = participationFixture();
    $session = participationSession($f);
    $this->travelTo($f['event']->ends_at->addHour());
    $store = app(TrainingParticipationStore::class);
    $sibling = NativeWorkforceFixture::create((int) $f['tenant']->id, WorkforceResourceType::Company);
    expect(fn () => $store->recordAttendance($f['hr'], (int) $sibling->id, (int) $session->id, $f['subject'], participationDraft()))
        ->toThrow(InvalidTrainingParticipationException::class);
    $foreign = new WorkforceSubject((int) $f['tenant']->id, (int) $f['company']->id, WorkforceResourceType::Employee,
        $f['subject']->stableId, new ExternalReference(WorkforceResourceType::Employee, $f['subject']->stableId, 'foreign'));
    expect(fn () => $store->recordAttendance($f['hr'], (int) $f['company']->id, (int) $session->id, $foreign, participationDraft()))
        ->toThrow(InvalidTrainingParticipationException::class);
    app(TenantContext::class)->clear();
    expect(fn () => $store->confirm($f['hr'], (int) $f['company']->id, 1))->toThrow(InvalidTrainingParticipationException::class);
});

test('confirmed facts cannot be revised or deleted through the store or raw writes', function (): void {
    $f = participationFixture();
    $session = participationSession($f);
    $this->travelTo($f['event']->ends_at->addHour());
    $store = app(TrainingParticipationStore::class);
    $fact = $store->recordAttendance($f['hr'], (int) $f['company']->id, (int) $session->id, $f['subject'], participationDraft());
    $store->confirm($f['hr'], (int) $f['company']->id, (int) $fact->id);
    expect(fn () => $store->revise($f['hr'], (int) $f['company']->id, (int) $fact->id, participationDraft()))->toThrow(InvalidTrainingParticipationException::class);
    expect(fn () => DB::transaction(fn () => DB::table('people_training_participation_facts')->where('id', $fact->id)->update(['actual_minutes' => 1])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('people_training_participation_facts')->where('id', $fact->id)->delete()))
        ->toThrow(QueryException::class);
    expect($fact->refresh()->actual_minutes)->toBe(90);
});

test('an assigned trainer cannot record another trainers event', function (): void {
    $f = participationFixture();
    $other = participationFixture([$f['tenant'], $f['company']]);
    $session = participationSession($other);
    $this->travelTo($f['event']->ends_at->addHours(2));
    expect(fn () => app(TrainingParticipationStore::class)->recordAttendance($f['trainerUser'], (int) $f['company']->id,
        (int) $session->id, $other['subject'], participationDraft()))->toThrow(InvalidTrainingParticipationException::class);
});

test('a scoped company cannot import a sibling session or participant through matching numeric ids', function (): void {
    $f = participationFixture();
    $sibling = NativeWorkforceFixture::create((int) $f['tenant']->id, WorkforceResourceType::Company);
    $other = participationFixture([$f['tenant'], $sibling]);
    $session = participationSession($other);
    $this->travelTo($f['event']->ends_at->addHours(2));
    $store = app(TrainingParticipationStore::class);
    expect(fn () => $store->recordAttendance($f['hr'], (int) $f['company']->id, (int) $session->id, $f['subject'], participationDraft()))
        ->toThrow(InvalidTrainingParticipationException::class);
    expect(fn () => $store->recordAttendance($f['hr'], (int) $sibling->id, (int) $session->id, $other['subject'], participationDraft()))
        ->toThrow(InvalidTrainingParticipationException::class);
    $ownSession = participationSession($f);
    expect(fn () => $store->recordAttendance($f['hr'], (int) $f['company']->id, (int) $ownSession->id, $other['subject'], participationDraft()))
        ->toThrow(InvalidTrainingParticipationException::class);
});

test('confirmation needs the HR audience even when a trainer receives the confirmation capability', function (): void {
    $f = participationFixture();
    $session = participationSession($f);
    $this->travelTo($f['event']->ends_at->addHour());
    $role = Role::query()->where('code', 'people_training_trainer')->sole();
    $role->capabilities()->create(['capability_key' => TrainingParticipationStore::CONFIRM]);
    $store = app(TrainingParticipationStore::class);
    $fact = $store->recordAttendance($f['hr'], (int) $f['company']->id, (int) $session->id, $f['subject'], participationDraft());
    expect(fn () => $store->confirm($f['trainerUser'], (int) $f['company']->id, (int) $fact->id))
        ->toThrow(InvalidTrainingParticipationException::class);
});

test('pending facts can be corrected and duplicate source evidence is refused', function (): void {
    $f = participationFixture();
    $session = participationSession($f);
    $this->travelTo($f['event']->ends_at->addHour());
    $store = app(TrainingParticipationStore::class);
    $draft = participationDraft();
    $fact = $store->recordAttendance($f['hr'], (int) $f['company']->id, (int) $session->id, $f['subject'], $draft);
    $revised = $store->revise($f['hr'], (int) $f['company']->id, (int) $fact->id, participationDraft(['actualMinutes' => 30, 'sourceReference' => $draft->sourceReference]));
    expect($revised->actual_minutes)->toBe(30)->and($revised->confirmed_at)->toBeNull();
    $next = participationSession($f, 'session-next');
    expect(fn () => $store->recordAttendance($f['hr'], (int) $f['company']->id, (int) $next->id, $f['subject'], $draft))
        ->toThrow(InvalidTrainingParticipationException::class);
    expect(TrainingParticipationFact::query()->forCompany((int) $f['tenant']->id, (int) $f['company']->id)->count())->toBe(1);
});

test('invalid actual hours certificate ranges and exposed evidence paths are refused', function (array $invalid): void {
    $f = participationFixture();
    $session = participationSession($f);
    $this->travelTo($f['event']->ends_at->addHour());
    expect(fn () => app(TrainingParticipationStore::class)->recordAttendance($f['hr'], (int) $f['company']->id,
        (int) $session->id, $f['subject'], participationDraft($invalid)))->toThrow(InvalidTrainingParticipationException::class);
})->with([
    'negative minutes' => [['actualMinutes' => -1]],
    'more than session duration' => [['actualMinutes' => 121]],
    'absent with hours' => [['attendance' => AttendanceStatus::Absent, 'actualMinutes' => 5]],
    'reversed certificate range' => [['certificateValidUntil' => new DateTimeImmutable('2020-01-01')]],
    'dates without a certificate' => [['certificateReference' => null]],
    'evidence URL' => [['evidenceReferences' => ['https://private.example/attendance.pdf']]],
    'source path' => [['sourceReference' => '/private/employee.pdf']],
]);

test('future attendance is refused and session identity is immutable', function (): void {
    $f = participationFixture();
    $session = participationSession($f);
    $store = app(TrainingParticipationStore::class);
    expect(fn () => $store->recordAttendance($f['hr'], (int) $f['company']->id, (int) $session->id, $f['subject'], participationDraft()))
        ->toThrow(InvalidTrainingParticipationException::class);
    expect(fn () => $store->defineSession($f['hr'], (int) $f['company']->id, (int) $f['event']->id,
        'outside', $f['event']->starts_at->subHour(), $f['event']->ends_at))->toThrow(InvalidTrainingParticipationException::class);
    expect(fn () => DB::transaction(fn () => DB::table('people_training_sessions')->where('id', $session->id)->update(['session_reference' => 'changed'])))
        ->toThrow(QueryException::class);
});

test('learning test unknown and not applicable cannot become zero or invented passes', function (): void {
    expect((new LearningTestResult(true))->toArray()['passed'])->toBeNull()
        ->and((new LearningTestResult(false))->toArray()['applicable'])->toBeFalse()
        ->and(fn () => new LearningTestResult(false, 0, 100, 70))->toThrow(InvalidTrainingParticipationException::class)
        ->and(fn () => new LearningTestResult(true, 101, 100, 70))->toThrow(InvalidTrainingParticipationException::class)
        ->and(fn () => new LearningTestResult(true, 50))->toThrow(InvalidTrainingParticipationException::class);
});

test('facts cannot attach a session from another event even through a raw write', function (): void {
    $f = participationFixture();
    $first = participationSession($f);
    $other = participationFixture([$f['tenant'], $f['company']]);
    $second = participationSession($other);
    $this->travelTo($other['event']->ends_at->addHour());
    $fact = app(TrainingParticipationStore::class)->recordAttendance($f['hr'], (int) $f['company']->id, (int) $first->id, $f['subject'], participationDraft());
    expect(fn () => DB::transaction(fn () => DB::table('people_training_participation_facts')->where('id', $fact->id)->update(['session_id' => $second->id])))
        ->toThrow(QueryException::class);
});

test('evidence assignment is checked separately and cannot be removed or confirmed after its grant is revoked', function (): void {
    $f = participationFixture();
    $session = participationSession($f);
    $this->travelTo($f['event']->ends_at->addHour());
    $store = app(TrainingParticipationStore::class);
    $fact = $store->recordAttendance($f['hr'], (int) $f['company']->id, (int) $session->id, $f['subject'], participationDraft());
    Role::query()->where('code', 'people_hr')->sole()->capabilities()->where('capability_key', TrainingParticipationStore::EVIDENCE)->delete();
    // Authorization snapshots are request-scoped. Begin the next request's graph.
    foreach ([
        GrantPolicy::class,
        AuthorizationEngine::class,
        AuthorizationService::class,
        SkillAudience::class,
    ] as $binding) {
        app()->forgetInstance($binding);
    }
    $store = app(TrainingParticipationStore::class);
    expect(fn () => $store->confirm($f['hr'], (int) $f['company']->id, (int) $fact->id))->toThrow(AuthorizationDeniedException::class);
    expect(fn () => $store->revise($f['hr'], (int) $f['company']->id, (int) $fact->id,
        participationDraft(['evidenceReferences' => [], 'certificateReference' => null, 'certificateValidFrom' => null, 'certificateValidUntil' => null])))
        ->toThrow(AuthorizationDeniedException::class);
    expect($fact->refresh()->evidence_references)->toBe(['document:attendance-123']);
});

test('an assigned trainer still needs the functional recording capability', function (): void {
    $f = participationFixture();
    $session = participationSession($f);
    $this->travelTo($f['event']->ends_at->addHour());
    PrincipalRole::query()->where('principal_id', $f['trainerUser']->id)->where('principal_type', PrincipalType::USER->value)->delete();
    expect(fn () => app(TrainingParticipationStore::class)->recordAttendance($f['trainerUser'], (int) $f['company']->id,
        (int) $session->id, $f['subject'], participationDraft()))->toThrow(AuthorizationDeniedException::class);
});

test('every new participation table requires the company axis', function (string $model): void {
    $f = participationFixture();
    expect(fn () => $model::query()->forTenant((int) $f['tenant']->id)->count())
        ->toThrow(MissingCompanyScopeException::class);
})->with([TrainingParticipant::class, TrainingParticipationFact::class, TrainingSession::class]);

test('an actor moved to another company cannot keep writing with a stale user instance', function (): void {
    $f = participationFixture();
    $session = participationSession($f);
    $this->travelTo($f['event']->ends_at->addHour());
    $other = NativeWorkforceFixture::create((int) $f['tenant']->id, WorkforceResourceType::Company);
    DB::table('users')->where('id', $f['hr']->id)->update(['company_id' => $other->id]);
    expect(fn () => app(TrainingParticipationStore::class)->recordAttendance($f['hr'], (int) $f['company']->id,
        (int) $session->id, $f['subject'], participationDraft()))->toThrow(InvalidTrainingParticipationException::class);
});
