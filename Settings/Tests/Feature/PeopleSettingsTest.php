<?php

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use App\Modules\People\Settings\Models\EmployeePortalAccess;
use App\Modules\People\Settings\Models\EmployeeProfileChangeRequest;
use App\Modules\People\Settings\Models\EmployeeWorkProfile;
use App\Modules\People\Settings\Models\PeopleCalendarException;
use App\Modules\People\Settings\Models\PeopleImportJob;
use App\Modules\People\Settings\Models\PeopleReferenceAlias;
use App\Modules\People\Settings\Models\PeopleReferenceEntry;
use App\Modules\People\Settings\Models\PeopleRestrictedPersonEntry;
use App\Modules\People\Settings\Services\EmployeePortalAccessService;
use App\Modules\People\Settings\Services\PeopleReferenceExportBuilder;
use App\Modules\People\Settings\Services\PeopleReferenceImportService;

test('people reference data uses honest BLB names and preserves ipayroll source labels', function (): void {
    $company = Company::factory()->create();

    $entry = PeopleReferenceEntry::query()->create([
        'company_id' => $company->id,
        'type' => PeopleReferenceEntry::TYPE_JOB_TITLE,
        'code' => 'AC-EXEC',
        'name' => 'Account Executive',
        'source_system' => 'ipayroll',
        'source_label' => 'Occupation',
        'source_code' => 'AC EXEC',
    ]);

    expect(PeopleReferenceEntry::labels()[PeopleReferenceEntry::TYPE_JOB_TITLE])->toBe('Job Title')
        ->and($entry->source_label)->toBe('Occupation')
        ->and($entry->type)->toBe('job_title');
});

test('reference import dry run reports duplicates without writing entries', function (): void {
    $company = Company::factory()->create();

    $job = app(PeopleReferenceImportService::class)->import(
        companyId: $company->id,
        targetType: PeopleReferenceEntry::TYPE_EMPLOYMENT_GROUP,
        rows: [
            ['Code' => 'EXEC-SR', 'Description' => 'EXECUTIVE - SENIOR LEVEL'],
            ['Code' => 'EXEC-SR', 'Description' => 'EXECUTIVE - SENIOR LEVEL'],
            ['Code' => '', 'Description' => 'GENERAL WORKERS'],
        ],
        dryRun: true,
        sourceLabel: 'Category',
    );

    expect($job)->toBeInstanceOf(PeopleImportJob::class)
        ->and($job->status)->toBe(PeopleImportJob::STATUS_FAILED)
        ->and($job->summary['accepted_rows'])->toBe(1)
        ->and($job->summary['error_rows'])->toBe(2)
        ->and(PeopleReferenceEntry::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('reference import writes entries and aliases for migration traceability', function (): void {
    $company = Company::factory()->create();

    $job = app(PeopleReferenceImportService::class)->import(
        companyId: $company->id,
        targetType: PeopleReferenceEntry::TYPE_WORKFORCE_CLASS,
        rows: [
            ['Code' => 'DIRECT', 'Description' => 'DIRECT LABOR'],
            ['Code' => 'OFFICE', 'Description' => 'OFFICE LABOR'],
        ],
        dryRun: false,
        sourceLabel: 'Job',
    );

    expect($job->status)->toBe(PeopleImportJob::STATUS_IMPORTED)
        ->and(PeopleReferenceEntry::query()->where('company_id', $company->id)->where('type', PeopleReferenceEntry::TYPE_WORKFORCE_CLASS)->count())->toBe(2)
        ->and(PeopleReferenceAlias::query()->where('company_id', $company->id)->where('source_type', 'Job')->count())->toBe(2);
});

test('reference csv import and export use scoped data contracts', function (): void {
    $company = Company::factory()->create();
    $imports = app(PeopleReferenceImportService::class);
    $rows = $imports->rowsFromContent("Code,Description,Active\nTECHNICIAN,TECHNICIAN,1\n", 'OccupationList.csv');

    $imports->import(
        companyId: $company->id,
        targetType: PeopleReferenceEntry::TYPE_JOB_TITLE,
        rows: $rows,
        dryRun: false,
        sourceLabel: 'Occupation',
    );

    $export = app(PeopleReferenceExportBuilder::class)->csv($company->id, PeopleReferenceEntry::TYPE_JOB_TITLE);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['Code'])->toBe('TECHNICIAN')
        ->and($export['filename'])->toBe('people-reference-job_title.csv')
        ->and($export['content'])->toContain('job_title,TECHNICIAN,TECHNICIAN');
});

test('employee work profile links payroll relevant settings historically', function (): void {
    $company = Company::factory()->create();
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $costCenter = PeopleReferenceEntry::query()->create([
        'company_id' => $company->id,
        'type' => PeopleReferenceEntry::TYPE_COST_CENTER,
        'code' => 'PROD',
        'name' => 'Production',
    ]);
    $jobTitle = PeopleReferenceEntry::query()->create([
        'company_id' => $company->id,
        'type' => PeopleReferenceEntry::TYPE_JOB_TITLE,
        'code' => 'TECHNICIAN',
        'name' => 'Technician',
    ]);

    $profile = EmployeeWorkProfile::query()->create([
        'employee_id' => $employee->id,
        'cost_center_id' => $costCenter->id,
        'job_title_id' => $jobTitle->id,
        'pay_rate_type' => 'monthly',
        'hired_on' => '2026-01-01',
    ]);

    expect($profile->jobTitle->name)->toBe('Technician')
        ->and($employee->refresh()->workProfile->costCenter->code)->toBe('PROD');
});

test('employee portal access provisions accounts and records invitation delivery', function (): void {
    $company = Company::factory()->create();
    $employee = Employee::factory()->create(['company_id' => $company->id, 'employee_number' => 'E0001', 'email' => 'employee@example.test']);
    $user = User::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id, 'email' => 'employee@example.test']);

    $service = app(EmployeePortalAccessService::class);
    $access = $service->provision($employee, $user);
    $log = $service->sendAccessInvitation($access, $company->id);

    expect($access)->toBeInstanceOf(EmployeePortalAccess::class)
        ->and($access->login_identifier)->toBe('employee@example.test')
        ->and($access->status)->toBe(EmployeePortalAccess::STATUS_PENDING)
        ->and($access->refresh()->last_invited_at)->not->toBeNull()
        ->and($log->subject)->toBe('Employee access invitation');
});

