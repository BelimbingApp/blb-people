<?php

use App\Core\Company\Services\PrimaryCompanyManager;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Leave\Database\Seeders\Dev\DevLeaveSeeder;
use App\Domains\People\Leave\Models\LeaveRequest;

test('dev leave seeder is idempotent under sqlite', function (): void {
    $this->app['env'] = 'local';

    $company = app(PrimaryCompanyManager::class)->platformOperatorCompany();
    expect($company)->not->toBeNull();

    Employee::factory()->count(4)->create([
        'company_id' => $company->id,
        'employee_type' => 'full_time',
        'status' => 'active',
    ]);

    $this->seed(DevLeaveSeeder::class);

    $firstCount = LeaveRequest::query()->where('company_id', $company->id)->count();
    expect($firstCount)->toBeGreaterThan(0);

    $this->seed(DevLeaveSeeder::class);

    expect(LeaveRequest::query()->where('company_id', $company->id)->count())->toBe($firstCount);
});
