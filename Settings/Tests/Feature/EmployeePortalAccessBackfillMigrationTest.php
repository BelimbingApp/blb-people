<?php

use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use Illuminate\Database\Migrations\Migration;

function loadEmployeePortalAccessBackfillMigration(): Migration
{
    return require dirname(__DIR__, 2).'/Database/Migrations/0320_01_02_000000_backfill_employee_portal_access_for_existing_links.php';
}

test('the backfill grandfathers an existing employee-user link with no portal access row', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Backfill Tenant']);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'employee_number' => 'B-001',
        'full_name' => 'Backfill Employee',
        'employee_type' => 'full_time',
    ]);
    $user = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'email' => 'backfill@example.test',
    ]);

    expect(EmployeePortalAccess::query()->where('employee_id', $employee->id)->exists())->toBeFalse();

    loadEmployeePortalAccessBackfillMigration()->up();

    $access = EmployeePortalAccess::query()->where('employee_id', $employee->id)->sole();

    expect($access->user_id)->toBe($user->id)
        ->and($access->status)->toBe(EmployeePortalAccess::STATUS_ACTIVE)
        ->and($access->metadata['backfilled_by'] ?? null)->toBe('0320_01_02_000000');
});

test('the backfill never overwrites an existing portal access row, active or otherwise', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Backfill Preserve Tenant']);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'employee_number' => 'B-002',
        'full_name' => 'Preserved Employee',
        'employee_type' => 'full_time',
    ]);
    $user = User::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id]);
    $existing = EmployeePortalAccess::query()->create([
        'employee_id' => $employee->id,
        'user_id' => $user->id,
        'display_name' => 'Preserved Employee',
        'status' => EmployeePortalAccess::STATUS_REVOKED,
        'revoked_at' => now(),
    ]);

    loadEmployeePortalAccessBackfillMigration()->up();

    expect(EmployeePortalAccess::query()->where('employee_id', $employee->id)->count())->toBe(1)
        ->and($existing->refresh()->status)->toBe(EmployeePortalAccess::STATUS_REVOKED);
});

test('the backfill skips a user whose employee_id points elsewhere or whose company does not match', function (): void {
    [$tenant, $companyA] = createTenantWithCompany(['name' => 'Backfill Mismatch Tenant A']);
    $companyB = Company::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Backfill Mismatch Company B']);
    $employee = Employee::factory()->create([
        'company_id' => $companyA->id,
        'employee_number' => 'B-003',
        'full_name' => 'Mismatched Company Employee',
        'employee_type' => 'full_time',
    ]);
    User::factory()->create(['company_id' => $companyB->id, 'employee_id' => $employee->id]);

    loadEmployeePortalAccessBackfillMigration()->up();

    expect(EmployeePortalAccess::query()->where('employee_id', $employee->id)->exists())->toBeFalse();
});

test('the backfill is idempotent and its rollback removes only the rows it created', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Backfill Rollback Tenant']);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'employee_number' => 'B-004',
        'full_name' => 'Rollback Employee',
        'employee_type' => 'full_time',
    ]);
    User::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id]);

    $migration = loadEmployeePortalAccessBackfillMigration();
    $migration->up();
    $migration->up();

    expect(EmployeePortalAccess::query()->where('employee_id', $employee->id)->count())->toBe(1);

    $migration->down();

    expect(EmployeePortalAccess::query()->where('employee_id', $employee->id)->exists())->toBeFalse();
});
