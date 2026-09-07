<?php

use App\Base\Audit\Models\AuditAction;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Livewire\Catalog\Import;
use App\Domains\People\Skills\Models\RequirementItem;
use App\Domains\People\Skills\Models\RequirementProfile;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Services\StarterProfileImporter;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Starter-profile workbook import page (#299)
|--------------------------------------------------------------------------
|
| Native workforce fixture, every helper local to this file so it runs
| alone. Only the tests/Pest.php bootstrap helpers (createTenant,
| setupAuthzRoles) are shared.
*/

beforeEach(function (): void {
    $this->withoutVite();
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function catImportUser(Company $company, string $roleCode): User
{
    $user = User::factory()->create(['company_id' => $company->id]);
    PrincipalRole::query()->create([
        'company_id' => $company->id, 'principal_type' => PrincipalType::USER->value, 'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->sole()->id,
    ]);

    return $user;
}

function catImportUnit(Company $company, string $code, string $name): PeopleReferenceEntry
{
    $unit = PeopleReferenceEntry::query()->create([
        'company_id' => $company->id, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => $code, 'name' => $name, 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    // The profile store targets a department through its employees' projection, so give it one.
    $employee = Employee::factory()->create(['company_id' => $company->id, 'full_name' => $name.' One', 'short_name' => null, 'supervisor_id' => null, 'status' => 'active', 'employee_type' => 'full_time']);
    EmployeeWorkProfile::query()->create(['employee_id' => $employee->id, 'organization_unit_id' => $unit->id]);

    return $unit;
}

/** @return array{tenantId: int, alpha: Company, beta: Company, hr: User, hod: User, betaHr: User} */
function catImportFixture(): array
{
    $tenant = createTenant(['name' => 'Catalog Import Tenant']);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();
    $alpha = Company::factory()->create(['tenant_id' => $tenantId, 'name' => 'Alpha Import', 'status' => 'active']);
    $beta = Company::factory()->create(['tenant_id' => $tenantId, 'name' => 'Beta Import', 'status' => 'active']);
    catImportUnit($alpha, 'OPS', 'Operations');
    catImportUnit($alpha, 'QA', 'Quality');
    catImportUnit($beta, 'BOPS', 'Operations');

    return [
        'tenantId' => $tenantId, 'alpha' => $alpha, 'beta' => $beta,
        'hr' => catImportUser($alpha, 'people_hr'),
        'hod' => catImportUser($alpha, 'people_hod'),
        'betaHr' => catImportUser($beta, 'people_hr'),
    ];
}

function catImportCsv(array $rows): UploadedFile
{
    $lines = [implode(',', StarterProfileImporter::HEADER)];
    foreach ($rows as $row) {
        $lines[] = implode(',', $row);
    }

    return UploadedFile::fake()->createWithContent('starter.csv', implode("\n", $lines)."\n");
}

function catImportFlushAudit(): void
{
    $buffer = app(AuditBuffer::class);
    (new ReflectionClass($buffer))->getMethod('flush')->invoke($buffer);
}

/** @return array{skills: int, items: int, profiles: int} rows of the company, and never a sibling's */
function catImportCounts(int $tenantId, Company $company): array
{
    return [
        'skills' => Skill::query()->forCompany($tenantId, (int) $company->id)->count(),
        'items' => RequirementItem::query()->forCompany($tenantId, (int) $company->id)->count(),
        'profiles' => RequirementProfile::query()->forCompany($tenantId, (int) $company->id)->count(),
    ];
}

test('a two-row valid workbook creates two requirements in one draft profile, two skills, and one audit row naming the file and row count', function (): void {
    $f = catImportFixture();
    $before = AuditAction::query()->count();

    $page = Livewire::actingAs($f['hr'])->test(Import::class)->assertOk()
        ->set('workbook', catImportCsv([
            ['Operations', 'Line Operator', 'Forklift Operation', '3', 'critical'],
            ['Operations', 'Line Operator', '5S Housekeeping', '2', 'Essential'],
        ]))
        ->call('import')
        ->assertHasNoErrors()
        ->assertSee('starter.csv imported: 2 rows, 2 new skills, 1 new role profiles, 2 new requirements');

    expect($page->get('result')['errors'])->toBe([])
        ->and(catImportCounts($f['tenantId'], $f['alpha']))->toBe(['skills' => 2, 'items' => 2, 'profiles' => 1])
        ->and(catImportCounts($f['tenantId'], $f['beta']))->toBe(['skills' => 0, 'items' => 0, 'profiles' => 0]);

    $profile = RequirementProfile::query()->forCompany($f['tenantId'], (int) $f['alpha']->id)->sole();
    $items = RequirementItem::query()->forCompany($f['tenantId'], (int) $f['alpha']->id)->orderBy('sequence')->get();
    $skills = Skill::query()->forCompany($f['tenantId'], (int) $f['alpha']->id)->orderBy('code')->pluck('code')->all();
    expect($profile->code)->toBe('starter.operations.line_operator')
        ->and($profile->name)->toBe('Line Operator (Operations)')
        ->and($skills)->toBe(['5s_housekeeping', 'forklift_operation'])
        ->and($items->map(fn (RequirementItem $item): array => [(int) $item->required_level, $item->criticality->value])->all())
        ->toBe([[3, 'critical'], [2, 'essential']]);

    catImportFlushAudit();
    expect(AuditAction::query()->count())->toBe($before + 1);
    $action = AuditAction::query()->latest('id')->first();
    expect($action->event)->toBe(StarterProfileImporter::EVENT)
        ->and((int) $action->actor_id)->toBe((int) $f['hr']->id)
        ->and($action->payload['context']['file'] ?? null)->toBe('starter.csv')
        ->and($action->payload['context']['rows'] ?? null)->toBe(2)
        ->and($action->payload['subject']['identifier'] ?? null)->toBe('starter.csv');
});

test('one bad level refuses the whole workbook: nothing is written, the bad row is listed, and no audit row is left', function (): void {
    $f = catImportFixture();
    $before = AuditAction::query()->count();

    // Level 0 is inside the store's own 0-5 range, so only the import's
    // validate-before-write pass stands between this row and the database:
    // delete that pass and BOTH rows land (#299's named control).
    $page = Livewire::actingAs($f['hr'])->test(Import::class)
        ->set('workbook', catImportCsv([
            ['Operations', 'Line Operator', 'Forklift Operation', '3', 'critical'],
            ['Operations', 'Line Operator', '5S Housekeeping', '0', 'essential'],
        ]))
        ->call('import')
        ->assertHasNoErrors();

    // The database first: this is the assertion the named control turns red.
    expect(catImportCounts($f['tenantId'], $f['alpha']))->toBe(['skills' => 0, 'items' => 0, 'profiles' => 0])
        ->and($page->get('result')['errors'])->toBe([['row' => 3, 'message' => 'required level [0] must be a whole number from 1 to 5']]);
    $page->assertSee('Rows refused in starter.csv')
        ->assertSee('required level [0] must be a whole number from 1 to 5');

    catImportFlushAudit();
    expect(AuditAction::query()->count())->toBe($before);
});

test('every row is checked and every problem is listed with its row number: unknown department, duplicate skill per role, level outside 1-5, unknown criticality', function (): void {
    $f = catImportFixture();

    $page = Livewire::actingAs($f['hr'])->test(Import::class)
        ->set('workbook', catImportCsv([
            ['Operations', 'Line Operator', 'Forklift Operation', '3', 'critical'],
            ['Logistics', 'Driver', 'Defensive Driving', '3', 'critical'],
            ['Operations', 'Line Operator', 'forklift operation', '6', 'urgent'],
            ['Quality', 'Inspector', 'Gauge Reading', '4', 'development'],
        ]))
        ->call('import');

    expect($page->get('result')['errors'])->toBe([
        ['row' => 3, 'message' => 'unknown department [Logistics]'],
        ['row' => 4, 'message' => 'required level [6] must be a whole number from 1 to 5'],
        ['row' => 4, 'message' => 'unknown criticality [urgent]; use critical, essential or development'],
        ['row' => 4, 'message' => 'duplicate skill [forklift operation] for this role (first at row 2)'],
    ])->and(catImportCounts($f['tenantId'], $f['alpha']))->toBe(['skills' => 0, 'items' => 0, 'profiles' => 0]);

    // A wrong header is one row-1 problem, not a crash.
    $page->set('workbook', UploadedFile::fake()->createWithContent('starter.csv', "dept,role,skill\nOperations,Line Operator,Forklift Operation\n"))->call('import');
    expect($page->get('result')['errors'])->toBe([['row' => 1, 'message' => 'Expected the columns department, role, skill, required_level, criticality.']]);
});

test('a second import of the same file is idempotent: no duplicate skills, profiles or requirements, and it still audits', function (): void {
    $f = catImportFixture();
    $rows = [
        ['Operations', 'Line Operator', 'Forklift Operation', '3', 'critical'],
        ['Quality', 'Inspector', 'Gauge Reading', '4', 'development'],
    ];

    $page = Livewire::actingAs($f['hr'])->test(Import::class)
        ->set('workbook', catImportCsv($rows))->call('import')->assertHasNoErrors();
    expect(catImportCounts($f['tenantId'], $f['alpha']))->toBe(['skills' => 2, 'items' => 2, 'profiles' => 2]);

    $page->set('workbook', catImportCsv($rows))->call('import')->assertHasNoErrors()
        ->assertSee('starter.csv imported: 2 rows, 0 new skills, 0 new role profiles, 0 new requirements');
    expect(catImportCounts($f['tenantId'], $f['alpha']))->toBe(['skills' => 2, 'items' => 2, 'profiles' => 2]);

    catImportFlushAudit();
    expect(AuditAction::query()->where('event', StarterProfileImporter::EVENT)->count())->toBe(2);
});

test('a user without the import capability is refused by the component and the route, and HR of another company cannot select this one', function (): void {
    $f = catImportFixture();

    Livewire::actingAs($f['hod'])->test(Import::class)->assertForbidden();
    $this->actingAs($f['hod'])->get(route('people.skill.catalog.import'))->assertForbidden();
    $this->actingAs($f['hr'])->get(route('people.skill.catalog.import'))->assertOk();

    Livewire::actingAs($f['betaHr'])->test(Import::class)
        ->call('selectCompany', $f['alpha']->id)->assertStatus(404);

    // The company id is client-writable: a forged value never reaches the importer.
    Livewire::actingAs($f['betaHr'])->test(Import::class)
        ->set('companyEntityId', $f['alpha']->id)
        ->set('workbook', catImportCsv([['Operations', 'Line Operator', 'Forklift Operation', '3', 'critical']]))
        ->call('import')->assertStatus(404);
    expect(catImportCounts($f['tenantId'], $f['alpha']))->toBe(['skills' => 0, 'items' => 0, 'profiles' => 0]);
});
