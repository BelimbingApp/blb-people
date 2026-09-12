<?php

use App\Base\Audit\Models\AuditAction;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Company\Models\Department;
use App\Core\Company\Models\DepartmentType;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentCycle;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\AssessmentResultBand;
use App\Domains\People\Skills\Enums\AssessmentStatus;
use App\Domains\People\Skills\Enums\HodVerification;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Livewire\BackupCoverage\Index;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Models\SkillCategory;
use App\Domains\People\Skills\Services\AssessmentWorkflowContext;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Backup coverage CSV export (0007-d, #350): HR downloads exactly the rows
 * the page renders, under people.skill.coverage.export, with one audited
 * action per export. Self-contained: every helper is prefixed bce.
 */
afterEach(function (): void {
    $this->travelBack();
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

/** @return array<string, mixed> */
function bceFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Export Tenant'], ['name' => 'Export Company', 'status' => 'active']);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $unit = PeopleReferenceEntry::query()->create([
        'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'bce-ops', 'name' => 'Operations', 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $type = DepartmentType::query()->create([
        'code' => 'bce-operations', 'name' => 'Operations', 'category' => 'operational', 'is_active' => true,
    ]);
    $department = Department::query()->create([
        'company_id' => $companyId, 'department_type_id' => $type->id, 'status' => 'active',
    ]);

    $hr = bceUser($companyId, 'people_hr');
    $hod = bceUser($companyId, 'people_hod');
    bceGrant($companyId, $hod, Index::VIEW_CAPABILITY);

    $sibling = Company::factory()->create(['tenant_id' => $tenantId, 'name' => 'Export Sibling', 'status' => 'active']);

    return compact('tenantId', 'companyId', 'hr', 'hod', 'unit', 'department', 'sibling');
}

function bceUser(int $companyId, string $role): User
{
    $user = User::factory()->create(['company_id' => $companyId]);
    PrincipalRole::query()->create([
        'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $role)->valueOrFail('id'),
    ]);

    return $user;
}

/** A direct capability grant: a viewer the role matrix would never produce. */
function bceGrant(int $companyId, User $user, string $capability): void
{
    PrincipalCapability::query()->create([
        'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id, 'capability_key' => $capability, 'is_allowed' => true,
    ]);
}

function bceEmployee(array $f, string $name, ?int $companyId = null): Employee
{
    $companyId ??= $f['companyId'];
    $employee = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $companyId === $f['companyId'] ? $f['department']->id : null,
        'full_name' => $name, 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    if ($companyId === $f['companyId']) {
        EmployeeWorkProfile::query()->create(['employee_id' => $employee->id, 'organization_unit_id' => $f['unit']->id]);
    }

    return $employee;
}

function bceSkill(array $f, int $companyId, string $code = 'backup.export'): int
{
    $existing = Skill::query()->forCompany($f['tenantId'], $companyId)->where('code', $code)->first();
    if ($existing !== null) {
        return (int) $existing->id;
    }
    $category = SkillCategory::query()->forCompany($f['tenantId'], $companyId)->where('code', 'bce-safety')->first()
        ?? app(SkillCatalogStore::class)->defineCategory($companyId, 'bce-safety', 'Safety');

    return (int) app(SkillCatalogStore::class)->defineSkill($companyId, new SkillDraft(
        code: $code, name: Str::headline(str_replace('.', ' ', $code)),
        definition: 'Export fixture skill.',
        categoryId: (int) $category->id, defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ))->id;
}

