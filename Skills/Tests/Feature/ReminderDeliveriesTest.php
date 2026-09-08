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
use App\Domains\People\Skills\Data\DevelopmentActionDraft;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Database\Seeders\RequirementProfileWorkflowSeeder;
use App\Domains\People\Skills\Enums\DevelopmentActionType;
use App\Domains\People\Skills\Enums\ReminderDeliveryState;
use App\Domains\People\Skills\Enums\ReminderRule;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Models\SkillReminderDelivery;
use App\Domains\People\Skills\Notifications\SkillReminderNotification;
use App\Domains\People\Skills\Services\DevelopmentActionStore;
use App\Domains\People\Skills\Services\ReminderDeliveries;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Training\Livewire\HrGovernance\Index as HrGovernanceIndex;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * 0009-g: one deduplicated delivery row per reminder, recipient and ISO week;
 * failures visible and retryable. Self-contained: helpers are prefixed deliv.
 */

afterEach(function (): void {
    app(TenantContext::class)->clear();
    Carbon::setTestNow();
});

beforeEach(function (): void {
    $this->withoutVite();
});

function delivRole(User $user, string $code): void
{
    PrincipalRole::query()->create([
        'company_id' => $user->company_id, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $code)->sole()->id,
    ]);
}

/**
 * One company: a department with a head who has a user, one employee in it,
 * one skill. The head is the HOD every score reminder addresses.
 *
 * @return array{companyId: int, company: Company, hod: User, hodEmployeeId: int, employeeId: int, skillId: int, departmentId: int}
 */
function delivCompany(int $tenantId, string $label): array
{
    $company = Company::factory()->create(['tenant_id' => $tenantId, 'name' => $label.' Co '.Str::lower(Str::random(6)), 'status' => 'active']);
    $companyId = (int) $company->id;

    $type = DepartmentType::query()->firstOrCreate(
        ['code' => 'ops-deliv'],
        ['name' => 'Operations deliveries', 'category' => 'operational', 'is_active' => true],
    );
    $department = Department::query()->create(['company_id' => $companyId, 'department_type_id' => $type->id, 'status' => 'active']);
    $head = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $department->id,
        'full_name' => 'Head '.$label, 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $department->update(['head_id' => $head->id]);
    $hod = User::factory()->create(['company_id' => $companyId, 'employee_id' => $head->id]);
    EmployeePortalAccess::query()->create([
        'employee_id' => $head->id, 'user_id' => $hod->id,
        'display_name' => 'Head '.$label, 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);

    $employee = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $department->id,
        'full_name' => 'Employee '.$label, 'status' => 'active', 'employee_type' => 'full_time',
    ]);

    return [
        'companyId' => $companyId,
        'company' => $company,
        'hod' => $hod,
        'hodEmployeeId' => (int) $head->id,
        'employeeId' => (int) $employee->id,
        'skillId' => delivSkill($companyId, 'deliv.'.Str::lower($label).'.'.Str::lower(Str::random(6))),
        'departmentId' => (int) $department->id,
    ];
}

/** @return array{tenantId: int, alpha: array, beta: array} */
function delivFixture(string $name): array
{
    $tenant = createTenant(['name' => $name]);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    return [
        'tenantId' => $tenantId,
        'alpha' => delivCompany($tenantId, 'Alpha'),
        'beta' => delivCompany($tenantId, 'Beta'),
    ];
}

function delivSkill(int $companyId, string $code): int
{
    $category = app(SkillCatalogStore::class)->defineCategory($companyId, 'cat-'.$code, 'Category '.$code);

    return (int) app(SkillCatalogStore::class)->defineSkill($companyId, new SkillDraft(
        code: $code, name: 'Skill '.$code, definition: 'Does the thing.', categoryId: (int) $category->id,
    ))->id;
}

/** An overdue reassessment score for the side's employee. Essential, not critical: a lone critical holder is also a coverage gap (0009-i), and this file measures score reminders. */
function delivOverdueScore(int $tenantId, array $side, array $overrides = []): EmployeeSkillScore
{
    $assessment = SkillAssessment::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $side['companyId'],
        'employee_entity_id' => $side['employeeId'], 'skill_id' => $side['skillId'],
        'requirement_reference' => 'deliv.ops', 'requirement_version' => 2,
        'required_level' => 4, 'criticality' => 'essential', 'mandatory_gate' => true,
        'assessed_level' => 2, 'gap' => 2, 'method' => 'direct_observation', 'cycle' => 'annual',
        'status' => 'draft', 'evidence' => 'Observed once.', 'assessed_at' => now()->subYear(), 'assessor_user_id' => 9,
    ]);

    return EmployeeSkillScore::query()->create(array_merge([
        'tenant_id' => $tenantId, 'company_entity_id' => $side['companyId'],
        'employee_entity_id' => $side['employeeId'], 'skill_id' => $side['skillId'],
        'source_assessment_id' => (int) $assessment->id,
        'requirement_reference' => 'deliv.ops', 'requirement_version' => 2,
        'required_level' => 4, 'current_level' => 2, 'gap' => 2, 'mandatory_gate' => true,
        'criticality' => 'essential', 'assessed_at' => now()->subYear(),
        'next_assessment_due' => now()->subDays(3)->toDateString(),
    ], $overrides));
}

