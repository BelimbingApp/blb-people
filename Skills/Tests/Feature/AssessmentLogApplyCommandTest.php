<?php

use App\Base\Audit\Models\AuditAction;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Skills\Data\ProficiencyLevelDraft;
use App\Domains\People\Skills\Data\RequirementItemDraft;
use App\Domains\People\Skills\Data\RequirementProfileDraft;
use App\Domains\People\Skills\Data\RequirementSelectorDraft;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Enums\SelectorType;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\AssessmentLogImporter;
use App\Domains\People\Skills\Services\ProficiencyScaleStore;
use App\Domains\People\Skills\Services\RequirementProfileStore;
use App\Domains\People\Skills\Services\SkillCatalogDefaults;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Tests\Support\CompanyIsolationFixture;
use App\Domains\People\Skills\Tests\Support\TwoCompanyTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * people:skills-assessment-log-apply (#390). Self-contained: helpers are
 * prefixed alApply; only the tests/Pest.php bootstrap helpers are shared.
 * The fixture rows are dated January/February 2026: the requirement profile
 * is published on 2026-01-01 and "today" is pinned to 2026-03-01.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-03-01 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

function alApplyFixturePath(): string
{
    return __DIR__.'/../Fixtures/skill-assessment-log.xlsx';
}

/** A copy of the fixture with one assessment-log cell replaced; caller unlinks it. */
function alApplyWithCell(string $cell, string $value): string
{
    $path = tempnam(storage_path('framework/testing'), 'assessment-log-apply-');
    copy(alApplyFixturePath(), $path);
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = $zip->getFromName('xl/worksheets/sheet3.xml');
    $next = preg_replace('/(<x:c\b[^>]*\br="'.$cell.'"[^>]*>)<x:v>[^<]*<\/x:v>/', '$1<x:v>'.$value.'</x:v>', $xml);
    expect($next)->not->toBe($xml);
    $zip->addFromString('xl/worksheets/sheet3.xml', $next);
    $zip->close();

    return $path;
}

function alApplyUser(int $companyId, string $roleCode, ?int $employeeId = null): User
{
    $user = User::factory()->create(['company_id' => $companyId, 'employee_id' => $employeeId]);
    PrincipalRole::query()->create([
        'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value, 'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->sole()->id,
    ]);

    return $user;
}

/** An active employee with a linked platform user, so the row can name them as assessor of record. */
function alApplyEmployee(int $companyId, string $staffId): Employee
{
    $employee = Employee::factory()->create(['company_id' => $companyId, 'employee_number' => $staffId, 'status' => 'active']);
    $user = alApplyUser($companyId, 'people_employee', (int) $employee->id);
    EmployeePortalAccess::query()->create([
        'employee_id' => $employee->id, 'user_id' => $user->id,
        'display_name' => $staffId, 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);

    return $employee;
}

/**
 * @param  list<string>  $requiredSkills  Skill codes the published company profile requires
 * @return array{fixture: TwoCompanyTenant, hr: User, one: Employee, two: Employee}
 */
function alApplyFixture(array $requiredSkills = ['demo-001', 'demo-002']): array
{
    $fixture = CompanyIsolationFixture::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);
    setupAuthzRoles();
    $alpha = $fixture->alphaCompanyEntityId;

    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory($alpha, 'safety', 'Safety');
    $skills = [];
    foreach (['demo-001' => 'Demo one', 'demo-002' => 'Demo two'] as $code => $name) {
        $skills[$code] = $catalog->defineSkill($alpha, new SkillDraft($code, $name, "$name skill.", (int) $category->id));
    }

    $scales = app(ProficiencyScaleStore::class);
    $draft = $scales->draft($alpha, SkillCatalogDefaults::SCALE_CODE, 'Standard scale', array_map(
        fn (int $level): ProficiencyLevelDraft => new ProficiencyLevelDraft($level, "Level {$level}", "Stage {$level}.", "Authority {$level}."),
        range(0, 5),
    ));
    $scales->publish($alpha, (int) $draft->id);

    Carbon::setTestNow('2026-01-01 09:00:00');
    $profiles = app(RequirementProfileStore::class);
    $profile = $profiles->draft($alpha, new RequirementProfileDraft(
        code: 'log.company',
        name: 'Company baseline',
        selectors: [new RequirementSelectorDraft(SelectorType::Company)],
        items: array_values(array_map(
            fn (int $sequence, string $code): RequirementItemDraft => new RequirementItemDraft((int) $skills[$code]->id, $sequence + 1, 3, RequirementCriticality::Critical, 100.0 / count($requiredSkills)),
            array_keys($requiredSkills),
            $requiredSkills,
        )),
    ));
    $profiles->publish($alpha, (int) $profile->id);
    Carbon::setTestNow('2026-03-01 09:00:00');

    return [
        'fixture' => $fixture,
        'hr' => alApplyUser($alpha, 'people_hr'),
        'one' => alApplyEmployee($alpha, 'EMP-00001'),
        'two' => alApplyEmployee($alpha, 'EMP-00002'),
    ];
}

function alApplyCommand(object $test, array $f, string $path, array $overrides = []): object
{
    return $test->artisan('people:skills-assessment-log-apply', $overrides + [
        'workbook' => $path,
        '--tenant' => $f['fixture']->tenantId,
        '--company' => $f['fixture']->alphaCompanyEntityId,
        '--as' => $f['hr']->id,
    ]);
}

/** @return array{int, int} assessment rows, score rows */
function alApplyCounts(): array
{
    return [DB::table('people_connector_skill_assessments')->count(), DB::table('people_connector_skill_employee_scores')->count()];
}

function alApplyFlushAudit(): void
{
    $buffer = app(AuditBuffer::class);
    (new ReflectionClass($buffer))->getMethod('flush')->invoke($buffer);
}

/** @return array<string, int> "employee id|skill code" => current level */
function alApplyScores(array $f): array
{
    $fixture = $f['fixture'];
    $codes = Skill::query()->forCompany($fixture->tenantId, $fixture->alphaCompanyEntityId)->pluck('code', 'id')->all();

    return DB::table('people_connector_skill_employee_scores')
        ->where('tenant_id', $fixture->tenantId)->orderBy('employee_entity_id')->orderBy('skill_id')->get()
        ->mapWithKeys(fn (object $score): array => [$score->employee_entity_id.'|'.$codes[$score->skill_id] => (int) $score->current_level])
        ->all();
}

test('a clean fixture creates three finalized assessments with sha256:row provenance and projects each current score', function (): void {
    $f = alApplyFixture();
    $sha = hash_file('sha256', alApplyFixturePath());
    [$assessments, $scores] = alApplyCounts();

    alApplyCommand($this, $f, alApplyFixturePath())
        ->expectsOutputToContain('Workbook SHA-256: '.$sha)
        ->expectsOutputToContain('Created: 3')
        ->expectsOutputToContain('Skipped: 0')
        ->expectsOutputToContain('Defects: 0')
        ->assertSuccessful();

    expect(alApplyCounts())->toBe([$assessments + 3, $scores + 3]);

    $rows = SkillAssessment::query()->forCompany($f['fixture']->tenantId, $f['fixture']->alphaCompanyEntityId)->orderBy('id')->get();
    expect($rows->pluck('status')->map(fn ($status) => $status->value)->all())->toBe(['finalized', 'finalized', 'finalized'])
        ->and($rows->pluck('source')->unique()->all())->toBe([AssessmentLogImporter::SOURCE])
        ->and($rows->pluck('source_reference')->all())->toBe([$sha.':6', $sha.':7', $sha.':8'])
        ->and($rows->pluck('finalized_by_user_id')->unique()->all())->toBe([(int) $f['hr']->id])
        ->and($rows->pluck('assessor_employee_entity_id')->all())->toBe([(int) $f['two']->id, (int) $f['one']->id, (int) $f['two']->id])
        ->and($rows->pluck('hod_decision_notes')->all())->toBe([
            '04 Assessment Log row 6 (HOD Verified? = Yes)',
            '04 Assessment Log row 7 (HOD Verified? = No)',
            '04 Assessment Log row 8 (HOD Verified? = Yes)',
        ])
        ->and(alApplyScores($f))->toBe([
            $f['one']->id.'|demo-001' => 3,
            $f['one']->id.'|demo-002' => 5,
            $f['two']->id.'|demo-002' => 2,
        ]);
});

test('a workbook with an unknown employee on row 7 applies nothing and exits non-zero', function (): void {
    $f = alApplyFixture();
    $path = alApplyWithCell('D7', 'EMP-99999');
    $before = alApplyCounts();
    $audits = AuditAction::query()->count();

    try {
        alApplyCommand($this, $f, $path)
            ->expectsOutputToContain('[defect] unknown_employee at 04 Assessment Log!D7 | provenance sha256='.hash_file('sha256', $path).' row=7')
            ->expectsOutputToContain('Created: 0')
            ->expectsOutputToContain('Defects: 1')
            ->assertFailed();
    } finally {
        unlink($path);
    }

    alApplyFlushAudit();
    expect(alApplyCounts())->toBe($before)
        ->and(AuditAction::query()->count())->toBe($audits);
});

test('applying the same file twice creates nothing the second time: three skipped, row counts unchanged', function (): void {
    $f = alApplyFixture();
    alApplyCommand($this, $f, alApplyFixturePath())->expectsOutputToContain('Created: 3')->assertSuccessful();
    $between = alApplyCounts();

    alApplyCommand($this, $f, alApplyFixturePath())
        ->expectsOutputToContain('Created: 0')
        ->expectsOutputToContain('Skipped: 3')
        ->expectsOutputToContain('Defects: 0')
        ->assertSuccessful();

    expect(alApplyCounts())->toBe($between);
});

test('a store refusal on row 7 rolls back row 6: zero new assessments and the refusal names the row', function (): void {
    // Only demo-001 is required, so row 6 (demo-001) writes and row 7 (demo-002) is refused by the store.
    $f = alApplyFixture(['demo-001']);
    $before = alApplyCounts();

    alApplyCommand($this, $f, alApplyFixturePath())
        ->expectsOutputToContain('[defect] store_refused at 04 Assessment Log!A7')
        ->expectsOutputToContain('Created: 0')
        ->expectsOutputToContain('Defects: 1')
        ->assertFailed();

    expect(alApplyCounts())->toBe($before);
});

test('a row naming a sibling company employee is a defect and nothing is written', function (): void {
    $f = alApplyFixture();
    Employee::factory()->create(['company_id' => $f['fixture']->betaCompanyEntityId, 'employee_number' => 'BETA-0001', 'status' => 'active']);
    $path = alApplyWithCell('D6', 'BETA-0001');
    $before = alApplyCounts();

    try {
        alApplyCommand($this, $f, $path)
            ->expectsOutputToContain('[defect] cross_company_employee at 04 Assessment Log!D6')
            ->expectsOutputToContain('Created: 0')
            ->assertFailed();
    } finally {
        unlink($path);
    }

    expect(alApplyCounts())->toBe($before);
});

test('a user attributed only to company B is refused for company A before the workbook is opened', function (): void {
    $f = alApplyFixture();
    $betaHr = alApplyUser($f['fixture']->betaCompanyEntityId, 'people_hr');
    $missing = storage_path('framework/testing/never-opened-'.uniqid().'.xlsx');

    alApplyCommand($this, $f, $missing, ['--as' => $betaHr->id])
        ->expectsOutputToContain('User '.$betaHr->id.' is not authorized for people.skill.catalog.import in company '.$f['fixture']->alphaCompanyEntityId.'; the workbook was not opened.')
        ->doesntExpectOutputToContain('readable local workbook')
        ->assertFailed();
});

test('--tenant naming another tenant with the same user is refused and writes nothing', function (): void {
    $f = alApplyFixture();
    $other = CompanyIsolationFixture::twoCompaniesInOneTenant('Gamma Ltd', 'Delta Co');
    $before = alApplyCounts();

    alApplyCommand($this, $f, alApplyFixturePath(), ['--tenant' => $other->tenantId])
        ->expectsOutputToContain('the workbook was not opened.')
        ->doesntExpectOutputToContain('Created: 3')
        ->assertFailed();

    alApplyCommand($this, $f, alApplyFixturePath(), ['--tenant' => 999_999])
        ->expectsOutputToContain('999999')
        ->assertFailed();

    expect(alApplyCounts())->toBe($before);
});

test('the import audit row names the sha256 and the created ids', function (): void {
    $f = alApplyFixture();
    $sha = hash_file('sha256', alApplyFixturePath());
    $audits = AuditAction::query()->count();

    alApplyCommand($this, $f, alApplyFixturePath())->expectsOutputToContain('Created: 3')->assertSuccessful();
    $ids = SkillAssessment::query()->forCompany($f['fixture']->tenantId, $f['fixture']->alphaCompanyEntityId)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

    alApplyFlushAudit();
    expect(AuditAction::query()->count())->toBe($audits + 1);
    $action = AuditAction::query()->latest('id')->first();
    expect($action->event)->toBe(AssessmentLogImporter::EVENT)
        ->and($action->payload['context']['sha256'] ?? null)->toBe($sha)
        ->and($action->payload['context']['assessment_ids'] ?? null)->toBe($ids)
        ->and($action->payload['context']['created'] ?? null)->toBe(3)
        ->and($action->payload['subject']['identifier'] ?? null)->toBe($sha);
});
