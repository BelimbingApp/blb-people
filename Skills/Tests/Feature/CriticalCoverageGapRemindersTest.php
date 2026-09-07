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
use App\Domains\People\Skills\Data\DueReminder;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Database\Seeders\RequirementProfileWorkflowSeeder;
use App\Domains\People\Skills\Enums\AssessmentCycle;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\AssessmentResultBand;
use App\Domains\People\Skills\Enums\AssessmentStatus;
use App\Domains\People\Skills\Enums\HodVerification;
use App\Domains\People\Skills\Enums\ReminderDeliveryState;
use App\Domains\People\Skills\Enums\ReminderRule;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Models\SkillReminderDelivery;
use App\Domains\People\Skills\Notifications\SkillReminderNotification;
use App\Domains\People\Skills\Services\AssessmentWorkflowContext;
use App\Domains\People\Skills\Services\ReminderDeliveries;
use App\Domains\People\Skills\Services\ReminderRules;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Training\Livewire\HrGovernance\Index as HrGovernanceIndex;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * 0009-i: a department that has lost its cover for a critical skill is told,
 * once a month, through the same ledger the other reminders use. The HOD of
 * the department and HR both hear it; a sibling department's head does not.
 * Self-contained: helpers are prefixed covgap.
 */

afterEach(function (): void {
    app(TenantContext::class)->clear();
    Carbon::setTestNow();
});

beforeEach(function (): void {
    $this->withoutVite();
});

function covgapRole(User $user, string $code): void
{
    PrincipalRole::query()->create([
        'company_id' => $user->company_id, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $code)->sole()->id,
    ]);
}

/** @return array{id: int, hod: User, headId: int} */
function covgapDepartment(int $companyId, string $typeCode, string $label): array
{
    $type = DepartmentType::query()->firstOrCreate(
        ['code' => $typeCode],
        ['name' => 'Dept '.$typeCode, 'category' => 'operational', 'is_active' => true],
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

    return ['id' => (int) $department->id, 'hod' => $hod, 'headId' => (int) $head->id];
}

/**
 * One company: departments X and Y, each headed by a user; one HR user; one
 * critical skill. The backup minimum is the platform default of two.
 *
 * @return array{companyId: int, hr: User, x: array, y: array, skillId: int}
 */
function covgapCompany(int $tenantId, string $label): array
{
    $company = Company::factory()->create(['tenant_id' => $tenantId, 'name' => $label.' Co '.Str::lower(Str::random(6)), 'status' => 'active']);
    $companyId = (int) $company->id;
    $hr = User::factory()->create(['company_id' => $companyId]);
    covgapRole($hr, 'people_hr');

    $category = app(SkillCatalogStore::class)->defineCategory($companyId, 'cat-covgap', 'Coverage category');
    $skillId = (int) app(SkillCatalogStore::class)->defineSkill($companyId, new SkillDraft(
        code: 'covgap.isolation', name: 'Energy isolation '.$label, definition: 'Isolate before work.', categoryId: (int) $category->id,
    ))->id;

    return [
        'companyId' => $companyId,
        'hr' => $hr,
        'x' => covgapDepartment($companyId, 'covgap-x', $label.' X'),
        'y' => covgapDepartment($companyId, 'covgap-y', $label.' Y'),
        'skillId' => $skillId,
    ];
}

/** @return array{tenantId: int, alpha: array, beta: array} */
function covgapFixture(string $name): array
{
    $tenant = createTenant(['name' => $name]);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    return [
        'tenantId' => $tenantId,
        'alpha' => covgapCompany($tenantId, 'Alpha'),
        'beta' => covgapCompany($tenantId, 'Beta'),
    ];
}

/** A holder of the side's critical skill in the department: a finalized assessment and the released score. */
function covgapHolder(int $tenantId, array $side, array $department, int $level = 3, ?string $validUntil = null): EmployeeSkillScore
{
    $employee = Employee::factory()->create([
        'company_id' => $side['companyId'], 'department_id' => $department['id'],
        'full_name' => 'Holder '.Str::random(4), 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $gap = max(3 - $level, 0);
    $assessment = AssessmentWorkflowContext::runStoreMutation(static fn (): SkillAssessment => SkillAssessment::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $side['companyId'],
        'employee_entity_id' => $employee->id, 'skill_id' => $side['skillId'],
        'requirement_reference' => 'covgap.ops', 'requirement_version' => 1,
        'required_level' => 3, 'criticality' => RequirementCriticality::Critical, 'weight_percent' => 100,
        'mandatory_gate' => true, 'assessed_level' => $level, 'gap' => $gap,
        'weighted_gap' => $gap * 100, 'priority_score' => $gap * 300,
        'result_band' => AssessmentResultBand::fromGap($gap, $level, 3),
        'method' => AssessmentMethod::DirectObservation, 'cycle' => AssessmentCycle::Annual,
        'status' => AssessmentStatus::Submitted, 'evidence' => 'Observed task.', 'assessed_at' => now()->subDays(3),
        'assessor_user_id' => 9, 'hod_verification' => HodVerification::Pending,
    ]));
    // Finalized through the lifecycle the workflow guard accepts, one step
    // per save: the coverage rule reads the released score, but the score
    // this fixture releases is one a finalized assessment stands behind.
    foreach ([
        ['status' => AssessmentStatus::PendingHodVerification],
        ['hod_verification' => HodVerification::Verified, 'hod_verifier_user_id' => 10, 'hod_verified_at' => now()->subDays(2)],
        ['status' => AssessmentStatus::Finalized, 'finalized_at' => now()->subDays(2), 'finalized_by_user_id' => 10],
    ] as $attributes) {
        $assessment->fill($attributes);
        AssessmentWorkflowContext::runStoreMutation(static function () use ($assessment): void {
            $assessment->save();
        });
    }

    return EmployeeSkillScore::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $side['companyId'],
        'employee_entity_id' => $employee->id, 'skill_id' => $side['skillId'],
        'source_assessment_id' => $assessment->id,
        'requirement_reference' => 'covgap.ops', 'requirement_version' => 1,
        'required_level' => 3, 'current_level' => $level, 'gap' => $gap, 'mandatory_gate' => true,
        'criticality' => RequirementCriticality::Critical, 'assessed_at' => now()->subDays(3),
        'valid_until' => $validUntil,
    ]);
}