/** An approved, overdue development action owned by a fresh employee who has a user. */
function delivOverdueAction(int $tenantId, array $side): array
{
    $owner = Employee::factory()->create(['company_id' => $side['companyId'], 'status' => 'active', 'department_id' => $side['departmentId']]);
    $ownerUser = User::factory()->create(['company_id' => $side['companyId'], 'employee_id' => $owner->id]);
    EmployeePortalAccess::query()->create([
        'employee_id' => $owner->id, 'user_id' => $ownerUser->id,
        'display_name' => 'Owner', 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);

    $store = app(DevelopmentActionStore::class);
    $action = $store->proposeManual($side['companyId'], new DevelopmentActionDraft(
        employeeEntityId: $side['employeeId'],
        type: DevelopmentActionType::Coaching,
        objective: 'Reach level four.',
        intervention: 'Supervised cycles.',
        expectedEvidence: 'Signed checklist.',
        ownerEmployeeEntityId: (int) $owner->id,
        hrCoordinatorEmployeeEntityId: $side['hodEmployeeId'],
        trainerEmployeeEntityId: $side['hodEmployeeId'],
        startDate: Carbon::now()->subDays(10),
        dueDate: Carbon::now()->subDay(),
        skillId: $side['skillId'],
        startingLevel: 1,
        targetLevel: 3,
        criticality: RequirementCriticality::Critical,
        manualReason: 'Coaching after the gap review.',
    ));

    return ['action' => $store->approve($side['companyId'], (int) $action->id, 10), 'ownerUser' => $ownerUser];
}

function delivRows(int $tenantId, int $companyId): Collection
{
    return SkillReminderDelivery::query()->forCompany($tenantId, $companyId)->orderBy('id')->get();
}

test('an overdue reassessment with a HOD user writes one sent row and one notification pointing at the matrix', function (): void {
    $f = delivFixture('Deliv Send Tenant');
    $a = $f['alpha'];
    delivOverdueScore($f['tenantId'], $a);
    Notification::fake();

    $result = app(ReminderDeliveries::class)->send($a['companyId']);

    expect($result->sent)->toBe(1)->and($result->skipped)->toBe(0)->and($result->failed)->toBe(0);

    $rows = delivRows($f['tenantId'], $a['companyId']);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->state)->toBe(ReminderDeliveryState::Sent)
        ->and($rows[0]->rule)->toBe(ReminderRule::OverdueReassessment)
        ->and((int) $rows[0]->recipient_user_id)->toBe((int) $a['hod']->id)
        ->and($rows[0]->period_key)->toBe(ReminderDeliveries::periodKey(now()))
        ->and($rows[0]->sent_at)->not->toBeNull()
        ->and($rows[0]->failure)->toBeNull();

    Notification::assertSentTo($a['hod'], SkillReminderNotification::class, function (SkillReminderNotification $notification) use ($a): bool {
        return $notification->url === route('people.skill.assessment.matrix', ['employee' => $a['employeeId']])
            && $notification->reminder->rule === ReminderRule::OverdueReassessment;
    });
    Notification::assertCount(1);
});

test('a second run in the same ISO week writes no row and sends nothing; the next week sends again', function (): void {
    $f = delivFixture('Deliv Week Tenant');
    $a = $f['alpha'];
    delivOverdueScore($f['tenantId'], $a);
    Notification::fake();
    $deliveries = app(ReminderDeliveries::class);

    $first = $deliveries->send($a['companyId']);
    $second = $deliveries->send($a['companyId']);

    expect($first->sent)->toBe(1)
        ->and($second->sent)->toBe(0)
        ->and($second->skipped)->toBe(1)
        ->and($second->skipReasons)->toBe([ReminderDeliveries::SKIP_ALREADY_DELIVERED => 1])
        ->and(delivRows($f['tenantId'], $a['companyId']))->toHaveCount(1);
    Notification::assertCount(1);

    $nextWeek = $deliveries->send($a['companyId'], now()->addWeek());

    expect($nextWeek->sent)->toBe(1)
        ->and(delivRows($f['tenantId'], $a['companyId'])->pluck('period_key')->unique())->toHaveCount(2);
    Notification::assertCount(2);
});

