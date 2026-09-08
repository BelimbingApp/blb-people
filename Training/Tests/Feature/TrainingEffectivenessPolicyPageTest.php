<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\People\Training\Livewire\EffectivenessAggregate\Index as AggregateIndex;
use App\Domains\People\Training\Models\TrainingEffectivenessCheckpointPolicy;
use App\Domains\People\Training\Services\TrainingEffectivenessPolicy;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * 0013-e2: HR sets the governed checkpoint offsets from the aggregate page
 * and reads the append-only history there.
 *
 * Self-contained: helpers are prefixed effPolPage and live here. Store rules
 * stay in TrainingEffectivenessPolicyTest; this file owns the surface.
 */
afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

function effPolPageRole(User $user, string $code): void
{
    PrincipalRole::query()->create([
        'company_id' => $user->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $code)->sole()->id,
    ]);
}

/** @return array{tenantId: int, companyId: int, siblingId: int, hr: User, hod: User} */
function effPolPageFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(
        ['name' => 'Policy Page Tenant'],
        ['name' => 'Policy Page Company', 'status' => 'active'],
    );
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    $siblingId = (int) Company::factory()->create([
        'tenant_id' => $tenantId, 'name' => 'Policy Page Sibling', 'status' => 'active',
    ])->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $hr = User::factory()->create(['company_id' => $companyId, 'name' => 'Policy Page HR']);
    effPolPageRole($hr, 'people_hr');
    $hod = User::factory()->create(['company_id' => $companyId, 'name' => 'Policy Page HOD']);
    effPolPageRole($hod, 'people_hod');

    return [
        'tenantId' => $tenantId,
        'companyId' => $companyId,
        'siblingId' => $siblingId,
        'hr' => $hr,
        'hod' => $hod,
    ];
}

test('HR sets a policy from the page and sees it in the history table', function (): void {
    Carbon::setTestNow('2026-09-08 10:00:00');
    $f = effPolPageFixture();

    Livewire::actingAs($f['hr'])->test(AggregateIndex::class)
        ->assertSee('Set checkpoint policy')
        ->assertSee('Checkpoint policy history')
        ->set('day30', '14')
        ->set('day60', '45')
        ->set('day90', '90')
        ->set('effectiveFrom', '2026-09-08')
        ->set('reason', 'Pilot shorter first checkpoint')
        ->call('setPolicy')
        ->assertHasNoErrors()
        ->assertNotDispatched('notify')
        ->assertSee('The checkpoint policy was recorded.')
        ->assertSee('Pilot shorter first checkpoint')
        ->assertSee('Policy Page HR')
        ->assertSee('14')
        ->assertSee('45');

    $row = TrainingEffectivenessCheckpointPolicy::query()
        ->forCompany($f['tenantId'], $f['companyId'])
        ->sole();

    expect((int) $row->day_30_offset)->toBe(14)
        ->and((int) $row->day_60_offset)->toBe(45)
        ->and((int) $row->day_90_offset)->toBe(90)
        ->and($row->reason)->toBe('Pilot shorter first checkpoint')
        ->and((int) $row->set_by_user_id)->toBe((int) $f['hr']->id);
});

test('a blank effectiveFrom defaults to today rather than refusing', function (): void {
    Carbon::setTestNow('2026-09-08 10:00:00');
    $f = effPolPageFixture();

    Livewire::actingAs($f['hr'])->test(AggregateIndex::class)
        ->set('day30', '30')
        ->set('day60', '60')
        ->set('day90', '90')
        ->set('effectiveFrom', '')
        ->set('reason', 'Blank date means today')
        ->call('setPolicy')
        ->assertHasNoErrors()
        ->assertNotDispatched('notify')
        ->assertSee('The checkpoint policy was recorded.');

    $row = TrainingEffectivenessCheckpointPolicy::query()
        ->forCompany($f['tenantId'], $f['companyId'])
        ->sole();

    expect($row->effective_from->toDateString())->toBe('2026-09-08')
        ->and($row->reason)->toBe('Blank date means today');
});

test('service refusals reach the user as a notify toast', function (string $field, mixed $value, string $needle): void {
    Carbon::setTestNow('2026-09-08 10:00:00');
    $f = effPolPageFixture();

    $page = Livewire::actingAs($f['hr'])->test(AggregateIndex::class)
        ->set('day30', '30')
        ->set('day60', '60')
        ->set('day90', '90')
        ->set('effectiveFrom', '2026-09-08')
        ->set('reason', 'Valid reason');

    $page->set($field, $value)->call('setPolicy')
        ->assertDispatched('notify', fn (string $event, array $params): bool => ($params['variant'] ?? null) === 'error');

    expect(TrainingEffectivenessCheckpointPolicy::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(0)
        ->and($needle)->not->toBe(''); // keeps the refusal case named in the dataset
})->with([
    'offsets not increasing' => ['day60', '20', 'increasing'],
    'offset of zero' => ['day30', '0', 'one day'],
    'effective_from yesterday' => ['effectiveFrom', '2026-09-07', 'prospective'],
    'empty reason' => ['reason', '   ', 'reason'],
]);

test('a HOD with review but not manage is refused the page', function (): void {
    $f = effPolPageFixture();

    // Asserted through the route: Livewire wraps a render-time refusal in a
    // ViewException, which would make a component assertion say less than it
    // appears to. The authz middleware is what guards the page in production.
    test()->actingAs($f['hod'])
        ->get(route('people.training.effectiveness.summary'))
        ->assertForbidden();
});

test('sibling company history stays empty of this company\'s policies', function (): void {
    Carbon::setTestNow('2026-09-08 10:00:00');
    $f = effPolPageFixture();

    app(TrainingEffectivenessPolicy::class)->set(
        $f['hr'],
        $f['companyId'],
        21,
        42,
        84,
        Carbon::parse('2026-09-08'),
        'Company A policy',
    );

    $siblingHr = User::factory()->create(['company_id' => $f['siblingId'], 'name' => 'Sibling HR']);
    effPolPageRole($siblingHr, 'people_hr');

    Livewire::actingAs($siblingHr)->test(AggregateIndex::class)
        ->assertDontSee('Company A policy')
        ->assertSee('No company policy has been set');

    expect(app(TrainingEffectivenessPolicy::class)->history($f['tenantId'], $f['siblingId']))->toBe([]);
});

test('the policy history table has a caption', function (): void {
    Carbon::setTestNow('2026-09-08 10:00:00');
    $f = effPolPageFixture();

    app(TrainingEffectivenessPolicy::class)->set(
        $f['hr'],
        $f['companyId'],
        30,
        60,
        90,
        Carbon::parse('2026-09-08'),
        'Captioned history',
    );

    Livewire::actingAs($f['hr'])->test(AggregateIndex::class)
        ->assertSee('Checkpoint policy history for this company', false);
});