/** @return list<DueReminder> */
function covgapDue(int $companyId, ?DateTimeImmutable $asOf = null): array
{
    return array_values(array_filter(
        app(ReminderRules::class)->due($companyId, $asOf),
        static fn ($reminder): bool => $reminder->rule === ReminderRule::CriticalCoverageGap,
    ));
}

function covgapRows(int $tenantId, int $companyId): Collection
{
    return SkillReminderDelivery::query()->forCompany($tenantId, $companyId)
        ->where('rule', ReminderRule::CriticalCoverageGap->value)->orderBy('id')->get();
}

test('one holder under a minimum of two yields one gap reminder naming the skill and department; a second holder at level yields none', function (): void {
    $f = covgapFixture('Covgap Rule Tenant');
    $a = $f['alpha'];
    covgapHolder($f['tenantId'], $a, $a['x']);

    $due = covgapDue($a['companyId']);

    expect($due)->toHaveCount(1)
        ->and($due[0]->rule)->toBe(ReminderRule::CriticalCoverageGap)
        ->and($due[0]->skillId)->toBe($a['skillId'])
        ->and($due[0]->departmentId)->toBe($a['x']['id'])
        ->and($due[0]->holders)->toBe(1)
        ->and($due[0]->minimum)->toBe(2)
        ->and($due[0]->companyEntityId)->toBe($a['companyId']);

    covgapHolder($f['tenantId'], $a, $a['x'], level: 3);

    expect(covgapDue($a['companyId']))->toHaveCount(0);
});

test('a holder whose certificate lapsed before as-of does not count: the gap appears the day after expiry, not the day before', function (): void {
    $f = covgapFixture('Covgap Expiry Tenant');
    $a = $f['alpha'];
    covgapHolder($f['tenantId'], $a, $a['x']);
    covgapHolder($f['tenantId'], $a, $a['x'], validUntil: '2026-10-15');

    expect(covgapDue($a['companyId'], new DateTimeImmutable('2026-10-14')))->toHaveCount(0)
        ->and(covgapDue($a['companyId'], new DateTimeImmutable('2026-10-15')))->toHaveCount(0)
        ->and(covgapDue($a['companyId'], new DateTimeImmutable('2026-10-16')))->toHaveCount(1);
});