test('an overdue development action notifies the owner, not the HOD, and stores the action id', function (): void {
    $f = delivFixture('Deliv Owner Tenant');
    $a = $f['alpha'];
    ['action' => $action, 'ownerUser' => $ownerUser] = delivOverdueAction($f['tenantId'], $a);
    Notification::fake();

    $result = app(ReminderDeliveries::class)->send($a['companyId']);

    $rows = delivRows($f['tenantId'], $a['companyId']);
    expect($result->sent)->toBe(1)
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]->rule)->toBe(ReminderRule::OverdueDevelopmentAction)
        ->and((int) $rows[0]->recipient_user_id)->toBe((int) $ownerUser->id)
        ->and($rows[0]->developmentActionId())->toBe((int) $action->id);

    Notification::assertSentTo($ownerUser, SkillReminderNotification::class, fn (SkillReminderNotification $n): bool => $n->url === route('people.skill.development-actions.index', ['action' => (int) $action->id]));
    Notification::assertNotSentTo($a['hod'], SkillReminderNotification::class);
});

test('a notify() fault leaves the row failed with the message, retry() then sends once, and a sent row is never retried', function (): void {
    $f = delivFixture('Deliv Retry Tenant');
    $a = $f['alpha'];
    delivOverdueScore($f['tenantId'], $a);
    $deliveries = app(ReminderDeliveries::class);

    Event::listen(NotificationSending::class, function (): void {
        throw new RuntimeException('mail relay refused the connection');
    });
    $faulted = $deliveries->send($a['companyId']);
    Event::forget(NotificationSending::class);

    $row = delivRows($f['tenantId'], $a['companyId'])->sole();
    expect($faulted->failed)->toBe(1)->and($faulted->sent)->toBe(0)
        ->and($faulted->failures)->toHaveCount(1)
        ->and($row->state)->toBe(ReminderDeliveryState::Failed)
        ->and($row->failure)->toBe('mail relay refused the connection')
        ->and($row->sent_at)->toBeNull()
        ->and(DB::table('notifications')->where('notifiable_id', $a['hod']->id)->count())->toBe(0);

    $retried = $deliveries->retry($a['companyId']);

    $row->refresh();
    expect($retried->sent)->toBe(1)->and($retried->failed)->toBe(0)
        ->and($row->state)->toBe(ReminderDeliveryState::Sent)
        ->and($row->sent_at)->not->toBeNull()
        ->and($row->failure)->toBeNull()
        ->and(DB::table('notifications')->where('notifiable_id', $a['hod']->id)->count())->toBe(1);

    // The sent row is not a candidate: a second retry finds nothing to do and
    // the recipient's inbox does not grow.
    $again = $deliveries->retry($a['companyId']);
    expect($again->sent)->toBe(0)->and($again->failed)->toBe(0)
        ->and(DB::table('notifications')->where('notifiable_id', $a['hod']->id)->count())->toBe(1)
        ->and(delivRows($f['tenantId'], $a['companyId']))->toHaveCount(1);
});

test('a reminder whose employee has no HOD user is counted as skipped with its reason, and no row is written', function (): void {
    $f = delivFixture('Deliv Nobody Tenant');
    $a = $f['alpha'];
    $orphan = Employee::factory()->create(['company_id' => $a['companyId'], 'status' => 'active', 'department_id' => null]);
    delivOverdueScore($f['tenantId'], array_merge($a, ['employeeId' => (int) $orphan->id]));
    Notification::fake();

    $result = app(ReminderDeliveries::class)->send($a['companyId']);

    expect($result->sent)->toBe(0)->and($result->failed)->toBe(0)
        ->and($result->skipped)->toBe(1)
        ->and($result->skipReasons)->toBe([ReminderDeliveries::SKIP_NO_HEAD_USER => 1])
        ->and(delivRows($f['tenantId'], $a['companyId']))->toHaveCount(0);
    Notification::assertNothingSent();
});

test('--dry-run prints the three counts, writes no row and sends nothing', function (): void {
    $f = delivFixture('Deliv Dry Tenant');
    $a = $f['alpha'];
    delivOverdueScore($f['tenantId'], $a);
    Notification::fake();
    $before = DB::table('people_connector_skill_reminder_deliveries')->count();

    $this->artisan('people:reminders-send', ['--tenant' => $f['tenantId'], '--company' => $a['companyId'], '--dry-run' => true])
        ->expectsOutputToContain('sent: 1')
        ->expectsOutputToContain('skipped: 0')
        ->expectsOutputToContain('failed: 0')
        ->expectsOutputToContain('Dry run: nothing was written or sent.')
        ->assertExitCode(0);

    expect(DB::table('people_connector_skill_reminder_deliveries')->count())->toBe($before);
    Notification::assertNothingSent();
});

