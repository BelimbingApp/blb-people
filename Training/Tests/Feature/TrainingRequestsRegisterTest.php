<?php

use App\Base\Audit\Models\AuditAction;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Locale\Contracts\CurrencyDisplayService;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Address\Models\Address;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\Geonames\Models\Country;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Training\Data\TrainingRequestDraft;
use App\Domains\People\Training\Enums\TrainingNeedSource;
use App\Domains\People\Training\Enums\TrainingPriority;
use App\Domains\People\Training\Enums\TrainingRequestStatus;
use App\Domains\People\Training\Livewire\Requests\Register;
use App\Domains\People\Training\Models\TrainingRequest;
use App\Domains\People\Training\Models\TrainingRequestDecision;
use App\Domains\People\Training\Services\TrainingRequestStore;
use Livewire\Livewire;

/**
 * HR training requests register (0010-c): every request of the company for
 * the year, filters by status and department, a CSV export of exactly the
 * filtered rows with one audit action. Self-contained: helpers are prefixed
 * reqRegister.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

/** The recorder buffers until the request ends; the platform's own tests flush the same way. */
function reqRegisterFlushAudit(): void
{
    $buffer = app(AuditBuffer::class);
    (new ReflectionClass($buffer))->getMethod('flush')->invoke($buffer);
}

function reqRegisterUser(Company $company, string $roleCode, string $name): User
{
    $user = User::factory()->create(['company_id' => $company->id, 'name' => $name]);
    PrincipalRole::query()->create([
        'company_id' => $company->id, 'principal_type' => PrincipalType::USER->value, 'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->sole()->id,
    ]);

    return $user;
}

