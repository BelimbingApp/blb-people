<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Data\ProficiencyLevelDraft;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\AssessmentWorkflowContext;
use App\Domains\People\Skills\Services\ProficiencyScaleStore;
use App\Domains\People\Skills\Services\SkillCatalogDefaults;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Tests\Support\CompanyIsolationFixture;
use App\Domains\People\Skills\Tests\Support\TwoCompanyTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * people:skills-assessment-log-dry-run (#373). Self-contained: helpers are
 * prefixed alDryRun; only the tests/Pest.php bootstrap helpers are shared.
 * The fixture rows are dated January/February 2026, so "today" is pinned.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-03-01 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

function alDryRunFixturePath(): string
{
    return __DIR__.'/../Fixtures/skill-assessment-log.xlsx';
}

/** A copy of the fixture with the assessment-log sheet XML altered; caller unlinks it. */
function alDryRunAlteredWorkbook(callable $alter): string
{
    $path = tempnam(storage_path('framework/testing'), 'assessment-log-dry-run-');
    copy(alDryRunFixturePath(), $path);
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = $zip->getFromName('xl/worksheets/sheet3.xml');
    $next = $alter($xml);
    expect($next)->not->toBe($xml);
    $zip->addFromString('xl/worksheets/sheet3.xml', $next);
    $zip->close();

    return $path;
}

/** Replace the text of one cell on the assessment-log sheet. */
function alDryRunWithCell(string $cell, string $value): string
{
    return alDryRunAlteredWorkbook(fn (string $xml): string => preg_replace(
        '/(<x:c\b[^>]*\br="'.$cell.'"[^>]*>)<x:v>[^<]*<\/x:v>/',
        '$1<x:v>'.$value.'</x:v>',
        $xml,
    ));
}

function alDryRunUser(int $companyId, string $roleCode): User
{
    $user = User::factory()->create(['company_id' => $companyId]);
    PrincipalRole::query()->create([
        'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value, 'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->sole()->id,
    ]);

    return $user;
}

function alDryRunEmployee(int $companyId, string $staffId, string $status = 'active'): Employee
{
    return Employee::factory()->create(['company_id' => $companyId, 'employee_number' => $staffId, 'status' => $status]);
}

/** @return array{fixture: TwoCompanyTenant, hr: User, hod: User, one: Employee, two: Employee} */
function alDryRunFixture(): array
{
    $fixture = CompanyIsolationFixture::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);
    setupAuthzRoles();
    $alpha = $fixture->alphaCompanyEntityId;

    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory($alpha, 'safety', 'Safety');
    $catalog->defineSkill($alpha, new SkillDraft('demo-001', 'Demo one', 'First demo skill.', (int) $category->id));
    $catalog->defineSkill($alpha, new SkillDraft('demo-002', 'Demo two', 'Second demo skill.', (int) $category->id));

    $scales = app(ProficiencyScaleStore::class);
    $draft = $scales->draft($alpha, SkillCatalogDefaults::SCALE_CODE, 'Standard scale', array_map(
        fn (int $level): ProficiencyLevelDraft => new ProficiencyLevelDraft($level, "Level {$level}", "Stage {$level}.", "Authority {$level}."),
        range(0, 5),
    ));
    $scales->publish($alpha, (int) $draft->id);

    return [
        'fixture' => $fixture,
        'hr' => alDryRunUser($alpha, 'people_hr'),
        'hod' => alDryRunUser($alpha, 'people_hod'),
        'one' => alDryRunEmployee($alpha, 'EMP-00001'),
        'two' => alDryRunEmployee($alpha, 'EMP-00002'),
    ];
}

function alDryRunCommand(object $test, array $f, string $path, array $overrides = []): object
{
    return $test->artisan('people:skills-assessment-log-dry-run', $overrides + [
        'workbook' => $path,
        '--tenant' => $f['fixture']->tenantId,
        '--company' => $f['fixture']->alphaCompanyEntityId,
        '--as' => $f['hr']->id,
    ]);
}

function alDryRunAssessmentCount(): int
{
    return DB::table('people_connector_skill_assessments')->count();
}

function alDryRunFinalize(array $f, Employee $employee, string $skillCode, string $assessedAt): SkillAssessment
{
    $fixture = $f['fixture'];
    $skill = Skill::query()->forCompany($fixture->tenantId, $fixture->alphaCompanyEntityId)->where('code', $skillCode)->sole();

    return AssessmentWorkflowContext::runStoreMutation(function () use ($fixture, $f, $employee, $skill, $assessedAt): SkillAssessment {
        $assessment = SkillAssessment::query()->create([
            'tenant_id' => $fixture->tenantId, 'company_entity_id' => $fixture->alphaCompanyEntityId,
            'employee_entity_id' => $employee->id, 'skill_id' => $skill->id,
            'requirement_reference' => 'log.requirement', 'requirement_version' => 1,
            'required_level' => 3, 'assessed_level' => 3, 'gap' => 0, 'criticality' => 'critical', 'mandatory_gate' => false,
            'method' => 'direct_observation', 'cycle' => 'annual', 'status' => 'submitted',
            'assessed_at' => $assessedAt, 'assessor_user_id' => $f['hr']->id, 'hod_verification' => 'pending',
            'evidence' => 'Observed on shift',
        ]);
        $assessment->update(['status' => 'pending_hod_verification']);
        $assessment->update(['hod_verification' => 'verified', 'hod_verifier_user_id' => $f['hod']->id, 'hod_verified_at' => now()]);
        $assessment->update(['status' => 'finalized', 'finalized_at' => now(), 'finalized_by_user_id' => $f['hod']->id]);

        return $assessment;
    });
}

