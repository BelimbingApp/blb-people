<?php

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Claim\Exceptions\ClaimCohortPredicateException;
use App\Modules\People\Claim\Exceptions\ClaimRequestLifecycleException;
use App\Modules\People\Claim\Services\ClaimCohortPredicateService;
use App\Modules\People\Claim\Services\SubmitClaimRequestService;

require_once __DIR__.'/ClaimPolicyEvaluationTest.php';

it('matches on a scalar value', function () {
    $employee = Employee::factory()->create(['employee_type' => 'regular']);
    $svc = app(ClaimCohortPredicateService::class);

    expect($svc->matches($employee, ['employee_type' => 'regular']))->toBeTrue();
    expect($svc->matches($employee, ['employee_type' => 'contract']))->toBeFalse();
});

it('matches on a list (IN) value', function () {
    $employee = Employee::factory()->create(['employee_type' => 'regular']);
    $svc = app(ClaimCohortPredicateService::class);

    expect($svc->matches($employee, ['employee_type' => ['regular', 'contract']]))->toBeTrue();
    expect($svc->matches($employee, ['employee_type' => ['contract', 'intern']]))->toBeFalse();
});

it('AND-s multiple clauses', function () {
    $employee = Employee::factory()->create([
        'employee_type' => 'regular',
        'status' => 'active',
    ]);
    $svc = app(ClaimCohortPredicateService::class);

    expect($svc->matches($employee, ['employee_type' => 'regular', 'status' => 'active']))->toBeTrue();
    expect($svc->matches($employee, ['employee_type' => 'regular', 'status' => 'inactive']))->toBeFalse();
});

it('matches everyone when predicate is null or empty', function () {
    $employee = Employee::factory()->create();
    $svc = app(ClaimCohortPredicateService::class);

    expect($svc->matches($employee, null))->toBeTrue();
    expect($svc->matches($employee, []))->toBeTrue();
});

it('throws on unknown predicate key', function () {
    $employee = Employee::factory()->create();
    $svc = app(ClaimCohortPredicateService::class);

    expect(fn () => $svc->matches($employee, ['bogus_key' => 'whatever']))
        ->toThrow(ClaimCohortPredicateException::class, 'bogus_key');
});

it('refuses submission when assignment cohort excludes employee', function () {
    $f = makeClaimFixture();
    $f['assignment']->update(['cohort_predicate' => ['employee_type' => 'agent']]);
    $f['employee']->update(['employee_type' => 'regular']);

    expect(fn () => app(SubmitClaimRequestService::class)->submit(
        employee: $f['employee'],
        assignment: $f['assignment'],
        assignmentLine: $f['line'],
        incurredOn: new DateTimeImmutable('2026-06-10'),
        requestedAmount: 50.00,
    ))->toThrow(ClaimRequestLifecycleException::class, 'not eligible for claim assignment');
});

it('refuses submission when policy cohort excludes employee', function () {
    $f = makeClaimFixture();
    $f['policy']->update(['cohort_predicate' => ['status' => 'inactive']]);
    $f['employee']->update(['status' => 'active']);

    expect(fn () => app(SubmitClaimRequestService::class)->submit(
        employee: $f['employee'],
        assignment: $f['assignment'],
        assignmentLine: $f['line'],
        incurredOn: new DateTimeImmutable('2026-06-10'),
        requestedAmount: 50.00,
    ))->toThrow(ClaimRequestLifecycleException::class, 'not eligible for claim policy');
});

it('accepts submission when cohort predicates match', function () {
    $f = makeClaimFixture();
    $f['assignment']->update(['cohort_predicate' => ['employee_type' => 'regular']]);
    $f['policy']->update(['cohort_predicate' => ['status' => 'active']]);
    $f['employee']->update(['employee_type' => 'regular', 'status' => 'active']);

    $request = app(SubmitClaimRequestService::class)->submit(
        employee: $f['employee'],
        assignment: $f['assignment'],
        assignmentLine: $f['line'],
        incurredOn: new DateTimeImmutable('2026-06-10'),
        requestedAmount: 50.00,
    );

    expect($request->id)->not->toBeNull();
});