test('a holder below the required level is not cover', function (): void {
    $f = covgapFixture('Covgap Level Tenant');
    $a = $f['alpha'];
    covgapHolder($f['tenantId'], $a, $a['x']);
    covgapHolder($f['tenantId'], $a, $a['x'], level: 2);

    expect(covgapDue($a['companyId']))->toHaveCount(1);
});

test('send writes one row per recipient in month M, nothing on a second run in M, and one more per recipient in M+1', function (): void {
    $f = covgapFixture('Covgap Month Tenant');
    $a = $f['alpha'];
    covgapHolder($f['tenantId'], $a, $a['x']);
    Notification::fake();
    $deliveries = app(ReminderDeliveries::class);
    $march = new DateTimeImmutable('2027-03-02 09:00:00');

    $first = $deliveries->send($a['companyId'], $march);
    $rows = covgapRows($f['tenantId'], $a['companyId']);

    expect($first->sent)->toBe(2)->and($first->failed)->toBe(0)
        ->and($rows)->toHaveCount(2)
        ->and($rows->pluck('recipient_user_id')->map(intval(...))->sort()->values()->all())
        ->toBe(collect([(int) $a['x']['hod']->id, (int) $a['hr']->id])->sort()->values()->all())
        ->and($rows->pluck('period_key')->unique()->all())->toBe(['2027-M03'])
        ->and($rows->pluck('state')->unique()->all())->toBe([ReminderDeliveryState::Sent])
        ->and((int) $rows[0]->employee_entity_id)->toBe(0)
        ->and((int) $rows[0]->department_id)->toBe($a['x']['id'])
        ->and((int) $rows[0]->skill_id)->toBe($a['skillId']);

    // Later the same month, and even a different ISO week: still delivered.
    $second = $deliveries->send($a['companyId'], $march->modify('+3 weeks'));

    expect($second->sent)->toBe(0)
        ->and($second->skipReasons)->toBe([ReminderDeliveries::SKIP_ALREADY_DELIVERED => 2])
        ->and(covgapRows($f['tenantId'], $a['companyId']))->toHaveCount(2);

    $april = $deliveries->send($a['companyId'], $march->modify('+1 month'));

    expect($april->sent)->toBe(2)
        ->and(covgapRows($f['tenantId'], $a['companyId']))->toHaveCount(4)
        ->and(covgapRows($f['tenantId'], $a['companyId'])->where('recipient_user_id', $a['hr']->id)->pluck('period_key')->all())
        ->toBe(['2027-M03', '2027-M04']);
    Notification::assertCount(4);
});

test('the HOD of department X receives X\'s gap and not Y\'s; HR receives both, each pointing at the coverage page for the department', function (): void {
    $f = covgapFixture('Covgap Hod Tenant');
    $a = $f['alpha'];
    covgapHolder($f['tenantId'], $a, $a['x']);
    covgapHolder($f['tenantId'], $a, $a['y']);
    Notification::fake();

    $result = app(ReminderDeliveries::class)->send($a['companyId']);

    expect($result->sent)->toBe(4);
    $rows = covgapRows($f['tenantId'], $a['companyId']);
    $byRecipient = $rows->groupBy(fn (SkillReminderDelivery $row): int => (int) $row->recipient_user_id)
        ->map(fn ($group) => $group->pluck('department_id')->map(intval(...))->sort()->values()->all());

    expect($byRecipient[(int) $a['x']['hod']->id])->toBe([$a['x']['id']])
        ->and($byRecipient[(int) $a['y']['hod']->id])->toBe([$a['y']['id']])
        ->and($byRecipient[(int) $a['hr']->id])->toBe(collect([$a['x']['id'], $a['y']['id']])->sort()->values()->all());

    Notification::assertSentTo($a['x']['hod'], SkillReminderNotification::class, fn (SkillReminderNotification $n): bool => $n->url === route('people.skill.backup-coverage.index', ['department' => $a['x']['id']])
        && $n->reminder->rule === ReminderRule::CriticalCoverageGap
        && $n->reminder->departmentId === $a['x']['id']);
    Notification::assertNotSentTo($a['x']['hod'], SkillReminderNotification::class, fn (SkillReminderNotification $n): bool => $n->reminder->departmentId === $a['y']['id']);
});

