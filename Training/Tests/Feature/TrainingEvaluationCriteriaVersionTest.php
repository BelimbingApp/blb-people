<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use App\Domains\People\Training\Data\ParticipationFactDraft;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Enums\TrainingEvaluationStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingEvaluationException;
use App\Domains\People\Training\Models\TrainingEvaluation;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEvaluationReader;
use App\Domains\People\Training\Services\TrainingEvaluationSubmissionStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use App\Domains\People\Training\Services\TrainingParticipationStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Criteria version 0012-g.v1 (blb-people#360)
|--------------------------------------------------------------------------
|
| All eight workbook ratings and the four free-text answers, a draft that
| keeps partial answers without completing, and completion gated on the
| mandatory questions of the version the row is pinned to. Self-contained:
| helpers are prefixed criteriaV and live here.
*/

afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

function criteriaVRole(User $user, string $code): void
{
    setupAuthzRoles();
    PrincipalRole::query()->create([
        'company_id' => $user->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $code)->sole()->id,
    ]);
}

/** An employee with portal access and the employee role, so the store's self-binding resolves. */
function criteriaVEmployeeUser(int $tenantId, int $companyId): array
{
    $employee = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId);
    $user = User::factory()->create(['company_id' => $companyId, 'employee_id' => $employee->id]);
    criteriaVRole($user, 'people_employee');
    EmployeePortalAccess::query()->create([
        'employee_id' => $employee->id, 'user_id' => $user->id,
        'display_name' => $employee->full_name, 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);

    return [$employee, $user];
}

