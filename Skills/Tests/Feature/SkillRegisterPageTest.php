<?php

use App\Base\Audit\Models\AuditAction;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentResultBand;
use App\Domains\People\Skills\Livewire\Register\Index;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\AssessmentWorkflowContext;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Tests\Support\CompanyIsolationFixture;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Employee skill register (0014-b, #319): one HR-wide filterable view of who
 * holds which released skill level today, with expiry visibility and an
 * audited CSV export of exactly the rendered rows.
 *
 * Self-contained: every helper is prefixed skillReg and lives here. The
 * fixture mirrors the HR KPI dashboard's: four employees in two units of the
 * alpha company of a TwoCompanyTenant, two catalog skills, and finalized
 * assessments whose validity the tests control.
 */
beforeEach(function (): void {
    $this->withoutVite();
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    Carbon::setTestNow();
});

/** @return array<string, mixed> */
function skillRegFixture(): array
{
    $tenant = CompanyIsolationFixture::twoCompaniesInOneTenant('Register Alpha', 'Register Beta');
    app(TenantContext::class)->set($tenant->tenantId);
    setupAuthzRoles();
    $companyId = $tenant->alphaCompanyEntityId;
    $tag = Str::lower(Str::random(6));

    $production = skillRegUnit($companyId, 'prod-'.$tag, 'Production');
    $engineering = skillRegUnit($companyId, 'eng-'.$tag, 'Engineering');
    $p1 = skillRegEmployee($companyId, $production, 'Reg Anna');
    $p2 = skillRegEmployee($companyId, $production, 'Reg Brian');
    $e1 = skillRegEmployee($companyId, $engineering, 'Reg Clara');

    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory($companyId, 'reg-'.$tag, 'Register');
    $skillA = (int) $catalog->defineSkill($companyId, new SkillDraft('reg-'.$tag.'.a', 'Register skill A', 'First register skill.', (int) $category->id))->id;
    $skillB = (int) $catalog->defineSkill($companyId, new SkillDraft('reg-'.$tag.'.b', 'Register skill B', 'Second register skill.', (int) $category->id))->id;

    $f = [
        'tenant' => $tenant, 'tenantId' => $tenant->tenantId, 'companyId' => $companyId,
        'production' => $production, 'engineering' => $engineering,
        'p1' => $p1, 'p2' => $p2, 'e1' => $e1, 'skillA' => $skillA, 'skillB' => $skillB,
        'hr' => skillRegUser($companyId, 'people_hr'), 'hod' => skillRegUser($companyId, 'people_hod'),
        'siblingHr' => skillRegUser($tenant->betaCompanyEntityId, 'people_hr'),
    ];

    // Anna holds both skills at released levels with no expiry.
    skillRegAssessment($f, $p1, $skillA, AssessmentResultBand::Meets);
    skillRegAssessment($f, $p1, $skillB, AssessmentResultBand::Exceeds);
    // Brian's newer A assessment lapsed, so the older valid one still counts.
    skillRegAssessment($f, $p2, $skillA, AssessmentResultBand::Meets, extra: ['assessed_at' => now()->subDays(3)]);
    skillRegAssessment($f, $p2, $skillA, AssessmentResultBand::Exceeds, extra: ['assessed_at' => now()->subDay(), 'valid_until' => now()->subDay()->toDateString()]);
    // Clara's only B assessment lapsed: expired with no current level.
    skillRegAssessment($f, $e1, $skillB, AssessmentResultBand::Meets, extra: ['valid_until' => now()->subDay()->toDateString()]);
    // Clara's A level is a live gap, so level filters have something to drop.
    skillRegAssessment($f, $e1, $skillA, AssessmentResultBand::MajorGap);

    return $f;
}

function skillRegUnit(int $companyId, string $code, string $name): PeopleReferenceEntry
{
    return PeopleReferenceEntry::query()->create([
        'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => $code, 'name' => $name, 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
}

function skillRegEmployee(int $companyId, ?PeopleReferenceEntry $unit, string $name): Employee
{
    $employee = Employee::factory()->create([
        'company_id' => $companyId, 'full_name' => $name, 'short_name' => null, 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    if ($unit !== null) {
        EmployeeWorkProfile::query()->create(['employee_id' => $employee->id, 'organization_unit_id' => $unit->id]);
    }

    return $employee;
}

function skillRegUser(int $companyId, string $roleCode): User
{
    $user = User::factory()->create(['company_id' => $companyId]);
    PrincipalRole::query()->create([
        'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value, 'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->valueOrFail('id'),
    ]);

    return $user;
}

/**
 * One finalized assessment, marched through the workflow states the way the
 * store does, so the history guards see a legitimate row.
 *
 * @param  array<string, mixed>  $extra  columns set on the draft (assessed_at, valid_until)
 */
function skillRegAssessment(array $f, Employee $employee, int $skillId, AssessmentResultBand $band, array $extra = []): SkillAssessment
{
    $assessed = match ($band) {
        AssessmentResultBand::Exceeds => 4, AssessmentResultBand::Meets => 3, AssessmentResultBand::MinorGap => 2,
        AssessmentResultBand::MajorGap => 1, default => 0,
    };

    return AssessmentWorkflowContext::runStoreMutation(function () use ($f, $employee, $skillId, $band, $extra, $assessed): SkillAssessment {
        $assessment = SkillAssessment::query()->create([
            'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'],
            'employee_entity_id' => $employee->id, 'skill_id' => $skillId,
            'requirement_reference' => 'reg.role', 'requirement_version' => 1, 'required_level' => 3,
            'assessed_level' => $assessed, 'gap' => max(3 - $assessed, 0), 'result_band' => $band,
            'criticality' => 'critical', 'mandatory_gate' => true,
            'method' => 'direct_observation', 'cycle' => 'annual', 'status' => 'submitted',
            'evidence' => 'Observed.', 'assessed_at' => now()->subDay(), 'assessor_user_id' => $f['hr']->id,
            'hod_verification' => 'pending', ...$extra,
        ]);
        $assessment->update(['status' => 'pending_hod_verification']);
        $assessment->update(['hod_verification' => 'verified', 'hod_verifier_user_id' => $f['hod']->id, 'hod_verified_at' => now()]);
        $assessment->update(['status' => 'finalized', 'finalized_at' => now(), 'finalized_by_user_id' => $f['hod']->id]);

        return $assessment;
    });
}

/** The recorder buffers until the request ends; the platform's own tests flush the same way. */
function skillRegFlushAudit(): void
{
    $buffer = app(AuditBuffer::class);
    (new ReflectionClass($buffer))->getMethod('flush')->invoke($buffer);
}

function skillRegPage(array $f, User $actor)
{
    return Livewire::actingAs($actor)->test(Index::class);
}

test('the page and the export refuse a user without the register capability', function (): void {
    $f = skillRegFixture();
    $before = AuditAction::query()->count();

    $this->actingAs($f['hod'])->get(route('people.skill.register.index'))->assertForbidden();

    // Guard: the route middleware, the mount gate and the export action all
    // answer through the same view capability (subsequent Livewire calls run
    // without mount, so export is refused on its own request too).
    Livewire::actingAs($f['hod'])->test(Index::class)->assertForbidden()->call('export')->assertForbidden();

    // Guard: a refused export records nothing.
    skillRegFlushAudit();
    expect(AuditAction::query()->count())->toBe($before);
});

test('rows from the sibling company and from another tenant never appear', function (): void {
    $f = skillRegFixture();
    /** @var \App\Domains\People\Skills\Tests\Support\TwoCompanyTenant $tenant */
    $tenant = $f['tenant'];

    $sibling = skillRegPage($f, $f['siblingHr'])->assertOk()->assertSet('companyEntityId', $tenant->betaCompanyEntityId);
    expect($sibling->viewData('rows'))->toHaveCount(0);
    expect($sibling->viewData('companies'))->not->toHaveKey($f['companyId']);
    $sibling->call('selectCompany', $f['companyId'])->assertNotFound();

    [$otherTenant, $otherCompany] = createTenantWithCompany(['name' => 'Register Other Tenant'], ['name' => 'Register Other Co', 'status' => 'active']);
    app(TenantContext::class)->set((int) $otherTenant->id);
    $stranger = Employee::factory()->create(['company_id' => $otherCompany->id, 'full_name' => 'Reg Stranger', 'status' => 'active', 'employee_type' => 'full_time']);
    DB::table('people_connector_skill_assessments')->insert([
        'tenant_id' => $otherTenant->id, 'company_entity_id' => $otherCompany->id, 'employee_entity_id' => $stranger->id,
        'skill_id' => $f['skillA'], 'requirement_reference' => 'other', 'requirement_version' => 1, 'required_level' => 3,
        'assessed_level' => 3, 'gap' => 0, 'result_band' => 'meets', 'criticality' => 'critical', 'method' => 'direct_observation',
        'cycle' => 'annual', 'status' => 'finalized', 'finalized_at' => now(), 'hod_verification' => 'verified',
        'assessed_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(TenantContext::class)->set($f['tenantId']);

    // Guard: forCompany pins tenant and company on every read.
    $rows = skillRegPage($f, $f['hr'])->assertOk()->viewData('rows');
    expect($rows->pluck('employee')->all())->not->toContain('Reg Stranger');
});

test('the current level follows the latest non-expired assessment', function (): void {
    $f = skillRegFixture();
    $rows = skillRegPage($f, $f['hr'])->assertOk()->viewData('rows')
        ->keyBy(fn (array $row): string => $row['employee_id'].':'.$row['skill_id']);

    // Guard: Brian's lapsed newer Exceeds does not displace the older valid Meets.
    expect($rows[$f['p2']->id.':'.$f['skillA']])->toMatchArray([
        'employee' => 'Reg Brian', 'department' => 'Production',
        'skill' => 'Register skill A', 'level' => 3, 'expired' => false,
    ]);

    // Guard: an employee with only expired scores shows expired with no level.
    expect($rows[$f['e1']->id.':'.$f['skillB']])->toMatchArray([
        'employee' => 'Reg Clara', 'skill' => 'Register skill B', 'level' => null, 'expired' => true,
    ]);

    expect($rows[$f['p1']->id.':'.$f['skillA']])->toMatchArray(['level' => 3, 'expired' => false])
        ->and($rows[$f['p1']->id.':'.$f['skillB']])->toMatchArray(['level' => 4, 'expired' => false]);
});

test('the expiring-within filter boundary is date-safe', function (): void {
    Carbon::setTestNow('2026-09-07 09:00:00');
    $f = skillRegFixture();

    skillRegAssessment($f, $f['p2'], $f['skillB'], AssessmentResultBand::Meets, ['valid_until' => '2026-10-07']);
    $page = skillRegPage($f, $f['hr'])->assertOk()->set('expiringWithinDays', '30');

    // Guard: on/before as-of + 30 days counts.
    expect($page->viewData('rows')->pluck('skill')->all())->toBe(['Register skill B']);

    skillRegAssessment($f, $f['e1'], $f['skillB'], AssessmentResultBand::Meets, ['valid_until' => '2026-10-08']);

    // Guard: as-of + 31 days does not.
    expect($page->viewData('rows')->pluck('skill')->all())->toBe(['Register skill B']);

    // Guard: a time part on the boundary date still counts as that date.
    $oct8 = SkillAssessment::query()
        ->where('employee_entity_id', $f['e1']->id)->where('skill_id', $f['skillB'])
        ->orderByDesc('assessed_at')->firstOrFail();
    DB::table('people_connector_skill_assessments')->where('id', $oct8->id)->update(['valid_until' => '2026-10-07 15:00:00']);
    expect($page->viewData('rows')->count())->toBe(2);
});

test('department, skill, level and expired-only filters narrow the rows', function (): void {
    $f = skillRegFixture();
    $page = skillRegPage($f, $f['hr'])->assertOk();
    expect($page->viewData('rows'))->toHaveCount(5);

    $page->set('department', (string) $f['engineering']->id);
    expect($page->viewData('rows')->pluck('employee')->all())->toBe(['Reg Clara', 'Reg Clara']);

    $page->set('department', '')->set('skill', (string) $f['skillB']);
    expect($page->viewData('rows')->pluck('employee')->all())->toBe(['Reg Anna', 'Reg Clara']);

    // Guard: the expired-only row has no level, so a level floor drops it.
    $page->set('skill', '')->set('level', '3');
    expect($page->viewData('rows')->pluck('employee')->all())->toBe(['Reg Anna', 'Reg Anna', 'Reg Brian']);

    $page->set('level', '')->set('expiredOnly', true);
    expect($page->viewData('rows')->pluck('employee')->all())->toBe(['Reg Clara']);
});

test('rows sort by employee, skill and valid-until', function (): void {
    $f = skillRegFixture();
    $page = skillRegPage($f, $f['hr'])->assertOk();

    expect($page->viewData('rows')->pluck('employee')->all())
        ->toBe(['Reg Anna', 'Reg Anna', 'Reg Brian', 'Reg Clara', 'Reg Clara']);

    $page->set('sort', 'skill')->set('direction', 'desc');
    expect($page->viewData('rows')->pluck('skill')->all())->toBe([
        'Register skill B', 'Register skill B', 'Register skill A', 'Register skill A', 'Register skill A',
    ]);
});

test('the CSV export contains exactly the filtered rows and records one audit action per export', function (): void {
    $f = skillRegFixture();
    $before = AuditAction::query()->count();

    $page = skillRegPage($f, $f['hr'])
        ->set('skill', (string) $f['skillA'])
        ->call('export')
        ->assertFileDownloaded('skill-register-'.$f['companyId'].'-'.now()->toDateString().'.csv');

    skillRegFlushAudit();
    $csv = base64_decode($page->effects['download']['content']);
    $lines = array_values(array_filter(explode("\n", trim($csv))));
    expect($lines)->toHaveCount(4)
        ->and($lines[0])->toBe('employee,department,skill,level,assessed_on,valid_until,expired')
        ->and($csv)->toContain('Reg Anna')
        ->and($csv)->toContain('Register skill A')
        ->and($csv)->not->toContain('Register skill B')
        ->and($csv)->not->toContain('Reg Stranger');

    expect(AuditAction::query()->count())->toBe($before + 1);
    $action = AuditAction::query()->latest('id')->first();
    expect($action->event)->toBe(Index::EXPORT_EVENT)
        ->and((int) $action->actor_id)->toBe((int) $f['hr']->id)
        ->and($action->payload['context']['rows'] ?? $action->payload['rows'] ?? null)->toBe(3)
        ->and(json_encode($action->payload))->toContain('"skill":"'.$f['skillA'].'"');

    // Guard: a second export records a second action.
    skillRegPage($f, $f['hr'])->set('skill', (string) $f['skillA'])->call('export')
        ->assertFileDownloaded('skill-register-'.$f['companyId'].'-'.now()->toDateString().'.csv');
    skillRegFlushAudit();
    expect(AuditAction::query()->count())->toBe($before + 2);
});

test('rendering and exporting the register writes no assessment row', function (): void {
    $f = skillRegFixture();
    $count = static fn (): array => [
        DB::table('people_connector_skill_assessments')->count(),
        AuditAction::query()->count(),
    ];
    $before = $count();

    skillRegPage($f, $f['hr'])->assertOk()
        ->set('department', (string) $f['production']->id)->assertOk()
        ->set('level', '3')->assertOk()
        ->call('export')->assertOk();

    // Guard: the page is read-only apart from its own audit row.
    skillRegFlushAudit();
    expect($count())->toBe([$before[0], $before[1] + 1]);
});

test('the register table carries a caption', function (): void {
    $f = skillRegFixture();
    $html = skillRegPage($f, $f['hr'])->assertOk()->html();

    expect($html)->toContain('<caption')->toContain('Employee skill register');
});