test('a valid three-row sheet would create three assessments with no defects and writes nothing', function (): void {
    $f = alDryRunFixture();
    $before = alDryRunAssessmentCount();

    alDryRunCommand($this, $f, alDryRunFixturePath())
        ->expectsOutputToContain('Workbook SHA-256: '.hash_file('sha256', alDryRunFixturePath()))
        ->expectsOutputToContain('Would create: 3')
        ->expectsOutputToContain('Would skip: 0')
        ->expectsOutputToContain('Defects: 0')
        ->expectsOutputToContain('Database writes: 0')
        ->assertSuccessful();

    expect(alDryRunAssessmentCount())->toBe($before);
});

test('each impossible reference or date is one defect naming the sheet and row', function (string $kind, string $cell, ?string $value, ?string $today): void {
    $f = alDryRunFixture();
    if ($today !== null) {
        Carbon::setTestNow($today);
    }
    $path = $value === null ? alDryRunFixturePath() : alDryRunWithCell($cell, $value);
    $before = alDryRunAssessmentCount();

    try {
        alDryRunCommand($this, $f, $path)
            ->expectsOutputToContain('[defect] '.$kind.' at 04 Assessment Log!'.$cell.' | provenance sha256='.hash_file('sha256', $path).' row='.substr($cell, 1))
            ->expectsOutputToContain('Would create: 2')
            ->expectsOutputToContain('Defects: 1')
            ->expectsOutputToContain('Database writes: 0')
            ->assertFailed();
    } finally {
        $value === null || unlink($path);
    }

    expect(alDryRunAssessmentCount())->toBe($before);
})->with([
    'unknown staff id' => ['unknown_employee', 'D6', 'EMP-99999', null],
    'unknown skill id' => ['unknown_skill', 'E6', 'DEMO-999', null],
    'level 6 on a 0-5 scale' => ['level_out_of_range', 'F6', '6', null],
    'assessment date tomorrow' => ['future_assessment_date', 'C8', null, '2026-01-31 09:00:00'],
    'valid until before the assessment' => ['valid_until_before_assessment', 'L6', '2025-12-31', null],
]);

test('a sibling company staff id is a cross-company defect and another tenant is never resolved', function (): void {
    $f = alDryRunFixture();
    alDryRunEmployee($f['fixture']->betaCompanyEntityId, 'BETA-0001');
    $other = CompanyIsolationFixture::twoCompaniesInOneTenant('Gamma Ltd', 'Delta Co');
    alDryRunEmployee($other->alphaCompanyEntityId, 'GAMMA-0001');
    app(TenantContext::class)->set($f['fixture']->tenantId);

    $sibling = alDryRunWithCell('D6', 'BETA-0001');
    $foreign = alDryRunWithCell('D6', 'GAMMA-0001');

    try {
        alDryRunCommand($this, $f, $sibling)
            ->expectsOutputToContain('[defect] cross_company_employee at 04 Assessment Log!D6')
            ->expectsOutputToContain('Would create: 2')
            ->assertFailed();

        alDryRunCommand($this, $f, $foreign)
            ->expectsOutputToContain('[defect] unknown_employee at 04 Assessment Log!D6')
            ->expectsOutputToContain('Would create: 2')
            ->assertFailed();
    } finally {
        unlink($sibling);
        unlink($foreign);
    }
});

test('an existing finalized assessment for the same employee, skill and date is a would-skip', function (): void {
    $f = alDryRunFixture();
    alDryRunFinalize($f, $f['one'], 'demo-001', '2026-01-15 14:30:00');
    $before = alDryRunAssessmentCount();

    alDryRunCommand($this, $f, alDryRunFixturePath())
        ->expectsOutputToContain('Would create: 2')
        ->expectsOutputToContain('Would skip: 1')
        ->expectsOutputToContain('Defects: 0')
        ->assertSuccessful();

    expect(alDryRunAssessmentCount())->toBe($before);
});

test('the command refuses without a tenant scope', function (): void {
    $f = alDryRunFixture();
    app(TenantContext::class)->clear();

    $exit = Artisan::call('people:skills-assessment-log-dry-run', [
        'workbook' => alDryRunFixturePath(),
        '--company' => $f['fixture']->alphaCompanyEntityId,
        '--as' => $f['hr']->id,
    ]);

    expect($exit)->not->toBe(0)
        ->and(Artisan::output())->toContain('--tenant')
        ->and(Artisan::output())->not->toContain('Would create');
});

test('a user without the import capability is refused before the file is read', function (): void {
    $f = alDryRunFixture();
    $missing = storage_path('framework/testing/never-opened-'.uniqid().'.xlsx');

    alDryRunCommand($this, $f, $missing, ['--as' => $f['hod']->id])
        ->expectsOutputToContain('is not authorized for people.skill.catalog.import in company '.$f['fixture']->alphaCompanyEntityId.'; the workbook was not opened.')
        ->doesntExpectOutputToContain('readable local workbook')
        ->assertFailed();

    alDryRunCommand($this, $f, $missing)
        ->expectsOutputToContain('readable local workbook')
        ->assertFailed();
});