function bceScore(
    array $f,
    Employee $employee,
    int $current,
    int $required = 3,
    RequirementCriticality $criticality = RequirementCriticality::Critical,
    ?string $validUntil = null,
    ?int $companyId = null,
    string $code = 'backup.export',
): EmployeeSkillScore {
    $companyId ??= $f['companyId'];
    $skillId = bceSkill($f, $companyId, $code);
    $assessment = AssessmentWorkflowContext::runStoreMutation(static fn (): SkillAssessment => SkillAssessment::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $companyId,
        'employee_entity_id' => $employee->id, 'skill_id' => $skillId,
        'requirement_reference' => 'fixture.backup', 'requirement_version' => 2,
        'required_level' => $required, 'criticality' => $criticality, 'weight_percent' => 100,
        'mandatory_gate' => true, 'assessed_level' => $current, 'gap' => max($required - $current, 0),
        'weighted_gap' => max($required - $current, 0) * 100, 'priority_score' => max($required - $current, 0) * 300,
        'result_band' => AssessmentResultBand::fromGap(max($required - $current, 0), $current, $required),
        'method' => AssessmentMethod::DirectObservation, 'cycle' => AssessmentCycle::Annual,
        'status' => AssessmentStatus::Draft, 'evidence' => 'Observed task.', 'assessed_at' => now()->subDays(3),
        'assessor_user_id' => 9, 'hod_verification' => HodVerification::Pending,
    ]));

    return EmployeeSkillScore::query()->forCompany($f['tenantId'], $companyId)->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $companyId,
        'employee_entity_id' => $employee->id, 'skill_id' => $skillId,
        'source_assessment_id' => $assessment->id,
        'requirement_reference' => 'fixture.backup', 'requirement_version' => 2,
        'required_level' => $required, 'current_level' => $current,
        'gap' => max($required - $current, 0), 'mandatory_gate' => true,
        'criticality' => $criticality, 'assessed_at' => now()->subDays(3),
        'valid_until' => $validUntil,
    ]);
}

/** @return list<array<string, mixed>> the page's rendered rows for the HR user */
function bcePageRows(array $f): array
{
    return Livewire::actingAs($f['hr'])->test(Index::class)->viewData('rows');
}

/** @return list<list<string>> the CSV rows (header + data) of one export by $user */
function bceDownload(User $user): array
{
    $page = Livewire::actingAs($user)->test(Index::class);
    $page->call('export')->assertFileDownloaded();
    bceFlushAudit();
    $lines = array_values(array_filter(explode("\n", trim(base64_decode($page->effects['download']['content'])))));

    return array_map(str_getcsv(...), $lines);
}

function bceFlushAudit(): void
{
    $buffer = app(AuditBuffer::class);
    (new ReflectionClass($buffer))->getMethod('flush')->invoke($buffer);
}

test('the CSV carries exactly the rendered rows in the same order', function (): void {
    $f = bceFixture();
    bceScore($f, bceEmployee($f, 'Only Holder'), current: 4);
    bceScore($f, bceEmployee($f, 'First Pair'), current: 4, code: 'backup.pair');
    bceScore($f, bceEmployee($f, 'Second Pair'), current: 3, code: 'backup.pair');
    bceScore($f, bceEmployee($f, 'Below Level'), current: 2, code: 'backup.low');
    bceScore($f, bceEmployee($f, 'Qualified Low'), current: 3, code: 'backup.low');
    bceScore($f, bceEmployee($f, 'Lapsed'), current: 4, validUntil: now()->subDay()->toDateString(), code: 'backup.old');

    $pageRows = collect(bcePageRows($f))->keyBy('skill');
    $csv = bceDownload($f['hr']);

    expect($csv[0])->toBe(['skill', 'covered', 'single_point_of_failure', 'holders', 'as_of'])
        ->and(count($csv) - 1)->toBe($pageRows->count());

    foreach (array_slice($csv, 1) as $index => $line) {
        $rendered = $pageRows->values()->get($index);
        expect($line[0])->toBe($rendered['skill'])
            ->and((int) $line[1])->toBe($rendered['covered'])
            ->and($line[2])->toBe($rendered['single_point_of_failure'] ? 'yes' : 'no')
            ->and($line[3])->toBe(implode('; ', $rendered['holders']))
            ->and($line[4])->toBe(now()->toDateString());
    }

    expect($pageRows->get('Backup Export')['covered'])->toBe(1)
        ->and($pageRows->get('Backup Pair')['covered'])->toBe(2)
        ->and($pageRows->get('Backup Low')['covered'])->toBe(1)
        ->and($pageRows->get('Backup Old')['covered'])->toBe(0);
});