/** @return array<string, mixed> tenant, company, trainer, employee, user, hr, course, event (ends_at carries a time part) */
function criteriaVFixture(): array
{
    [$tenant, $company] = createTenantWithCompany();
    app(TenantContext::class)->set((int) $tenant->id);
    $trainer = NativeWorkforceFixture::create((int) $tenant->id, WorkforceResourceType::Employee, (int) $company->id);
    [$employee, $user] = criteriaVEmployeeUser((int) $tenant->id, (int) $company->id);
    $hr = User::factory()->create(['company_id' => $company->id]);
    criteriaVRole($hr, 'people_hr');

    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory((int) $company->id, Str::lower(Str::random(12)), 'Evaluation');
    $skill = $catalog->defineSkill((int) $company->id, new SkillDraft(
        code: Str::lower(Str::random(12)), name: 'Evaluation', definition: 'Post-training feedback', categoryId: (int) $category->id,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse((int) $company->id, new TrainingCourseDraft(
        code: Str::lower(Str::random(12)), title: 'Forklift safety', deliveryMode: DeliveryMode::InternalClassroom,
        skillIds: [(int) $skill->id], internalTrainerEmployeeEntityId: (int) $trainer->id,
    ));
    $event = app(TrainingEventStore::class)->schedule((int) $company->id, new TrainingEventDraft(
        courseId: (int) $course->id, startsAt: now()->addDay()->setTime(9, 0), endsAt: now()->addDay()->setTime(16, 30),
        capacity: 10, organizerEmployeeEntityId: (int) $trainer->id,
    ));

    return compact('tenant', 'company', 'trainer', 'employee', 'user', 'hr', 'course', 'event');
}

/** Record attendance for an employee and move the clock to one hour after the event ends. */
function criteriaVAttend(array $f, ?Employee $employee = null): mixed
{
    $store = app(TrainingParticipationStore::class);
    $session = $store->defineSession(
        $f['hr'], (int) $f['company']->id, (int) $f['event']->id,
        (string) Str::uuid(), $f['event']->starts_at, $f['event']->ends_at,
    );
    Carbon::setTestNow($f['event']->ends_at->addHour());
    $employee ??= $f['employee'];
    $subject = new WorkforceSubject(
        (int) $f['tenant']->id, (int) $f['company']->id, WorkforceResourceType::Employee, (string) $employee->id,
        new ExternalReference(WorkforceResourceType::Employee, (string) $employee->id),
    );

    return $store->recordAttendance($f['hr'], (int) $f['company']->id, (int) $session->id, $subject, new ParticipationFactDraft(
        attendance: AttendanceStatus::Present, actualMinutes: 120, source: 'manual', sourceReference: (string) Str::uuid(),
    ));
}

/** Every mandatory answer of 0012-g.v1, ratings all $rating, plus one optional text. */
function criteriaVComplete(int $rating = 4): array
{
    return [
        'relevance' => $rating, 'objectives_met' => $rating, 'content_quality' => $rating, 'trainer_effectiveness' => $rating,
        'materials_exercises' => $rating, 'pace_duration' => $rating, 'practical_usefulness' => $rating, 'overall_satisfaction' => $rating,
        'application_commitment' => 'Use the pre-start checklist on every shift.',
        'most_useful_learning' => 'Load charts.',
    ];
}

function criteriaVRows(array $f): mixed
{
    return TrainingEvaluation::query()->forCompany((int) $f['tenant']->id, (int) $f['company']->id);
}

function criteriaVStore(): TrainingEvaluationSubmissionStore
{
    return app(TrainingEvaluationSubmissionStore::class);
}

test('a draft with three of eight ratings keeps the rest null, is pinned to 0012-g.v1 and is not completed', function (): void {
    $f = criteriaVFixture();
    criteriaVAttend($f);

    criteriaVStore()->saveDraft($f['user'], (int) $f['company']->id, (int) $f['event']->id, [
        'relevance' => 5, 'objectives_met' => 4, 'content_quality' => 3,
    ]);

    $row = criteriaVRows($f)->sole();
    expect($row->status)->toBe(TrainingEvaluationStatus::Draft)
        ->and($row->completed_at)->toBeNull()
        ->and($row->criteria_version)->toBe('0012-g.v1')
        ->and($row->relevance)->toBe(5)
        ->and($row->objectives_met)->toBe(4)
        ->and($row->content_quality)->toBe(3);
    foreach (['trainer_effectiveness', 'materials_exercises', 'pace_duration', 'practical_usefulness', 'overall_satisfaction'] as $unanswered) {
        expect($row->getAttributes()[$unanswered])->toBeNull($unanswered.' must be null, not 0');
    }
});

test('completion without the application commitment is refused naming it and the row stays a draft', function (): void {
    $f = criteriaVFixture();
    criteriaVAttend($f);
    $store = criteriaVStore();
    $store->saveDraft($f['user'], (int) $f['company']->id, (int) $f['event']->id, ['relevance' => 5]);
    expect(criteriaVRows($f)->sole()->status)->toBe(TrainingEvaluationStatus::Draft);

    $answers = criteriaVComplete();
    unset($answers['application_commitment']);
    expect(fn () => $store->complete($f['user'], (int) $f['company']->id, (int) $f['event']->id, $answers))
        ->toThrow(InvalidTrainingEvaluationException::class, 'application_commitment');

    $row = criteriaVRows($f)->sole();
    expect($row->status)->toBe(TrainingEvaluationStatus::Draft)
        ->and($row->completed_at)->toBeNull()
        ->and($row->objectives_met)->toBeNull();
});

test('completion with every mandatory answer writes completed, completed_at, self provenance and the 0012-g columns', function (): void {
    $f = criteriaVFixture();
    criteriaVAttend($f);

    criteriaVStore()->complete($f['user'], (int) $f['company']->id, (int) $f['event']->id, criteriaVComplete(4));

    $row = criteriaVRows($f)->sole();
    expect($row->status)->toBe(TrainingEvaluationStatus::Completed)
        ->and($row->completed_at)->not->toBeNull()
        ->and($row->entry_source)->toBe('self')
        ->and($row->criteria_version)->toBe('0012-g.v1')
        ->and($row->objectives_met)->toBe(4)
        ->and($row->content_quality)->toBe(4)
        ->and($row->overall_satisfaction)->toBe(4)
        ->and($row->most_useful_learning)->toBe('Load charts.')
        ->and($row->application_commitment)->toBe('Use the pre-start checklist on every shift.')
        ->and($row->support_needed)->toBeNull()
        ->and($row->submitted_by_user_id)->toBe($f['user']->id);
});

test('a draft cannot be saved over a completed evaluation; the completed row is unchanged', function (): void {
    $f = criteriaVFixture();
    criteriaVAttend($f);
    $store = criteriaVStore();
    $store->complete($f['user'], (int) $f['company']->id, (int) $f['event']->id, criteriaVComplete(4));
    $before = criteriaVRows($f)->sole();
    // A later instant, still inside the window, so an unchanged updated_at is a measurement rather than a coincidence.
    Carbon::setTestNow(now()->addHours(3));

    expect(fn () => $store->saveDraft($f['user'], (int) $f['company']->id, (int) $f['event']->id, ['relevance' => 1]))
        ->toThrow(InvalidTrainingEvaluationException::class, 'already completed');

    $after = criteriaVRows($f)->sole();
    expect($after->status)->toBe(TrainingEvaluationStatus::Completed)
        ->and($after->relevance)->toBe(4)
        ->and($after->updated_at->equalTo($before->updated_at))->toBeTrue()
        ->and(criteriaVRows($f)->count())->toBe(1);
});

test('a rating outside 1-5 is refused by both methods and nothing is written', function (string $method, int $rating): void {
    $f = criteriaVFixture();
    criteriaVAttend($f);
    $answers = [...criteriaVComplete(), 'pace_duration' => $rating];

    expect(fn () => criteriaVStore()->{$method}($f['user'], (int) $f['company']->id, (int) $f['event']->id, $answers))
        ->toThrow(InvalidTrainingEvaluationException::class, 'from 1 to 5');

    expect(criteriaVRows($f)->count())->toBe(0);
})->with([
    'draft, zero' => ['saveDraft', 0],
    'draft, six' => ['saveDraft', 6],
    'complete, zero' => ['complete', 0],
    'complete, six' => ['complete', 6],
]);

test('the 14-day window applies to both methods: open at the closing instant, refused one second later', function (string $method): void {
    $f = criteriaVFixture();
    criteriaVAttend($f);
    $closesAt = $f['event']->ends_at->addDays(14);
    expect($f['event']->ends_at->format('H:i'))->toBe('16:30');
    $answers = $method === 'complete' ? criteriaVComplete() : ['relevance' => 3];

    Carbon::setTestNow($closesAt);
    criteriaVStore()->{$method}($f['user'], (int) $f['company']->id, (int) $f['event']->id, $answers);
    expect(criteriaVRows($f)->whereDate('due_on', $closesAt->toDateString())->count())->toBe(1);

    Carbon::setTestNow($closesAt->addSecond());
    expect(fn () => criteriaVStore()->{$method}($f['user'], (int) $f['company']->id, (int) $f['event']->id, [...$answers, 'relevance' => 1]))
        ->toThrow(InvalidTrainingEvaluationException::class, 'window has closed');

    expect(criteriaVRows($f)->sole()->relevance)->toBe($answers['relevance']);
})->with(['saveDraft', 'complete']);

test('a criterion mean uses only the rows that answered it, reports the answered count, and ignores a draft', function (): void {
    $f = criteriaVFixture();
    criteriaVAttend($f);
    $store = criteriaVStore();
    $store->complete($f['user'], (int) $f['company']->id, (int) $f['event']->id, criteriaVComplete(4));

    [$second, $secondUser] = criteriaVEmployeeUser((int) $f['tenant']->id, (int) $f['company']->id);
    criteriaVAttend($f, $second);
    $store->complete($secondUser, (int) $f['company']->id, (int) $f['event']->id, criteriaVComplete(2));
    criteriaVRows($f)->where('employee_subject_id', (string) $second->id)->update(['content_quality' => null]);

    $reader = app(TrainingEvaluationReader::class);
    $means = $reader->means(criteriaVRows($f)->get());
    expect($means['content_quality'])->toBe(['mean' => 4.0, 'answered_count' => 1])
        ->and($means['relevance'])->toBe(['mean' => 3.0, 'answered_count' => 2])
        ->and(array_keys($means))->toBe(TrainingEvaluationReader::RATINGS);

    [$third, $thirdUser] = criteriaVEmployeeUser((int) $f['tenant']->id, (int) $f['company']->id);
    criteriaVAttend($f, $third);
    $store->saveDraft($thirdUser, (int) $f['company']->id, (int) $f['event']->id, ['content_quality' => 1, 'relevance' => 1]);

    expect(criteriaVRows($f)->count())->toBe(3)
        ->and($reader->means(criteriaVRows($f)->get()))->toBe($means);
});

test('a 0012-a.v1 submission still writes and aggregates unchanged beside a 0012-g.v1 row', function (): void {
    $f = criteriaVFixture();
    criteriaVAttend($f);
    $store = criteriaVStore();
    $store->submit($f['user'], (int) $f['company']->id, (int) $f['event']->id, [
        'relevance' => 5, 'trainer_effectiveness' => 4, 'materials_exercises' => 3, 'pace_duration' => 2, 'practical_usefulness' => 1,
    ], 'Old form.');

    $legacy = criteriaVRows($f)->sole();
    expect($legacy->criteria_version)->toBe('0012-a.v1')
        ->and($legacy->status)->toBe(TrainingEvaluationStatus::Completed)
        ->and($legacy->objectives_met)->toBeNull()
        ->and($legacy->application_commitment)->toBeNull()
        ->and($legacy->issues_or_improvements)->toBe('Old form.');

    [$second, $secondUser] = criteriaVEmployeeUser((int) $f['tenant']->id, (int) $f['company']->id);
    criteriaVAttend($f, $second);
    $store->complete($secondUser, (int) $f['company']->id, (int) $f['event']->id, criteriaVComplete(3));

    $means = app(TrainingEvaluationReader::class)->means(criteriaVRows($f)->get());
    expect($means['relevance'])->toBe(['mean' => 4.0, 'answered_count' => 2])
        ->and($means['objectives_met'])->toBe(['mean' => 3.0, 'answered_count' => 1])
        ->and(criteriaVRows($f)->where('criteria_version', '0012-a.v1')->sole()->relevance)->toBe(5);
});

test('a sibling company\'s employee and another tenant\'s user cannot draft against this participant', function (): void {
    $f = criteriaVFixture();
    criteriaVAttend($f);
    $tenantId = (int) $f['tenant']->id;
    $alpha = (int) $f['company']->id;
    $eventId = (int) $f['event']->id;
    $beta = Company::factory()->create(['tenant_id' => $tenantId, 'name' => 'Beta Sibling', 'status' => 'active']);
    [, $siblingUser] = criteriaVEmployeeUser($tenantId, (int) $beta->id);

    foreach ([$alpha, (int) $beta->id] as $companyId) {
        expect(fn () => criteriaVStore()->saveDraft($siblingUser, $companyId, $eventId, ['relevance' => 5]))
            ->toThrow(InvalidTrainingEvaluationException::class, 'unavailable in the current scope');
    }

    [$otherTenant, $otherCompany] = createTenantWithCompany();
    app(TenantContext::class)->set((int) $otherTenant->id);
    [, $strangerUser] = criteriaVEmployeeUser((int) $otherTenant->id, (int) $otherCompany->id);
    foreach ([$alpha, (int) $otherCompany->id] as $companyId) {
        expect(fn () => criteriaVStore()->saveDraft($strangerUser, $companyId, $eventId, ['relevance' => 5]))
            ->toThrow(InvalidTrainingEvaluationException::class, 'unavailable in the current scope');
    }

    expect(criteriaVRows($f)->count())->toBe(0)
        ->and(TrainingEvaluation::query()->forCompany($tenantId, (int) $beta->id)->count())->toBe(0)
        ->and(TrainingEvaluation::query()->forCompany((int) $otherTenant->id, (int) $otherCompany->id)->count())->toBe(0);
});
