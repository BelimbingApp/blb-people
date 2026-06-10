<?php

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Claim\Exceptions\ClaimRequestLifecycleException;
use App\Modules\People\Claim\Models\ClaimAssignmentLine;
use App\Modules\People\Claim\Models\ClaimPolicy;
use App\Modules\People\Claim\Models\ClaimPolicyBand;
use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Models\ClaimType;
use App\Modules\People\Claim\Services\SubmitClaimRequestService;

require_once __DIR__.'/ClaimPolicyEvaluationTest.php';

function makeSecondaryAssignmentLine(array $f, array $typeOverrides = [], array $policyOverrides = []): ClaimAssignmentLine
{
    $company = Company::query()->findOrFail(Company::LICENSEE_ID);

    $type = ClaimType::query()->create(array_merge([
        'company_id' => $company->id,
        'code' => 'mileage_'.uniqid(),
        'name' => 'Mileage',
        'default_unit' => ClaimType::UNIT_DISTANCE,
        'calculation_mode' => 'rate_times_quantity',
        'receipt_requirement' => ClaimType::RECEIPT_NEVER,
        'provider_required' => false,
        'payroll_eligible' => true,
        'sort_order' => 20,
        'allow_employee_submission' => true,
        'allow_on_behalf_submission' => true,
        'admin_only' => false,
        'advance_settlement_allowed' => false,
        'status' => ClaimType::STATUS_ACTIVE,
    ], $typeOverrides));

    $policy = ClaimPolicy::query()->create(array_merge([
        'company_id' => $company->id,
        'code' => 'mileage_policy_'.uniqid(),
        'name' => 'Mileage Policy',
        'item_mode' => ClaimPolicy::MODE_SINGLE_VALUE,
        'encumber_pending' => true,
        'effective_from' => '2026-01-01',
        'version' => 1,
        'status' => 'active',
    ], $policyOverrides));

    ClaimPolicyBand::query()->create([
        'claim_policy_id' => $policy->id,
        'logical_operator' => '<=',
        'threshold_value' => null,
        'rate' => 1,
        'per_claim_limit' => 1000.00,
        'per_month_limit' => 2000.00,
        'per_year_limit' => 10000.00,
        'sort_order' => 10,
    ]);

    return ClaimAssignmentLine::query()->create([
        'claim_assignment_id' => $f['assignment']->id,
        'claim_type_id' => $type->id,
        'claim_policy_id' => $policy->id,
        'sort_order' => 20,
        'status' => ClaimAssignmentLine::STATUS_ACTIVE,
    ]);
}

it('submits a request with two compatible lines', function () {
    $f = makeClaimFixture();
    $second = makeSecondaryAssignmentLine($f);

    /** @var Employee $employee */
    $employee = $f['employee'];

    $request = app(SubmitClaimRequestService::class)->submitLines(
        employee: $employee,
        assignment: $f['assignment'],
        lineSpecs: [
            [
                'assignment_line' => $f['line'],
                'incurred_on' => new DateTimeImmutable('2026-06-10'),
                'requested_amount' => 120.00,
                'description' => 'Clinic visit',
            ],
            [
                'assignment_line' => $second,
                'incurred_on' => new DateTimeImmutable('2026-06-11'),
                'requested_amount' => 250.00,
                'description' => 'Mileage to client',
            ],
        ],
    );

    expect($request->status)->toBe(ClaimRequest::STATUS_SUBMITTED);
    expect((float) $request->requested_amount)->toBe(370.00);
    expect($request->lines()->count())->toBe(2);
    expect($request->metadata['line_count'])->toBe(2);

    // Strictest = the larger 250 line
    expect((float) $request->strictest_line_snapshot['requested_amount'])->toBe(250.0);
});

it('blocks submission when lines require incompatible approval profiles', function () {
    $f = makeClaimFixture();
    $f['policy']->update(['approval_profile_key' => 'manager_default']);

    $second = makeSecondaryAssignmentLine($f, policyOverrides: ['approval_profile_key' => 'finance_vp']);

    /** @var Employee $employee */
    $employee = $f['employee'];

    expect(fn () => app(SubmitClaimRequestService::class)->submitLines(
        employee: $employee,
        assignment: $f['assignment'],
        lineSpecs: [
            [
                'assignment_line' => $f['line'],
                'incurred_on' => new DateTimeImmutable('2026-06-10'),
                'requested_amount' => 50.00,
            ],
            [
                'assignment_line' => $second,
                'incurred_on' => new DateTimeImmutable('2026-06-11'),
                'requested_amount' => 50.00,
            ],
        ],
    ))->toThrow(ClaimRequestLifecycleException::class, 'incompatible approval profiles');
});

it('allows multiple lines sharing the same approval profile', function () {
    $f = makeClaimFixture();
    $f['policy']->update(['approval_profile_key' => 'manager_default']);

    $second = makeSecondaryAssignmentLine($f, policyOverrides: ['approval_profile_key' => 'manager_default']);

    /** @var Employee $employee */
    $employee = $f['employee'];

    $request = app(SubmitClaimRequestService::class)->submitLines(
        employee: $employee,
        assignment: $f['assignment'],
        lineSpecs: [
            [
                'assignment_line' => $f['line'],
                'incurred_on' => new DateTimeImmutable('2026-06-10'),
                'requested_amount' => 50.00,
            ],
            [
                'assignment_line' => $second,
                'incurred_on' => new DateTimeImmutable('2026-06-11'),
                'requested_amount' => 75.00,
            ],
        ],
    );

    expect($request->approval_profile_key)->toBe('manager_default');
});

it('refuses a request with no lines', function () {
    $f = makeClaimFixture();
    /** @var Employee $employee */
    $employee = $f['employee'];

    expect(fn () => app(SubmitClaimRequestService::class)->submitLines(
        employee: $employee,
        assignment: $f['assignment'],
        lineSpecs: [],
    ))->toThrow(ClaimRequestLifecycleException::class, 'at least one line');
});

it('single-line submit() still works and delegates to submitLines()', function () {
    $f = makeClaimFixture();
    /** @var Employee $employee */
    $employee = $f['employee'];

    $request = app(SubmitClaimRequestService::class)->submit(
        employee: $employee,
        assignment: $f['assignment'],
        assignmentLine: $f['line'],
        incurredOn: new DateTimeImmutable('2026-06-10'),
        requestedAmount: 80.00,
    );

    expect($request->status)->toBe(ClaimRequest::STATUS_SUBMITTED);
    expect($request->lines()->count())->toBe(1);
    expect((float) $request->requested_amount)->toBe(80.00);
});