test('each export writes exactly one audit row with the row count and skill ids, and nothing else', function (): void {
    $f = bceFixture();
    bceScore($f, bceEmployee($f, 'Holder'), current: 4);
    bceFlushAudit();
    $counts = fn (): array => [
        'audit' => AuditAction::query()->count(),
        'scores' => EmployeeSkillScore::query()->forCompany($f['tenantId'], $f['companyId'])->count(),
        'skills' => Skill::query()->forCompany($f['tenantId'], $f['companyId'])->count(),
    ];
    $before = $counts();

    bceDownload($f['hr']);

    $after = $counts();
    expect($after['audit'])->toBe($before['audit'] + 1)
        ->and($after['scores'])->toBe($before['scores'])
        ->and($after['skills'])->toBe($before['skills']);

    $action = AuditAction::query()->latest('id')->first();
    $skillId = bceSkill($f, $f['companyId']);
    expect($action->event)->toBe(Index::EXPORT_EVENT)
        ->and($action->event)->toBe('people.skill.coverage.exported')
        ->and((int) $action->actor_id)->toBe((int) $f['hr']->id)
        ->and($action->payload['context']['rows'])->toBe(1)
        ->and($action->payload['context']['skill_ids'])->toBe([$skillId])
        ->and($action->payload['context']['company_entity_id'])->toBe($f['companyId']);

    bceDownload($f['hr']);

    expect(AuditAction::query()->count())->toBe($before['audit'] + 2);
});

test('an expiry timestamp inside today still covers today and lapses tomorrow, on the page and in the file', function (): void {
    $f = bceFixture();
    bceScore($f, bceEmployee($f, 'Timed Holder'), current: 4, validUntil: now()->toDateTimeString());

    $this->travelTo(now()->startOfDay()->addHours(8));
    expect(collect(bcePageRows($f))->firstWhere('skill', 'Backup Export')['covered'])->toBe(1);
    $csv = bceDownload($f['hr']);
    expect($csv[1][1])->toBe('1');

    $this->travelTo(now()->addDay()->startOfDay()->addHours(8));
    expect(collect(bcePageRows($f))->firstWhere('skill', 'Backup Export')['covered'])->toBe(0);
    $csv = bceDownload($f['hr']);
    expect($csv[1][1])->toBe('0')->and($csv[1][2])->toBe('yes');
});

test('a viewer with only the page capability and a HOD with only view are refused the export', function (): void {
    $f = bceFixture();
    bceScore($f, bceEmployee($f, 'Holder'), current: 4);
    bceFlushAudit();
    $auditBefore = AuditAction::query()->count();

    $viewer = User::factory()->create(['company_id' => $f['companyId']]);
    bceGrant($f['companyId'], $viewer, Index::VIEW_CAPABILITY);
    bceGrant($f['companyId'], $viewer, 'people.skill.hr.view');

    Livewire::actingAs($viewer)->test(Index::class)->assertOk()
        ->call('export')->assertForbidden();
    Livewire::actingAs($f['hod'])->test(Index::class)->assertOk()
        ->call('export')->assertForbidden();

    expect(AuditAction::query()->count())->toBe($auditBefore);
});

test('the sibling company never appears in this company file and a foreign tenant is refused', function (): void {
    $f = bceFixture();
    bceScore($f, bceEmployee($f, 'Ours'), current: 4);
    bceScore($f, bceEmployee($f, 'Theirs', companyId: (int) $f['sibling']->id), current: 4,
        companyId: (int) $f['sibling']->id, code: 'backup.theirs');

    $csv = bceDownload($f['hr']);
    $skills = implode("\n", array_column(array_slice($csv, 1), 0));
    $holders = implode("\n", array_column(array_slice($csv, 1), 3));
    expect($holders)->toContain('Ours')
        ->and($holders)->not->toContain('Theirs')
        ->and($skills)->not->toContain('Backup Theirs');

    // A foreign tenant never passes mount, so the export action is unreachable.
    [$farTenant, $farCompany] = createTenantWithCompany(['name' => 'Far Tenant'], ['name' => 'Far Co']);
    app(TenantContext::class)->set((int) $farTenant->id);
    $farUser = User::factory()->create(['company_id' => $farCompany->id]);
    app(TenantContext::class)->set($f['tenantId']);

    Livewire::actingAs($farUser)->test(Index::class)->assertForbidden();
});

test('the export button shows only for a holder of the export capability', function (): void {
    $f = bceFixture();

    Livewire::actingAs($f['hr'])->test(Index::class)->assertOk()->assertSee('Export CSV');

    $viewer = User::factory()->create(['company_id' => $f['companyId']]);
    bceGrant($f['companyId'], $viewer, Index::VIEW_CAPABILITY);
    bceGrant($f['companyId'], $viewer, 'people.skill.hr.view');
    Livewire::actingAs($viewer)->test(Index::class)->assertOk()->assertDontSee('Export CSV');
});
