<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Enums\TrainingEvaluationFollowupKind;
use App\Domains\People\Training\Enums\TrainingEvaluationFollowupStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingEvaluationException;
use App\Domains\People\Training\Livewire\Evaluations\Index as EvaluationsDashboard;
use App\Domains\People\Training\Models\TrainingEvaluation;
use App\Domains\People\Training\Models\TrainingEvaluationFollowup;
use App\Domains\People\Training\Models\TrainingEvaluationFollowupAudit;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEvaluationFollowupStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Self-contained: every helper is prefixed evaluationFollowup and lives here.
 *
 * Each acceptance bullet of blb-people#315 is one test below, failing first:
 * cross-scope refusal, HR-only access, the untouched evaluation row, the
 * one-open-per-kind rule, close-needs-action, and one audit entry per
 * transition.
 */

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

function evaluationFollowupRole(User $actor, string $code): void
{
    setupAuthzRoles();
    PrincipalRole::query()->create([
        'company_id' => $actor->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $actor->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $code)->sole()->id,
    ]);
}

function evaluationFollowupUser(int $companyId, string $role): User
{
    $user = User::factory()->create(['company_id' => $companyId]);
    evaluationFollowupRole($user, $role);

    return $user;
}

/**
 * @return array{tenantId: int, companyId: int, evaluationId: int, hr: User, hod: User, employee: User}
 */
