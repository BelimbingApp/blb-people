<?php

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Payroll\Contracts\Intake\PayrollContributionPayload;
use App\Modules\People\Payroll\Contracts\Intake\PayrollContributionState;
use App\Modules\People\Payroll\Models\PayrollCalendar;
use App\Modules\People\Payroll\Models\PayrollInput;
use App\Modules\People\Payroll\Models\PayrollPendingContribution;
use App\Modules\People\Payroll\Models\PayrollPeriod;
use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Payroll\Services\PayrollContributionIntake;
use App\Modules\People\Payroll\Services\PayrollContributionStatus;

const INTAKE_PERIOD_CODE = '2026-05';
const INTAKE_PERIOD_NAME = 'May 2026';
const INTAKE_PERIOD_START = '2026-05-01';
const INTAKE_PERIOD_END = '2026-05-31';
const INTAKE_RUN_CODE = 'MAY-2026';
const INTAKE_ANCHOR_DATE = '2026-05-15';

function intakeTestFixtures(string $runStatus = PayrollRun::STATUS_DRAFT): array
{
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $calendar = PayrollCalendar::query()->create([
        'company_id' => $company->id,
        'code' => 'MONTHLY',
        'name' => 'Monthly',
        'country_iso' => 'MY',
        'currency' => 'MYR',
        'frequency' => 'monthly',
    ]);
    $period = PayrollPeriod::query()->create([
        'payroll_calendar_id' => $calendar->id,
        'code' => INTAKE_PERIOD_CODE,
        'name' => INTAKE_PERIOD_NAME,
        'starts_on' => INTAKE_PERIOD_START,
        'ends_on' => INTAKE_PERIOD_END,
        'pay_date' => INTAKE_PERIOD_END,
    ]);
    $run = PayrollRun::query()->create([
        'company_id' => $company->id,
        'payroll_calendar_id' => $calendar->id,
        'payroll_period_id' => $period->id,
        'code' => INTAKE_RUN_CODE,
        'name' => INTAKE_PERIOD_NAME,
        'status' => $runStatus,
        'currency' => 'MYR',
    ]);

    return ['company' => $company, 'employee' => $employee, 'run' => $run];
}

function intakeTestPayload(Company $company, Employee $employee, string $payItemCode = 'CLM_MED'): PayrollContributionPayload
{
    return new PayrollContributionPayload(
        sourceType: 'claim_line',
        sourceId: 42,
        payItemCode: $payItemCode,
        periodAnchor: new DateTimeImmutable(INTAKE_ANCHOR_DATE),
        companyId: (int) $company->id,
        employeeId: (int) $employee->id,
        currency: 'MYR',
        occurredOn: new DateTimeImmutable(INTAKE_ANCHOR_DATE),
        inputType: PayrollInput::TYPE_REIMBURSEMENT,
        amount: 120.50,
        quantity: 1.0,
        rate: null,
        label: 'Medical claim',
        accountingSnapshot: ['debit' => '6100', 'credit' => '2100'],
        metadata: ['claim_request_id' => 7],
    );
}

it('ingests a payload into an open run and produces one PayrollInput', function (): void {
    ['company' => $company, 'employee' => $employee, 'run' => $run] = intakeTestFixtures();
    $payload = intakeTestPayload($company, $employee);

    $outcome = app(PayrollContributionIntake::class)->ingest($payload);

    expect($outcome->state)->toBe(PayrollContributionState::QUEUED_IN_RUN)
        ->and($outcome->payrollRunId)->toBe($run->id)
        ->and($outcome->payrollInputId)->not->toBeNull();

    expect(PayrollInput::query()->count())->toBe(1);
    $input = PayrollInput::query()->first();
    expect($input->pay_item_code)->toBe('CLM_MED')
        ->and((float) $input->amount)->toBe(120.50)
        ->and($input->source_type)->toBe('claim_line')
        ->and((int) $input->source_id)->toBe(42);

    expect(PayrollPendingContribution::query()->count())->toBe(1);
});

it('is idempotent on the composite source tuple', function (): void {
    ['company' => $company, 'employee' => $employee] = intakeTestFixtures();
    $payload = intakeTestPayload($company, $employee);
    $intake = app(PayrollContributionIntake::class);

    $first = $intake->ingest($payload);
    $second = $intake->ingest($payload);

    expect($first->payrollInputId)->toBe($second->payrollInputId)
        ->and($first->payrollPendingContributionId)->toBe($second->payrollPendingContributionId);
    expect(PayrollInput::query()->count())->toBe(1)
        ->and(PayrollPendingContribution::query()->count())->toBe(1);
});

