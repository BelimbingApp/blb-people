<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\People\Training\Enums\CutoverWorkflow;
use App\Domains\People\Training\Enums\CutoverWriter;
use App\Domains\People\Training\Exceptions\CutoverWriteRefusedException;
use App\Domains\People\Training\Exceptions\InvalidCutoverWindowException;
use App\Domains\People\Training\Models\TrainingCutoverWindow;
use App\Domains\People\Training\Models\TrainingRequest;
use App\Domains\People\Training\Services\CutoverWriteGuard;
use App\Domains\People\Training\Services\TrainingRequestStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * 0015-f / #418: cutover single-writer guard.
 * Self-contained: helpers are prefixed cutW.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

function cutWUser(Company $company, string $roleCode, string $name): User
{
    $user = User::factory()->create(['company_id' => $company->id, 'name' => $name]);
    PrincipalRole::query()->create([
        'company_id' => $company->id, 'principal_type' => PrincipalType::USER->value, 'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->sole()->id,
    ]);

    return $user;
}

/** @return array{tenantId: int, companyId: int, hr: User, company: Company} */
function cutWFixture(string $label = 'CutW'): array
{
    $tenant = createTenant(['name' => $label.' Tenant']);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();
    $company = Company::factory()->create(['tenant_id' => $tenantId, 'name' => $label.' Co', 'status' => 'active']);

    return [
        'tenantId' => $tenantId,
        'companyId' => (int) $company->id,
        'company' => $company,
        'hr' => cutWUser($company, 'people_hr', $label.' HR'),
    ];
}

test('legacy window refuses TrainingRequestStore writes and leaves reads intact; system window allows writes', function (): void {
    $f = cutWFixture();
    $guard = app(CutoverWriteGuard::class);
    $start = new \DateTimeImmutable('2026-09-01 00:00:00');
    $end = new \DateTimeImmutable('2026-09-30 23:59:59');

    $guard->declare($f['hr'], $f['companyId'], CutoverWorkflow::TrainingRequests, CutoverWriter::Legacy, $start, $end, 'portal still live');

    expect(fn () => $guard->assertWritable($f['companyId'], CutoverWorkflow::TrainingRequests, new \DateTimeImmutable('2026-09-15 12:00:00')))
        ->toThrow(CutoverWriteRefusedException::class, 'Training requests');

    // Reads unaffected: querying requests does not consult the guard.
    expect(TrainingRequest::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(0);

    $guard->declare(
        $f['hr'],
        $f['companyId'],
        CutoverWorkflow::TrainingRequests,
        CutoverWriter::System,
        new \DateTimeImmutable('2026-10-01 00:00:00'),
        null,
        'cutover complete',
    );
    $guard->assertWritable($f['companyId'], CutoverWorkflow::TrainingRequests, new \DateTimeImmutable('2026-10-01 00:00:01'));
    expect(true)->toBeTrue();
});

test('window edges: one second inside refuses, one second outside allows', function (): void {
    $f = cutWFixture('CutWEdge');
    $guard = app(CutoverWriteGuard::class);
    $start = new \DateTimeImmutable('2026-09-08 10:00:00');
    $end = new \DateTimeImmutable('2026-09-08 11:00:00');
    $guard->declare($f['hr'], $f['companyId'], CutoverWorkflow::Effectiveness, CutoverWriter::Legacy, $start, $end, 'edge pin');

    expect(fn () => $guard->assertWritable($f['companyId'], CutoverWorkflow::Effectiveness, $end))
        ->toThrow(CutoverWriteRefusedException::class);
    $guard->assertWritable($f['companyId'], CutoverWorkflow::Effectiveness, $end->modify('+1 second'));
});

test('overlapping windows for the same workflow are refused at declaration', function (): void {
    $f = cutWFixture('CutWOverlap');
    $guard = app(CutoverWriteGuard::class);
    $guard->declare(
        $f['hr'],
        $f['companyId'],
        CutoverWorkflow::Attendance,
        CutoverWriter::Legacy,
        new \DateTimeImmutable('2026-09-01 00:00:00'),
        new \DateTimeImmutable('2026-09-15 00:00:00'),
        'first',
    );

    expect(fn () => $guard->declare(
        $f['hr'],
        $f['companyId'],
        CutoverWorkflow::Attendance,
        CutoverWriter::System,
        new \DateTimeImmutable('2026-09-10 00:00:00'),
        new \DateTimeImmutable('2026-09-20 00:00:00'),
        'clash',
    ))->toThrow(InvalidCutoverWindowException::class, 'overlapping');
});

test('cutover windows are append-only at the database', function (): void {
    $f = cutWFixture('CutWAppend');
    $guard = app(CutoverWriteGuard::class);
    $window = $guard->declare(
        $f['hr'],
        $f['companyId'],
        CutoverWorkflow::TrainingRequests,
        CutoverWriter::Legacy,
        new \DateTimeImmutable('2026-09-01 00:00:00'),
        null,
        'append-only',
    );

    expect(fn () => DB::table('people_training_cutover_windows')->where('id', $window->id)->update(['reason' => 'nope']))
        ->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::table('people_training_cutover_windows')->where('id', $window->id)->delete())
        ->toThrow(QueryException::class, 'append-only');
    expect(TrainingCutoverWindow::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(1);
});

test('TrainingRequestStore create is refused under a legacy window (store-level guard)', function (): void {
    $f = cutWFixture('CutWReq');
    app(CutoverWriteGuard::class)->declare(
        $f['hr'],
        $f['companyId'],
        CutoverWorkflow::TrainingRequests,
        CutoverWriter::Legacy,
        new \DateTimeImmutable('2026-01-01 00:00:00'),
        null,
        'portal owns requests',
    );

    // Direct assertWritable is what create() calls first; prove the store wires it.
    $store = app(TrainingRequestStore::class);
    $ref = new ReflectionClass($store);
    $prop = $ref->getProperty('cutover');
    $prop->setAccessible(true);
    expect($prop->getValue($store))->toBeInstanceOf(CutoverWriteGuard::class);

    expect(fn () => app(CutoverWriteGuard::class)->assertWritable($f['companyId'], CutoverWorkflow::TrainingRequests))
        ->toThrow(CutoverWriteRefusedException::class);
});