test('a sibling company\'s gap never produces a reminder under company A, and another tenant\'s never at all', function (): void {
    $f = covgapFixture('Covgap Sibling Tenant');
    $a = $f['alpha'];
    $b = $f['beta'];
    covgapHolder($f['tenantId'], $a, $a['x']);
    covgapHolder($f['tenantId'], $b, $b['x']);

    $g = covgapFixture('Covgap Away Tenant');
    covgapHolder($g['tenantId'], $g['alpha'], $g['alpha']['x']);
    app(TenantContext::class)->set($f['tenantId']);
    Notification::fake();

    $due = covgapDue($a['companyId']);
    $result = app(ReminderDeliveries::class)->send($a['companyId']);

    expect($due)->toHaveCount(1)
        ->and($due[0]->companyEntityId)->toBe($a['companyId'])
        ->and($result->sent)->toBe(2)
        ->and(covgapRows($f['tenantId'], $a['companyId']))->toHaveCount(2)
        ->and(covgapRows($f['tenantId'], $b['companyId']))->toHaveCount(0)
        ->and(SkillReminderDelivery::query()->forCompany($g['tenantId'], $g['alpha']['companyId'])->count())->toBe(0);
    Notification::assertNotSentTo($b['x']['hod'], SkillReminderNotification::class);
    Notification::assertNotSentTo($b['hr'], SkillReminderNotification::class);
    Notification::assertNotSentTo($g['alpha']['hr'], SkillReminderNotification::class);
});

test('--dry-run lists the gap and writes no delivery row; reminders-due counts it', function (): void {
    $f = covgapFixture('Covgap Dry Tenant');
    $a = $f['alpha'];
    covgapHolder($f['tenantId'], $a, $a['x']);
    Notification::fake();
    $before = DB::table('people_connector_skill_reminder_deliveries')->count();

    $this->artisan('people:reminders-due', ['--tenant' => $f['tenantId'], '--company' => $a['companyId']])
        ->expectsOutputToContain('critical_coverage_gap: 1')
        ->assertExitCode(0);

    $this->artisan('people:reminders-send', ['--tenant' => $f['tenantId'], '--company' => $a['companyId'], '--dry-run' => true])
        ->expectsOutputToContain('sent: 2')
        ->expectsOutputToContain('Dry run: nothing was written or sent.')
        ->assertExitCode(0);

    expect(DB::table('people_connector_skill_reminder_deliveries')->count())->toBe($before);
    Notification::assertNothingSent();
});

test('the HR governance page lists the acting company\'s open gaps with holders, minimum and last delivery state, drilling into the coverage page', function (): void {
    $f = covgapFixture('Covgap Page Tenant');
    (new RequirementProfileWorkflowSeeder)->run();
    $a = $f['alpha'];
    $b = $f['beta'];
    covgapHolder($f['tenantId'], $a, $a['x']);
    covgapHolder($f['tenantId'], $a, $a['y']);
    covgapHolder($f['tenantId'], $a, $a['y'], level: 3);
    covgapHolder($f['tenantId'], $b, $b['x']);
    Notification::fake();
    app(ReminderDeliveries::class)->send($a['companyId'], new DateTimeImmutable('2027-05-04'));

    $page = Livewire::actingAs($a['hr'])->test(HrGovernanceIndex::class)->assertOk();
    $gaps = $page->viewData('coverageGaps');

    expect($gaps)->toHaveCount(1)
        ->and($gaps[0]['department_id'])->toBe($a['x']['id'])
        ->and($gaps[0]['skill'])->toBe('Energy isolation Alpha')
        ->and($gaps[0]['holders'])->toBe(1)
        ->and($gaps[0]['minimum'])->toBe(2)
        ->and($gaps[0]['last_delivery'])->toBe('sent 2027-M05');
    $page->assertSee('Critical coverage gaps')
        ->assertSee('Energy isolation Alpha')
        ->assertSee('sent 2027-M05')
        ->assertSee(route('people.skill.backup-coverage.index', ['department' => $a['x']['id']]), false)
        ->assertDontSee('Energy isolation Beta');

    Livewire::actingAs($b['hr'])->test(HrGovernanceIndex::class)->assertOk()
        ->assertSee('Energy isolation Beta')
        ->assertSee('never delivered')
        ->assertDontSee('Energy isolation Alpha');
});