test('profile change requests restricted entries and calendar exceptions are first class records', function (): void {
    $company = Company::factory()->create();
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $user = User::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id]);
    $calendar = PeopleReferenceEntry::query()->create([
        'company_id' => $company->id,
        'type' => PeopleReferenceEntry::TYPE_WORK_CALENDAR,
        'code' => 'MY-STD',
        'name' => 'Malaysia Standard Work Calendar',
    ]);

    $request = EmployeeProfileChangeRequest::query()->create([
        'employee_id' => $employee->id,
        'requested_by_user_id' => $user->id,
        'request_type' => 'profile_update',
        'status' => EmployeeProfileChangeRequest::STATUS_SUBMITTED,
        'requested_changes' => ['mobile_number' => '+60123456789'],
        'submitted_at' => now(),
    ]);

    $restricted = PeopleRestrictedPersonEntry::query()->create([
        'company_id' => $company->id,
        'person_name' => 'Example Person',
        'document_type' => 'passport',
        'document_number' => 'P000000',
        'summary' => 'Do not rehire without HR review.',
    ]);

    $exception = PeopleCalendarException::query()->create([
        'work_calendar_id' => $calendar->id,
        'occurs_on' => '2026-02-17',
        'name' => 'Replacement holiday',
        'kind' => 'non_working_day',
    ]);

    expect($request->requested_changes['mobile_number'])->toBe('+60123456789')
        ->and($restricted->visibility)->toBe('restricted')
        ->and($exception->workCalendar->code)->toBe('MY-STD');
});

test('people settings workbench renders for authorized admins', function (): void {
    $user = createAdminUser();

    $this->actingAs($user)
        ->get(route('people.settings.index'))
        ->assertOk()
        ->assertSee('People Settings')
        ->assertSee('Reference Data');
});