it('persists pending when no payroll run covers the period anchor', function (): void {
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $payload = intakeTestPayload($company, $employee);

    $outcome = app(PayrollContributionIntake::class)->ingest($payload);

    expect($outcome->state)->toBe(PayrollContributionState::PENDING)
        ->and($outcome->payrollInputId)->toBeNull()
        ->and($outcome->payrollRunId)->toBeNull();

    expect(PayrollInput::query()->count())->toBe(0);
    expect(PayrollPendingContribution::query()->where('state', PayrollContributionState::PENDING)->count())->toBe(1);
});

it('returns rejected_locked when the only matching run is closed', function (): void {
    ['company' => $company, 'employee' => $employee] = intakeTestFixtures(PayrollRun::STATUS_CLOSED);
    $payload = intakeTestPayload($company, $employee);

    $outcome = app(PayrollContributionIntake::class)->ingest($payload);

    expect($outcome->state)->toBe(PayrollContributionState::REJECTED_LOCKED)
        ->and($outcome->payrollInputId)->toBeNull();
    expect(PayrollInput::query()->count())->toBe(0);
});

it('reverses a queued contribution by deleting the input', function (): void {
    ['company' => $company, 'employee' => $employee] = intakeTestFixtures();
    $payload = intakeTestPayload($company, $employee);
    $intake = app(PayrollContributionIntake::class);

    $intake->ingest($payload);
    expect(PayrollInput::query()->count())->toBe(1);

    $outcome = $intake->reverse(
        sourceType: $payload->sourceType,
        sourceId: $payload->sourceId,
        payItemCode: $payload->payItemCode,
        periodAnchor: $payload->periodAnchor,
        reason: 'cancelled by HR',
    );

    expect($outcome->state)->toBe(PayrollContributionState::REVERSED);
    expect(PayrollInput::query()->count())->toBe(0);
    $pending = PayrollPendingContribution::query()->first();
    expect($pending->state)->toBe(PayrollContributionState::REVERSED)
        ->and($pending->reason)->toBe('cancelled by HR');
});

it('reports queued_in_run status via the read API', function (): void {
    ['company' => $company, 'employee' => $employee] = intakeTestFixtures();
    $payload = intakeTestPayload($company, $employee);
    app(PayrollContributionIntake::class)->ingest($payload);

    $status = app(PayrollContributionStatus::class)->for(
        sourceType: $payload->sourceType,
        sourceId: $payload->sourceId,
        payItemCode: $payload->payItemCode,
        periodAnchor: $payload->periodAnchor,
    );

    expect($status->state)->toBe(PayrollContributionState::QUEUED_IN_RUN)
        ->and($status->payrollInputId)->not->toBeNull();
});

it('materialises pending contributions when a covering run is created', function (): void {
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $payload = intakeTestPayload($company, $employee);

    $first = app(PayrollContributionIntake::class)->ingest($payload);
    expect($first->state)->toBe(PayrollContributionState::PENDING)
        ->and(PayrollInput::query()->count())->toBe(0);

    // Now a covering run opens — model `created` event should materialise the pending row.
    $calendar = PayrollCalendar::query()->create([
        'company_id' => $company->id,
        'code' => 'MONTHLY',
        'name' => 'Monthly',
        'country_iso' => 'MY',
        'currency' => 'MYR',
        'frequency' => 'monthly',
    ]);
    $period = PayrollPeriod::query()->create([
        'payroll_calendar_id' => $calendar->id,
        'code' => INTAKE_PERIOD_CODE,
        'name' => INTAKE_PERIOD_NAME,
        'starts_on' => INTAKE_PERIOD_START,
        'ends_on' => INTAKE_PERIOD_END,
        'pay_date' => INTAKE_PERIOD_END,
    ]);
    PayrollRun::query()->create([
        'company_id' => $company->id,
        'payroll_calendar_id' => $calendar->id,
        'payroll_period_id' => $period->id,
        'code' => INTAKE_RUN_CODE,
        'name' => INTAKE_PERIOD_NAME,
        'status' => PayrollRun::STATUS_DRAFT,
        'currency' => 'MYR',
    ]);

    expect(PayrollInput::query()->count())->toBe(1);
    $status = app(PayrollContributionStatus::class)->for(
        sourceType: $payload->sourceType,
        sourceId: $payload->sourceId,
        payItemCode: $payload->payItemCode,
        periodAnchor: $payload->periodAnchor,
    );
    expect($status->state)->toBe(PayrollContributionState::QUEUED_IN_RUN);
});

it('treats different pay items on the same source as distinct contributions', function (): void {
    ['company' => $company, 'employee' => $employee] = intakeTestFixtures();
    $intake = app(PayrollContributionIntake::class);

    $intake->ingest(intakeTestPayload($company, $employee, payItemCode: 'CLM_MED'));
    $intake->ingest(intakeTestPayload($company, $employee, payItemCode: 'CLM_DENTAL'));

    expect(PayrollInput::query()->count())->toBe(2)
        ->and(PayrollPendingContribution::query()->count())->toBe(2);
});