test('the command sends and prints counts, and refuses to run without a tenant scope', function (): void {
    $f = delivFixture('Deliv Command Tenant');
    $a = $f['alpha'];
    delivOverdueScore($f['tenantId'], $a);
    Notification::fake();

    $this->artisan('people:reminders-send', ['--company' => $a['companyId']])
        ->expectsOutputToContain('A --tenant=<id> option is required before this command can run.')
        ->assertExitCode(1);
    expect(DB::table('people_connector_skill_reminder_deliveries')->count())->toBe(0);

    $this->artisan('people:reminders-send', ['--tenant' => $f['tenantId'], '--company' => $a['companyId']])
        ->expectsOutputToContain('sent: 1')
        ->expectsOutputToContain('skipped: 0')
        ->expectsOutputToContain('failed: 0')
        ->assertExitCode(0);
    app(TenantContext::class)->set($f['tenantId']);

    expect(delivRows($f['tenantId'], $a['companyId']))->toHaveCount(1);
    Notification::assertCount(1);
});

test('the sibling company is neither sent nor logged, and another tenant\'s rows are not loaded', function (): void {
    $f = delivFixture('Deliv Sibling Tenant');
    $a = $f['alpha'];
    $b = $f['beta'];
    delivOverdueScore($f['tenantId'], $a);
    delivOverdueScore($f['tenantId'], $b);

    $g = delivFixture('Deliv Away Tenant');
    delivOverdueScore($g['tenantId'], $g['alpha']);
    app(TenantContext::class)->set($f['tenantId']);
    Notification::fake();

    $result = app(ReminderDeliveries::class)->send($a['companyId']);

    expect($result->sent)->toBe(1)
        ->and(delivRows($f['tenantId'], $a['companyId']))->toHaveCount(1)
        ->and(delivRows($f['tenantId'], $b['companyId']))->toHaveCount(0)
        ->and(SkillReminderDelivery::query()->forCompany($g['tenantId'], $g['alpha']['companyId'])->count())->toBe(0);
    Notification::assertSentTo($a['hod'], SkillReminderNotification::class);
    Notification::assertNotSentTo($b['hod'], SkillReminderNotification::class);
    Notification::assertNotSentTo($g['alpha']['hod'], SkillReminderNotification::class);
});

test('the HR governance page lists only the acting company\'s failed rows under a captioned table', function (): void {
    $f = delivFixture('Deliv Page Tenant');
    (new RequirementProfileWorkflowSeeder)->run();
    $a = $f['alpha'];
    $b = $f['beta'];
    $hrAlpha = User::factory()->create(['company_id' => $a['companyId']]);
    delivRole($hrAlpha, 'people_hr');
    $hrBeta = User::factory()->create(['company_id' => $b['companyId']]);
    delivRole($hrBeta, 'people_hr');

    $seed = function (array $side, ReminderDeliveryState $state, string $failure) use ($f): SkillReminderDelivery {
        return SkillReminderDelivery::query()->create([
            'tenant_id' => $f['tenantId'], 'company_entity_id' => $side['companyId'],
            'rule' => ReminderRule::OverdueReassessment, 'employee_entity_id' => $side['employeeId'],
            'skill_id' => $side['skillId'], 'due_on' => now()->subDays(3)->toDateString(),
            'period_key' => ReminderDeliveries::periodKey(now()), 'recipient_user_id' => $side['hod']->id,
            'state' => $state, 'failure' => $state === ReminderDeliveryState::Failed ? $failure : null,
            'attempted_at' => now(), 'sent_at' => $state === ReminderDeliveryState::Sent ? now() : null,
        ]);
    };
    $mine = $seed($a, ReminderDeliveryState::Failed, 'alpha relay refused');
    $seed(array_merge($a, ['skillId' => delivSkill($a['companyId'], 'deliv.alpha.sent')]), ReminderDeliveryState::Sent, 'alpha delivered fine');
    $seed($b, ReminderDeliveryState::Failed, 'beta relay refused');

    $page = Livewire::actingAs($hrAlpha)->test(HrGovernanceIndex::class)->assertOk();

    expect($page->viewData('failedDeliveries')->pluck('id')->all())->toBe([$mine->id]);
    $page->assertSee('Skill reminder deliveries that failed')
        ->assertSee('alpha relay refused')
        ->assertDontSee('beta relay refused');

    Livewire::actingAs($hrBeta)->test(HrGovernanceIndex::class)->assertOk()
        ->assertDontSee('alpha relay refused')
        ->assertSee('beta relay refused');
});