function evaluationFollowupFixture(): array
{
    [$tenant, $company] = createTenantWithCompany();
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);

    $trainer = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId);
    $employee = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId);

    $hr = evaluationFollowupUser($companyId, 'people_hr');
    $hod = evaluationFollowupUser($companyId, 'people_hod');
    $participant = evaluationFollowupUser($companyId, 'people_employee');

    $skill = app(SkillCatalogStore::class)->defineSkill($companyId, new SkillDraft(
        code: Str::lower(Str::random(12)), name: 'Followup', definition: 'Learning',
        categoryId: (int) app(SkillCatalogStore::class)->defineCategory($companyId, Str::lower(Str::random(12)), 'Followup')->id,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse($companyId, new TrainingCourseDraft(
        code: Str::lower(Str::random(12)), title: 'Followup', deliveryMode: DeliveryMode::InternalClassroom,
        skillIds: [(int) $skill->id], internalTrainerEmployeeEntityId: (int) $trainer->id,
    ));
    $event = app(TrainingEventStore::class)->schedule($companyId, new TrainingEventDraft(
        courseId: (int) $course->id, startsAt: now()->addDay(), endsAt: now()->addDay()->addHours(4),
        capacity: 10, organizerEmployeeEntityId: (int) $trainer->id,
    ));

    $participantRow = TrainingParticipant::query()->create([
        'tenant_id' => $tenantId,
        'company_entity_id' => $companyId,
        'event_id' => (int) $event->id,
        'provider_id' => 'native',
        'employee_subject_id' => (string) $employee->id,
        'workforce_observed_at' => now(),
    ]);

    $evaluation = TrainingEvaluation::query()->create([
        'tenant_id' => $tenantId,
        'company_entity_id' => $companyId,
        'participant_id' => (int) $participantRow->id,
        'event_id' => (int) $event->id,
        'employee_subject_id' => (string) $employee->id,
        'criteria_version' => '2026.1',
        'relevance' => 4,
        'overall_satisfaction' => 5,
        'support_needed' => 'A mentor for the first month on call.',
        'issues_or_improvements' => 'The trainer rushed the practical section.',
        'status' => 'completed',
    ]);

    return [
        'tenantId' => $tenantId,
        'companyId' => $companyId,
        'evaluationId' => (int) $evaluation->id,
        'hr' => $hr,
        'hod' => $hod,
        'employee' => $participant,
    ];
}

test('hr opens a support follow-up and one audit entry records the transition', function (): void {
    $fixture = evaluationFollowupFixture();
    $store = app(TrainingEvaluationFollowupStore::class);

    $followup = $store->open($fixture['hr'], $fixture['companyId'], $fixture['evaluationId'], 'support_request');

    expect($followup->kind)->toBe(TrainingEvaluationFollowupKind::SupportRequest)
        ->and($followup->status)->toBe(TrainingEvaluationFollowupStatus::Open)
        ->and((int) $followup->evaluation_id)->toBe($fixture['evaluationId']);

    $audits = TrainingEvaluationFollowupAudit::query()->forCompany($fixture['tenantId'], $fixture['companyId'])
        ->where('training_evaluation_followup_id', $followup->id)->orderBy('id')->get();
    expect($audits)->toHaveCount(1);
    expect((int) $audits[0]->actor_user_id)->toBe((int) $fixture['hr']->id)
        ->and($audits[0]->kind)->toBe('support_request')
        ->and($audits[0]->status)->toBe('open')
        ->and((int) TrainingEvaluationFollowup::query()->forCompany($fixture['tenantId'], $fixture['companyId'])->whereKey($audits[0]->training_evaluation_followup_id)->sole()->evaluation_id)
        ->toBe($fixture['evaluationId']);
});

test('opening a follow-up in another company is refused and writes nothing', function (): void {
    $fixture = evaluationFollowupFixture();
    $otherCompanyId = (int) Company::factory()->create(['tenant_id' => $fixture['tenantId']])->id;
    $store = app(TrainingEvaluationFollowupStore::class);

    expect(fn () => $store->open($fixture['hr'], $otherCompanyId, $fixture['evaluationId'], 'support_request'))
        ->toThrow(InvalidTrainingEvaluationException::class);

    expect(TrainingEvaluationFollowup::query()->forCompany($fixture['tenantId'], $fixture['companyId'])->count())->toBe(0)
        ->and(TrainingEvaluationFollowupAudit::query()->forCompany($fixture['tenantId'], $fixture['companyId'])->count())->toBe(0);
});

test('opening a follow-up in another tenant is refused and writes nothing', function (): void {
    $fixture = evaluationFollowupFixture();
    [$otherTenant, $otherCompany] = createTenantWithCompany();
    $store = app(TrainingEvaluationFollowupStore::class);

    expect(fn () => $store->open($fixture['hr'], (int) $otherCompany->id, $fixture['evaluationId'], 'support_request'))
        ->toThrow(InvalidTrainingEvaluationException::class);

    expect(TrainingEvaluationFollowup::query()->forCompany($fixture['tenantId'], $fixture['companyId'])->count())->toBe(0)
        ->and(TrainingEvaluationFollowupAudit::query()->forCompany($fixture['tenantId'], $fixture['companyId'])->count())->toBe(0);
});

test('an employee is refused on every store method', function (): void {
    $fixture = evaluationFollowupFixture();
    $store = app(TrainingEvaluationFollowupStore::class);

    expect(fn () => $store->open($fixture['employee'], $fixture['companyId'], $fixture['evaluationId'], 'support_request'))
        ->toThrow(AuthorizationDeniedException::class);
    expect(fn () => $store->progress($fixture['employee'], $fixture['companyId'], 1, 'Called them.'))
        ->toThrow(AuthorizationDeniedException::class);
    expect(fn () => $store->close($fixture['employee'], $fixture['companyId'], 1, 'Done.'))
        ->toThrow(AuthorizationDeniedException::class);

    expect(TrainingEvaluationFollowup::query()->forCompany($fixture['tenantId'], $fixture['companyId'])->count())->toBe(0);
});

test('a hod is refused on every store method', function (): void {
    $fixture = evaluationFollowupFixture();
    $store = app(TrainingEvaluationFollowupStore::class);

    expect(fn () => $store->open($fixture['hod'], $fixture['companyId'], $fixture['evaluationId'], 'provider_concern'))
        ->toThrow(AuthorizationDeniedException::class);
    expect(fn () => $store->progress($fixture['hod'], $fixture['companyId'], 1, 'Called them.'))
        ->toThrow(AuthorizationDeniedException::class);
    expect(fn () => $store->close($fixture['hod'], $fixture['companyId'], 1, 'Done.'))
        ->toThrow(AuthorizationDeniedException::class);

    expect(TrainingEvaluationFollowup::query()->forCompany($fixture['tenantId'], $fixture['companyId'])->count())->toBe(0);
});

test('progress and close leave the evaluation row untouched', function (): void {
    $fixture = evaluationFollowupFixture();
    $store = app(TrainingEvaluationFollowupStore::class);

    $before = TrainingEvaluation::query()->forCompany($fixture['tenantId'], $fixture['companyId'])->whereKey($fixture['evaluationId'])->sole()->getAttributes();

    $followup = $store->open($fixture['hr'], $fixture['companyId'], $fixture['evaluationId'], 'provider_concern');
    expect(TrainingEvaluation::query()->forCompany($fixture['tenantId'], $fixture['companyId'])->whereKey($fixture['evaluationId'])->sole()->getAttributes())->toBe($before);

    $store->progress($fixture['hr'], $fixture['companyId'], (int) $followup->id, 'Emailed the provider.');
    expect(TrainingEvaluation::query()->forCompany($fixture['tenantId'], $fixture['companyId'])->whereKey($fixture['evaluationId'])->sole()->getAttributes())->toBe($before);

    $store->close($fixture['hr'], $fixture['companyId'], (int) $followup->id, 'Provider sent corrected slides.');
    expect(TrainingEvaluation::query()->forCompany($fixture['tenantId'], $fixture['companyId'])->whereKey($fixture['evaluationId'])->sole()->getAttributes())->toBe($before);
});

test('one open follow-up per kind, reopen allowed after close', function (): void {
    $fixture = evaluationFollowupFixture();
    $store = app(TrainingEvaluationFollowupStore::class);

    $first = $store->open($fixture['hr'], $fixture['companyId'], $fixture['evaluationId'], 'support_request');

    expect(fn () => $store->open($fixture['hr'], $fixture['companyId'], $fixture['evaluationId'], 'support_request'))
        ->toThrow(InvalidTrainingEvaluationException::class);

    $other = $store->open($fixture['hr'], $fixture['companyId'], $fixture['evaluationId'], 'provider_concern');
    expect($other->kind)->toBe(TrainingEvaluationFollowupKind::ProviderConcern);

    $store->close($fixture['hr'], $fixture['companyId'], (int) $first->id, 'Mentor assigned.');

    $reopened = $store->open($fixture['hr'], $fixture['companyId'], $fixture['evaluationId'], 'support_request');
    expect((int) $reopened->id)->not->toBe((int) $first->id)
        ->and($reopened->status)->toBe(TrainingEvaluationFollowupStatus::Open);
});

test('close without action taken is refused', function (): void {
    $fixture = evaluationFollowupFixture();
    $store = app(TrainingEvaluationFollowupStore::class);

    $followup = $store->open($fixture['hr'], $fixture['companyId'], $fixture['evaluationId'], 'support_request');

    expect(fn () => $store->close($fixture['hr'], $fixture['companyId'], (int) $followup->id, null))
        ->toThrow(InvalidTrainingEvaluationException::class);
    expect(fn () => $store->close($fixture['hr'], $fixture['companyId'], (int) $followup->id, '   '))
        ->toThrow(InvalidTrainingEvaluationException::class);

    expect($followup->refresh()->status)->toBe(TrainingEvaluationFollowupStatus::Open);
});

test('the dashboard shows follow-up state per flagged evaluation only', function (): void {
    $fixture = evaluationFollowupFixture();

    $quietEmployee = NativeWorkforceFixture::create($fixture['tenantId'], WorkforceResourceType::Employee, $fixture['companyId']);
    $quietParticipant = TrainingParticipant::query()->create([
        'tenant_id' => $fixture['tenantId'],
        'company_entity_id' => $fixture['companyId'],
        'event_id' => TrainingEvaluation::query()->forCompany($fixture['tenantId'], $fixture['companyId'])
            ->whereKey($fixture['evaluationId'])->sole()->event_id,
        'provider_id' => 'native',
        'employee_subject_id' => (string) $quietEmployee->id,
        'workforce_observed_at' => now(),
    ]);
    $quietEvaluation = TrainingEvaluation::query()->create([
        'tenant_id' => $fixture['tenantId'],
        'company_entity_id' => $fixture['companyId'],
        'participant_id' => (int) $quietParticipant->id,
        'event_id' => (int) $quietParticipant->event_id,
        'employee_subject_id' => (string) $quietEmployee->id,
        'criteria_version' => '2026.1',
        'relevance' => 5,
        'overall_satisfaction' => 5,
        'status' => 'completed',
    ]);

    $page = Livewire::actingAs($fixture['hr'])->test(EvaluationsDashboard::class);

    $events = $page->viewData('events');
    $flaggedIds = collect($events)->flatMap(fn (array $event): array => $event['flagged'])->pluck('evaluation_id')->all();
    expect($flaggedIds)->toBe([$fixture['evaluationId']]);

    $page->assertSee('Open support follow-up')
        ->assertSee('Open provider concern')
        ->assertSee('followup-'.$fixture['evaluationId'])
        ->assertDontSee('followup-'.$quietEvaluation->id);
});

test('hr drives a follow-up open, progress and close from the dashboard', function (): void {
    $fixture = evaluationFollowupFixture();

    $page = Livewire::actingAs($fixture['hr'])->test(EvaluationsDashboard::class)
        ->call('openFollowup', $fixture['evaluationId'], 'support_request')
        ->assertSee('Support request follow-up: open');

    $followupId = (int) TrainingEvaluationFollowup::query()
        ->forCompany($fixture['tenantId'], $fixture['companyId'])
        ->where('evaluation_id', $fixture['evaluationId'])->sole()->id;

    $page->set("followupNotes.{$followupId}", 'Called the mentor.')
        ->call('progressFollowup', $followupId)
        ->assertSee('Support request follow-up: in progress')
        ->set("followupNotes.{$followupId}", 'Mentor assigned.')
        ->call('closeFollowup', $followupId)
        ->assertSee('Support request follow-up: closed')
        ->assertSee('Open support follow-up');
});

test('a hod sees no follow-up action row and cannot act', function (): void {
    $fixture = evaluationFollowupFixture();

    Livewire::actingAs($fixture['hod'])->test(EvaluationsDashboard::class)
        ->assertOk()
        ->assertDontSee('Open support follow-up')
        ->assertDontSee('Open provider concern')
        ->call('openFollowup', $fixture['evaluationId'], 'support_request')
        ->assertForbidden();
});

test('each transition writes one audit entry in order', function (): void {
    $fixture = evaluationFollowupFixture();
    $store = app(TrainingEvaluationFollowupStore::class);

    $followup = $store->open($fixture['hr'], $fixture['companyId'], $fixture['evaluationId'], 'support_request');
    $store->progress($fixture['hr'], $fixture['companyId'], (int) $followup->id, 'Called the mentor.');
    $store->close($fixture['hr'], $fixture['companyId'], (int) $followup->id, 'Mentor assigned.');

    $audits = TrainingEvaluationFollowupAudit::query()->forCompany($fixture['tenantId'], $fixture['companyId'])
        ->where('training_evaluation_followup_id', $followup->id)->orderBy('id')->get();

    expect($audits->pluck('status')->all())->toBe(['open', 'in_progress', 'closed']);
    expect($audits->pluck('actor_user_id')->map(fn ($id): int => (int) $id)->unique()->all())
        ->toBe([(int) $fixture['hr']->id]);
    expect($audits[2]->action_taken)->toBe('Mentor assigned.');
});