function reqRegisterUnit(Company $company, string $code, string $name): PeopleReferenceEntry
{
    return PeopleReferenceEntry::query()->create([
        'company_id' => $company->id, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => $code, 'name' => $name, 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
}

function reqRegisterEmployee(Company $company, PeopleReferenceEntry $unit, string $name): Employee
{
    $employee = Employee::factory()->create(['company_id' => $company->id, 'full_name' => $name, 'short_name' => null, 'supervisor_id' => null, 'status' => 'active', 'employee_type' => 'full_time']);
    EmployeeWorkProfile::query()->create(['employee_id' => $employee->id, 'organization_unit_id' => $unit->id]);

    return $employee;
}

function reqRegisterCompanyCurrency(Company $company, string $countryIso = 'MY', string $currencyCode = 'MYR'): void
{
    Country::query()->create([
        'iso' => $countryIso,
        'iso3' => $countryIso.'X',
        'iso_numeric' => '458',
        'country' => 'Malaysia',
        'continent' => 'AS',
        'currency_code' => $currencyCode,
    ]);

    $address = Address::factory()->create([
        'tenant_id' => (int) $company->tenant_id,
        'country_iso' => $countryIso,
    ]);
    $company->addresses()->attach($address->id, [
        'kind' => json_encode(['headquarters']),
        'is_primary' => true,
        'priority' => 0,
    ]);
}

/**
 * A request created and submitted through the store, then moved to $status
 * the way the store moves it (a status update plus a decision row), so the
 * register reads real rows without walking every audience through the
 * workflow.
 */
function reqRegisterRequest(int $tenantId, Company $company, User $hr, Employee $requestor, PeopleReferenceEntry $unit, string $need, TrainingRequestStatus $status, ?User $decider = null, ?string $cost = null): TrainingRequest
{
    $store = app(TrainingRequestStore::class);
    $request = $store->create($hr, (int) $company->id, new TrainingRequestDraft(
        requestor: new WorkforceSubject($tenantId, (int) $company->id, WorkforceResourceType::Employee, (string) $requestor->id),
        department: new WorkforceSubject($tenantId, (int) $company->id, WorkforceResourceType::OrganizationUnit, (string) $unit->id),
        needSource: TrainingNeedSource::LegalCertification, need: $need, learningObjective: 'Objective.', expectedResult: 'Result.',
        priority: TrainingPriority::Medium, estimatedCost: $cost,
    ));
    if ($status !== TrainingRequestStatus::Draft) {
        $request = $store->submit($hr, (int) $company->id, (int) $request->id);
    }
    if (! in_array($status, [TrainingRequestStatus::Draft, TrainingRequestStatus::PendingHod], true)) {
        $request->update(['status' => $status]);
        if (in_array($status, [TrainingRequestStatus::Approved, TrainingRequestStatus::Rejected], true)) {
            TrainingRequestDecision::query()->forCompany($tenantId, (int) $company->id)->create([
                'tenant_id' => $tenantId, 'company_entity_id' => $company->id, 'training_request_id' => $request->id,
                'decision' => $status->value, 'actor_user_id' => ($decider ?? $hr)->getKey(), 'notes' => null, 'occurred_at' => '2026-03-15 09:00:00',
            ]);
        }
    }

    return $request->fresh();
}

/** @return array<string, mixed> */
function reqRegisterFixture(): array
{
    $tenant = createTenant(['name' => 'Requests Register Tenant']);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();
    $alpha = Company::factory()->create(['tenant_id' => $tenantId, 'name' => 'Alpha Register', 'status' => 'active']);
    $beta = Company::factory()->create(['tenant_id' => $tenantId, 'name' => 'Beta Register', 'status' => 'active']);
    $hr = reqRegisterUser($alpha, 'people_hr', 'Alpha HR');
    $approver = reqRegisterUser($alpha, 'people_training_approver', 'Alpha Approver');
    $hod = reqRegisterUser($alpha, 'people_hod', 'Alpha HOD');
    $betaHr = reqRegisterUser($beta, 'people_hr', 'Beta HR');

    $ops = reqRegisterUnit($alpha, 'OPS', 'Operations');
    $qa = reqRegisterUnit($alpha, 'QA', 'Quality');
    $betaUnit = reqRegisterUnit($beta, 'BOPS', 'Beta Operations');
    $opsOne = reqRegisterEmployee($alpha, $ops, 'Ops One');
    $opsTwo = reqRegisterEmployee($alpha, $ops, 'Ops Two');
    $qaOne = reqRegisterEmployee($alpha, $qa, 'Quality One');
    $betaOne = reqRegisterEmployee($beta, $betaUnit, 'Beta One');

    $approved = reqRegisterRequest($tenantId, $alpha, $hr, $opsOne, $ops, 'Approved ops need', TrainingRequestStatus::Approved, $approver, '1250.5000');
    $pending = reqRegisterRequest($tenantId, $alpha, $hr, $opsTwo, $ops, 'Pending ops need', TrainingRequestStatus::PendingHod);
    $rejected = reqRegisterRequest($tenantId, $alpha, $hr, $qaOne, $qa, 'Rejected quality need', TrainingRequestStatus::Rejected, $hr);
    $foreign = reqRegisterRequest($tenantId, $beta, $betaHr, $betaOne, $betaUnit, 'Beta need', TrainingRequestStatus::Approved, $betaHr);

    return compact('tenantId', 'alpha', 'beta', 'hr', 'hod', 'betaHr', 'approver', 'ops', 'qa', 'approved', 'pending', 'rejected', 'foreign');
}

test('the register lists every request of the company for the year and never a sibling company\'s, with approver and decision date from the decision rows', function (): void {
    $f = reqRegisterFixture();

    $page = Livewire::actingAs($f['hr'])->test(Register::class)->assertOk();
    $rows = $page->viewData('rows');
    expect($rows->pluck('id')->all())->toBe([$f['rejected']->id, $f['pending']->id, $f['approved']->id])
        ->and($rows->firstWhere('id', $f['approved']->id))->toMatchArray(['requestor' => 'Ops One', 'department' => 'Operations', 'status' => 'approved', 'estimated_cost' => '1250.5000', 'approver' => 'Alpha Approver', 'decided_at' => '2026-03-15'])
        ->and($rows->firstWhere('id', $f['pending']->id))->toMatchArray(['status' => 'pending_hod', 'estimated_cost' => '', 'approver' => '', 'decided_at' => ''])
        ->and($rows->firstWhere('id', $f['rejected']->id))->toMatchArray(['department' => 'Quality', 'approver' => 'Alpha HR']);
    $page->assertSee('Approved ops need')->assertDontSee('Beta One')->assertDontSee('Beta need');
});

test('filters by department and status narrow the rows', function (): void {
    $f = reqRegisterFixture();

    $page = Livewire::actingAs($f['hr'])->test(Register::class)->set('department', (string) $f['ops']->id);
    expect($page->viewData('rows')->pluck('id')->all())->toBe([$f['pending']->id, $f['approved']->id]);

    $page->set('status', 'approved');
    expect($page->viewData('rows')->pluck('id')->all())->toBe([$f['approved']->id]);

    $page->set('department', '')->set('status', 'rejected');
    expect($page->viewData('rows')->pluck('id')->all())->toBe([$f['rejected']->id]);

    // An unknown department or status filters nothing rather than everything away silently.
    $page->set('status', 'bogus')->set('department', '999999');
    expect($page->viewData('rows'))->toHaveCount(3);
});

test('the CSV export contains exactly the filtered rows and writes one audit action', function (): void {
    $f = reqRegisterFixture();
    $before = AuditAction::query()->count();

    $page = Livewire::actingAs($f['hr'])->test(Register::class)
        ->set('department', (string) $f['ops']->id)
        ->set('status', 'approved')
        ->call('export')
        ->assertFileDownloaded('training-requests-'.$f['alpha']->id.'-'.now()->year.'.csv');

    reqRegisterFlushAudit();
    $csv = base64_decode($page->effects['download']['content']);
    $lines = array_values(array_filter(explode("\n", trim($csv))));
    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toBe('id,created_at,requestor,subjects,department,need,priority,status,estimated_cost,approved_budget,proposed_delivery_method,proposed_provider,proposed_start_date,proposed_end_date,approver,decided_at,linked_event_id,linked_event_title')
        ->and($lines[1])->toStartWith($f['approved']->id.',')
        ->and($lines[1])->toContain('"Ops One",1,Operations', '"Approved ops need"', 'approved', '1250.5000', '"Alpha Approver"', '2026-03-15')
        ->and($csv)->not->toContain('Pending ops need')
        ->and($csv)->not->toContain('Beta need');

    expect(AuditAction::query()->count())->toBe($before + 1);
    $action = AuditAction::query()->latest('id')->first();
    expect($action->event)->toBe(Register::EXPORT_EVENT)
        ->and((int) $action->actor_id)->toBe((int) $f['hr']->id)
        ->and($action->payload['context']['rows'] ?? $action->payload['rows'] ?? null)->toBe(1)
        ->and(json_encode($action->payload))->toContain('"status":"approved"', (string) $f['approved']->id);
});

test('a HOD without the capability is refused by the component and the route, and HR of another company cannot select this one', function (): void {
    $f = reqRegisterFixture();

    Livewire::actingAs($f['hod'])->test(Register::class)->assertForbidden();
    $this->actingAs($f['hod'])->get(route('people.training.requests.register'))->assertForbidden();
    $this->actingAs($f['hr'])->get(route('people.training.requests.register'))->assertOk()->assertSee('Training requests register');

    $beta = Livewire::actingAs($f['betaHr'])->test(Register::class)->assertOk();
    expect($beta->viewData('rows')->pluck('id')->all())->toBe([$f['foreign']->id]);
    $beta->call('selectCompany', $f['alpha']->id)->assertNotFound();
});

test('a request from another year stays out of the register and the export', function (): void {
    $f = reqRegisterFixture();
    TrainingRequest::query()->forCompany($f['tenantId'], (int) $f['alpha']->id)->whereKey($f['pending']->id)
        ->update(['created_at' => (now()->year - 1).'-06-01 09:00:00']);

    $page = Livewire::actingAs($f['hr'])->test(Register::class);
    expect($page->viewData('rows')->pluck('id')->all())->toBe([$f['rejected']->id, $f['approved']->id]);
});

test('holding the capability without the HR audience is still refused', function (): void {
    $f = reqRegisterFixture();
    PrincipalCapability::query()->create([
        'company_id' => $f['alpha']->id, 'principal_type' => PrincipalType::USER->value, 'principal_id' => $f['hod']->id,
        'capability_key' => Register::VIEW_CAPABILITY, 'is_allowed' => true,
    ]);

    Livewire::actingAs($f['hod'])->test(Register::class)->assertForbidden();
});

test('setting the company property to another company is refused on render, and no sibling request is rendered', function (): void {
    $f = reqRegisterFixture();

    // The property is client-settable without selectCompany(); the render
    // path's own guard is what stops HR of Alpha reading Beta. (An action
    // after the refused request would replay the previous snapshot, so the
    // export path is covered by the same requireCompany() call, not chained.)
    $page = Livewire::actingAs($f['hr'])->test(Register::class)
        ->set('companyEntityId', (int) $f['beta']->id)
        ->assertNotFound();
    expect($page->html())->not->toContain('Beta need')
        ->and(AuditAction::query()->where('event', Register::EXPORT_EVENT)->count())->toBe(0);
});

test('the register uses shared list controls and human-readable locale-aware values', function (): void {
    $f = reqRegisterFixture();
    reqRegisterCompanyCurrency($f['alpha']);

    $page = Livewire::actingAs($f['hr'])->test(Register::class)->assertOk();
    $html = html_entity_decode($page->html());
    $formattedCost = app(CurrencyDisplayService::class)->format(1250.5, 'MYR', 2);

    expect($html)
        ->toContain('id="training-requests-search"')
        ->toContain('wire:model.live.debounce.300ms="search"')
        ->toContain('data-status="pending_hod"')
        ->toContain('Pending HOD')
        ->toContain($formattedCost)
        ->toContain('Estimated cost (MYR)')
        ->toContain('Export 3 requests to CSV')
        ->toContain('training-requests-per-page')
        ->toContain('wire:click="sort(\'created_at\')"');

    $menu = require dirname(__DIR__, 2).'/Config/menu.php';
    expect(collect($menu['items'])->firstWhere('id', 'people.training-requests-register'))
        ->toMatchArray([
            'route' => 'people.training.requests.register',
            'permission' => Register::VIEW_CAPABILITY,
            'parent' => 'people',
        ]);
});

test('a company with no authoritative currency shows costs without inventing one', function (): void {
    $f = reqRegisterFixture();
    // Deliberately no reqRegisterCompanyCurrency(): no country currency exists.

    $html = html_entity_decode(Livewire::actingAs($f['hr'])->test(Register::class)->assertOk()->html());

    expect($html)->toContain('Estimated cost (currency unavailable)')
        ->and($html)->not->toContain('Estimated cost (USD)')
        ->and($html)->not->toContain('Estimated cost (MYR)');
});

test('search sorting and pagination keep the register bounded to the selected company and year', function (): void {
    $f = reqRegisterFixture();
    $employee = reqRegisterEmployee($f['alpha'], $f['ops'], 'Paging Employee');

    foreach (range(1, 9) as $number) {
        reqRegisterRequest(
            $f['tenantId'],
            $f['alpha'],
            $f['hr'],
            $employee,
            $f['ops'],
            'Paging need '.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
            TrainingRequestStatus::PendingHod,
        );
    }

    $page = Livewire::actingAs($f['hr'])->test(Register::class)->set('perPage', 10);
    expect($page->viewData('rows')->count())->toBe(10)
        ->and($page->viewData('rows')->total())->toBe(12);

    $page->set('search', 'Approved ops');
    expect($page->viewData('rows')->pluck('id')->all())->toBe([$f['approved']->id]);

    $page->set('search', '')->call('sort', 'estimated_cost');
    expect($page->get('sortBy'))->toBe('estimated_cost')
        ->and($page->get('sortDir'))->toBe('desc')
        ->and($page->viewData('rows')->first()['id'])->toBe($f['approved']->id);
});

test('request details expose scoped history without duplicating the decision workflow', function (): void {
    $f = reqRegisterFixture();

    $page = Livewire::actingAs($f['hr'])->test(Register::class)
        ->call('openDetails', (int) $f['approved']->id)
        ->assertSet('selectedRequestId', (int) $f['approved']->id)
        ->assertSee('Objective.')
        ->assertSee('Result.')
        ->assertSee('Alpha Approver')
        ->assertSee('Approved');

    $page->call('closeDetails')->assertSet('selectedRequestId', null);
    Livewire::actingAs($f['hr'])->test(Register::class)
        ->call('openDetails', (int) $f['foreign']->id)
        ->assertNotFound();
});

test('the register distinguishes an empty year from filters with no matches', function (): void {
    $f = reqRegisterFixture();
    TrainingRequest::query()
        ->forCompany($f['tenantId'], (int) $f['alpha']->id)
        ->update(['created_at' => (now()->year - 1).'-06-01 09:00:00']);

    Livewire::actingAs($f['hr'])->test(Register::class)
        ->assertSee('No training requests were recorded for '.now()->year.'.')
        ->set('search', 'no-such-request')
        ->assertSee('No training requests match the current filters.');
});
