<?php

use App\Base\Audit\Models\AuditAction;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Services\WorkforceSubjects;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use App\Domains\People\Training\Data\LearningTestResult;
use App\Domains\People\Training\Data\ParticipationFactDraft;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Livewire\Event\Index;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use App\Domains\People\Training\Services\TrainingAudience;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use App\Domains\People\Training\Services\TrainingParticipationStore;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Event attendance register CSV export (0011-f, #392): HR downloads the
 * event's current participation facts in the `11 Training Attendance` column
 * order, one audit action per export. Self-contained: helpers are prefixed
 * attExport so they collide with nothing in the suite-wide Pest namespace.
 */

afterEach(function (): void {
    $this->travelBack();
    app(TenantContext::class)->clear();
});

function attExportRole(User $user, string $code): void
{
    setupAuthzRoles();
    PrincipalRole::query()->create([
        'company_id' => $user->company_id, 'principal_type' => PrincipalType::USER->value, 'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $code)->sole()->id,
    ]);
}

function attExportFlushAudit(): void
{
    $buffer = app(AuditBuffer::class);
    (new ReflectionClass($buffer))->getMethod('flush')->invoke($buffer);
}

/** @return array<string, mixed> */
function attExportFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Attendance Export Tenant'], ['name' => 'Attendance Export Company']);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);

    $trainer = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId);
    $learner = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId);
    $withdrawn = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId);
    $nominated = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId);

    $hr = User::factory()->create(['company_id' => $companyId, 'name' => 'Export HR']);
    attExportRole($hr, 'people_hr');
    $hod = User::factory()->create(['company_id' => $companyId, 'name' => 'Export HOD']);
    attExportRole($hod, 'people_hod');
    $trainerUser = User::factory()->create(['company_id' => $companyId, 'employee_id' => $trainer->id, 'name' => 'Export Trainer']);
    EmployeePortalAccess::query()->create([
        'employee_id' => $trainer->id, 'user_id' => $trainerUser->id,
        'display_name' => 'Assigned trainer', 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    attExportRole($trainerUser, 'people_training_trainer');

    $sibling = Company::factory()->create(['tenant_id' => $tenantId, 'name' => 'Attendance Export Sibling', 'status' => 'active']);
    $siblingHr = User::factory()->create(['company_id' => $sibling->id, 'name' => 'Sibling HR']);
    attExportRole($siblingHr, 'people_hr');

    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory($companyId, Str::lower(Str::random(12)), 'Export');
    $skill = $catalog->defineSkill($companyId, new SkillDraft(
        code: Str::lower(Str::random(12)), name: 'Export', definition: 'Attendance export', categoryId: (int) $category->id,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse($companyId, new TrainingCourseDraft(
        code: Str::lower(Str::random(12)), title: 'Attendance export course', deliveryMode: DeliveryMode::InternalClassroom,
        skillIds: [(int) $skill->id], internalTrainerEmployeeEntityId: (int) $trainer->id,
    ));
    $event = app(TrainingEventStore::class)->schedule($companyId, new TrainingEventDraft(
        courseId: (int) $course->id, startsAt: now()->addDay(), endsAt: now()->addDay()->addHours(4),
        capacity: 10, organizerEmployeeEntityId: (int) $trainer->id,
    ));
    $subject = new WorkforceSubject($tenantId, $companyId, WorkforceResourceType::Employee,
        (string) $learner->id, new ExternalReference(WorkforceResourceType::Employee, (string) $learner->id));

    return compact('tenant', 'tenantId', 'company', 'companyId', 'trainer', 'trainerUser', 'learner', 'withdrawn', 'nominated',
        'hr', 'hod', 'sibling', 'siblingHr', 'event', 'subject');
}

function attExportDraft(array $overrides = []): ParticipationFactDraft
{
    return new ParticipationFactDraft(...array_replace([
        'attendance' => AttendanceStatus::Present, 'actualMinutes' => 90,
        'source' => 'manual', 'sourceReference' => (string) Str::uuid(),
        'preTest' => new LearningTestResult(true, 40, 100, 70),
        'postTest' => new LearningTestResult(true, 85, 100, 70),
        'certificateReference' => 'certificate:export',
        'certificateValidFrom' => new DateTimeImmutable('2026-09-02'),
        'certificateValidUntil' => new DateTimeImmutable('2027-09-02'),
        'evidenceReferences' => [],
    ], $overrides));
}

/**
 * One session after the event has ended (facts are refused while it runs),
 * the learner's confirmed fact, a withdrawn participant and a nominated one
 * who was never recorded.
 *
 * @return array<string, mixed>
 */
function attExportRegister(array $f): array
{
    $store = app(TrainingParticipationStore::class);
    $session = $store->defineSession($f['hr'], $f['companyId'], (int) $f['event']->id, 'day-1',
        $f['event']->starts_at, $f['event']->starts_at->addHours(2));
    test()->travelTo($f['event']->ends_at->addHour());
    $fact = $store->recordAttendance($f['hr'], $f['companyId'], (int) $session->id, $f['subject'], attExportDraft());
    $fact = $store->confirm($f['hr'], $f['companyId'], (int) $fact->id);

    foreach ([['employee' => $f['withdrawn'], 'withdrawn_at' => now()], ['employee' => $f['nominated'], 'withdrawn_at' => null]] as $row) {
        TrainingParticipant::query()->forCompany($f['tenantId'], $f['companyId'])->create([
            'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'], 'event_id' => (int) $f['event']->id,
            'provider_id' => ExternalReference::PROVIDER_ID, 'employee_subject_id' => (string) $row['employee']->id,
            'workforce_observed_at' => now(), 'withdrawn_at' => $row['withdrawn_at'],
            'withdrawn_by_user_id' => $row['withdrawn_at'] === null ? null : $f['hr']->getKey(),
        ]);
    }

    return ['session' => $session, 'fact' => $fact];
}

/** @return list<string> the CSV lines of one export by $user */
function attExportDownload(User $user, int $eventId, ?int $companyId = null): array
{
    $page = Livewire::actingAs($user)->test(Index::class);
    if ($companyId !== null) {
        $page->set('companyEntityId', $companyId);
    }
    $page->call('exportAttendance', $eventId)->assertFileDownloaded();
    attExportFlushAudit();

    return array_values(array_filter(explode("\n", trim(base64_decode($page->effects['download']['content'])))));
}

/** The innermost exception a callback throws, or null when it does not throw. */
function attExportRootCause(callable $callback): ?Throwable
{
    try {
        $callback();
    } catch (Throwable $thrown) {
        while ($thrown->getPrevious() !== null) {
            $thrown = $thrown->getPrevious();
        }

        return $thrown;
    }

    return null;
}

function attExportName(int $companyId, int $employeeId): string
{
    foreach (app(WorkforceSubjects::class)->employees($companyId) as $employee) {
        if ((int) $employee->reference->externalId === $employeeId) {
            return $employee->displayName;
        }
    }

    throw new RuntimeException('Employee not in the directory');
}

test('the CSV carries the superseding row once and never the superseded original, which stays in the table', function (): void {
    $f = attExportFixture();
    ['fact' => $original] = attExportRegister($f);
    $correction = app(TrainingParticipationStore::class)->correct($f['hr'], $f['companyId'], (int) $original->id, attExportDraft([
        'actualMinutes' => 45, 'source' => 'correction', 'sourceReference' => 'fact:'.$original->id,
        'preTest' => new LearningTestResult(true, 40, 100, 70), 'postTest' => new LearningTestResult(true, 60, 100, 70),
    ]), 'Trainer signed the wrong sheet');

    $lines = attExportDownload($f['hr'], (int) $f['event']->id);

    expect($lines[0])->toBe(implode(',', Index::EXPORT_COLUMNS))
        ->and($lines[0])->toBe('employee_subject_id,employee_name,session_reference,attendance,actual_minutes,pre_test_score,post_test_score,improvement,pass_result,certificate_reference,certificate_valid_from,certificate_valid_until,confirmed_at,source,corrected');
    $learnerRows = array_values(array_filter($lines, fn (string $line): bool => str_starts_with($line, $f['learner']->id.',')));
    expect($learnerRows)->toHaveCount(1)
        ->and($learnerRows[0])->toContain(',day-1,present,45,40,60,20,fail,certificate:export,')
        ->and($learnerRows[0])->toEndWith(',correction,yes')
        ->and($learnerRows[0])->not->toContain(',90,')
        ->and($learnerRows[0])->not->toContain(',manual,');
    expect(TrainingParticipationFact::query()->forCompany($f['tenantId'], $f['companyId'])->whereKey($original->id)->exists())->toBeTrue()
        ->and((int) $correction->supersedes_fact_id)->toBe((int) $original->id);
});

test('a withdrawn participant is cancelled with no scores and a never-recorded participant is nominated with empty fact columns', function (): void {
    $f = attExportFixture();
    attExportRegister($f);

    $lines = attExportDownload($f['hr'], (int) $f['event']->id);

    $rows = array_map(str_getcsv(...), $lines);
    $blank = array_fill(0, 11, '');

    expect($lines)->toHaveCount(4)
        ->and($rows)->toContain([(string) $f['withdrawn']->id, attExportName($f['companyId'], (int) $f['withdrawn']->id), '', 'cancelled', ...$blank])
        ->and($rows)->toContain([(string) $f['nominated']->id, attExportName($f['companyId'], (int) $f['nominated']->id), '', 'nominated', ...$blank]);
});

test('each export writes exactly one audit row naming the rendered fact ids and changes nothing else', function (): void {
    $f = attExportFixture();
    ['fact' => $fact] = attExportRegister($f);
    attExportFlushAudit();
    $counts = fn (): array => [
        'audit' => AuditAction::query()->count(),
        'participants' => DB::table('people_training_participants')->count(),
        'facts' => DB::table('people_training_participation_facts')->count(),
        'sessions' => DB::table('people_training_sessions')->count(),
        'events' => DB::table('people_connector_training_events')->count(),
    ];
    $before = $counts();

    attExportDownload($f['hr'], (int) $f['event']->id);

    $after = $counts();
    expect($after['audit'])->toBe($before['audit'] + 1)
        ->and(array_diff_key($after, ['audit' => 0]))->toBe(array_diff_key($before, ['audit' => 0]));
    $action = AuditAction::query()->latest('id')->first();
    expect($action->event)->toBe(Index::EXPORT_EVENT)
        ->and($action->event)->toBe('people.training.participation.exported')
        ->and((int) $action->actor_id)->toBe((int) $f['hr']->id)
        ->and($action->payload['context']['fact_ids'])->toBe([(int) $fact->id])
        ->and($action->payload['context']['rows'])->toBe(3)
        ->and($action->payload['context']['training_event_id'])->toBe((int) $f['event']->id);

    attExportDownload($f['hr'], (int) $f['event']->id);

    $again = $counts();
    expect($again['audit'])->toBe($before['audit'] + 2)
        ->and(array_diff_key($again, ['audit' => 0]))->toBe(array_diff_key($before, ['audit' => 0]))
        ->and(AuditAction::query()->latest('id')->first()->payload['context']['fact_ids'])->toBe([(int) $fact->id]);
});

test('the assigned trainer, a same-company HOD and sibling-company HR are refused and another tenant does not find the event', function (): void {
    $f = attExportFixture();
    attExportRegister($f);
    $eventId = (int) $f['event']->id;
    $audience = app(TrainingAudience::class);

    expect($audience->canExport($f['hr'], $f['companyId']))->toBeTrue()
        ->and($audience->canExport($f['trainerUser'], $f['companyId']))->toBeFalse()
        ->and(fn () => $audience->authorizeExport($f['trainerUser'], $f['companyId']))->toThrow(AuthorizationDeniedException::class);
    // The trainer has no event.view, so the page refuses at mount; the view layer wraps the denial.
    expect(attExportRootCause(fn () => Livewire::actingAs($f['trainerUser'])->test(Index::class)->call('exportAttendance', $eventId)))
        ->toBeInstanceOf(AuthorizationDeniedException::class);

    // The HOD mounts the page (event.view, company-wide event) but holds no export capability.
    Livewire::actingAs($f['hod'])->test(Index::class)->assertViewHas('events', fn ($events): bool => $events->pluck('id')->all() === [$eventId]);
    expect(fn () => Livewire::actingAs($f['hod'])->test(Index::class)->call('exportAttendance', $eventId))
        ->toThrow(AuthorizationDeniedException::class);

    // Sibling HR is pinned to its own company: company A's event is not found there, and company A cannot be selected.
    expect(fn () => Livewire::actingAs($f['siblingHr'])->test(Index::class)->call('exportAttendance', $eventId))
        ->toThrow(ModelNotFoundException::class);
    Livewire::actingAs($f['siblingHr'])->test(Index::class)->call('selectCompany', $f['companyId'])->assertNotFound();

    [$otherTenant, $otherCompany] = createTenantWithCompany(['name' => 'Other Export Tenant'], ['name' => 'Other Export Company']);
    app(TenantContext::class)->set((int) $otherTenant->id);
    $otherHr = User::factory()->create(['company_id' => $otherCompany->id, 'name' => 'Other HR']);
    attExportRole($otherHr, 'people_hr');
    expect(fn () => Livewire::actingAs($otherHr)->test(Index::class)->call('exportAttendance', $eventId))
        ->toThrow(ModelNotFoundException::class);

    attExportFlushAudit();
    expect(AuditAction::query()->where('event', Index::EXPORT_EVENT)->count())->toBe(0);
});

test('certificate dates are formatted Y-m-d from the casts, never as the stored string with a time part', function (): void {
    $f = attExportFixture();
    ['fact' => $fact] = attExportRegister($f);
    $stored = DB::table('people_training_participation_facts')->where('id', $fact->id)->value('certificate_valid_from');
    expect((string) $stored)->toStartWith('2026-09-02');

    $lines = attExportDownload($f['hr'], (int) $f['event']->id);
    $row = array_values(array_filter($lines, fn (string $line): bool => str_starts_with($line, $f['learner']->id.',')))[0];

    expect($row)->toContain(',certificate:export,2026-09-02,2027-09-02,')
        ->and($row)->not->toContain('2026-09-02 00:00:00')
        ->and($row)->not->toContain('2027-09-02 00:00:00');
});

test('the export button renders only for holders of the export capability', function (): void {
    $f = attExportFixture();
    attExportRegister($f);

    Livewire::actingAs($f['hr'])->test(Index::class)
        ->assertViewHas('canExport', true)
        ->assertSee('Export attendance CSV')
        ->assertSeeHtml('wire:click="exportAttendance('.$f['event']->id.')"');
    Livewire::actingAs($f['hod'])->test(Index::class)
        ->assertViewHas('canExport', false)
        ->assertSee('Attendance export course')
        ->assertDontSee('Export attendance CSV');
});
