<?php

use App\Modules\People\Claim\Models\ClaimLine;
use App\Modules\People\Claim\Models\ClaimPolicyBand;
use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Services\ClaimPolicySimulationService;
use App\Modules\People\Claim\Services\ClaimPolicyValidationService;

require_once __DIR__.'/ClaimPolicyEvaluationTest.php';

// ─── Validation ──────────────────────────────────────────────────────

it('validation reports ok when policy is healthy', function () {
    $f = makeClaimFixture();
    $result = app(ClaimPolicyValidationService::class)->validate($f['policy']);

    expect($result['status'])->toBe('ok');
    expect($result['summary']['errors'])->toBe(0);
    expect($result['summary']['warnings'])->toBe(0);
});

it('validation flags empty bands as error', function () {
    $f = makeClaimFixture();
    ClaimPolicyBand::query()->where('claim_policy_id', $f['policy']->id)->delete();
    $f['policy']->load('bands');

    $result = app(ClaimPolicyValidationService::class)->validate($f['policy']);

    expect($result['status'])->toBe('error');
    $codes = array_column($result['findings'], 'code');
    expect($codes)->toContain('policy_bands_missing');
});

it('validation flags inactive policy as warning', function () {
    $f = makeClaimFixture();
    $f['policy']->update(['status' => 'inactive']);

    $result = app(ClaimPolicyValidationService::class)->validate($f['policy']->refresh());

    expect($result['status'])->toBe('warning');
    expect(array_column($result['findings'], 'code'))->toContain('policy_not_active');
});

it('validation flags unknown cohort key as error', function () {
    $f = makeClaimFixture();
    $f['policy']->update(['cohort_predicate' => ['bogus_key' => 'x']]);

    $result = app(ClaimPolicyValidationService::class)->validate($f['policy']->refresh());

    expect($result['status'])->toBe('error');
    expect(array_column($result['findings'], 'code'))->toContain('cohort_predicate_key_invalid');
});

it('validation warns when per-claim cap exceeds per-month cap', function () {
    $f = makeClaimFixture();
    ClaimPolicyBand::query()->where('claim_policy_id', $f['policy']->id)->update([
        'per_claim_limit' => 1000,
        'per_month_limit' => 500,
    ]);
    $f['policy']->load('bands');

    $result = app(ClaimPolicyValidationService::class)->validate($f['policy']);

    expect(array_column($result['findings'], 'code'))->toContain('band_per_claim_exceeds_month');
});

it('validation flags inverted effective range', function () {
    $f = makeClaimFixture();
    $f['policy']->update([
        'effective_from' => '2026-12-31',
        'effective_to' => '2026-01-01',
    ]);

    $result = app(ClaimPolicyValidationService::class)->validate($f['policy']->refresh());

    expect($result['status'])->toBe('error');
    expect(array_column($result['findings'], 'code'))->toContain('policy_effective_range_invalid');
});

it('validation result shape matches Attendance contract', function () {
    $f = makeClaimFixture();
    $result = app(ClaimPolicyValidationService::class)->validate($f['policy']);

    expect($result)->toHaveKeys(['status', 'policy', 'summary', 'findings']);
    expect($result['policy'])->toHaveKeys(['id', 'code', 'name', 'item_mode', 'version', 'status']);
    expect($result['summary'])->toHaveKeys(['errors', 'warnings', 'info']);
});

// ─── Simulation ──────────────────────────────────────────────────────

it('simulation returns ok status for a happy-path request', function () {
    $f = makeClaimFixture();

    $result = app(ClaimPolicySimulationService::class)->simulate(
        employee: $f['employee'],
        assignmentLine: $f['line'],
        incurredOn: new DateTimeImmutable('2026-06-10'),
        requestedAmount: 100.00,
    );

    expect($result['status'])->toBe('ok');
    expect($result['blocking'])->toBe([]);
    expect($result['explanation'])->toContain('would be accepted');
    expect($result['matched_band']['per_claim_limit'])->not->toBeNull();
});

it('simulation returns blocked status when a cap would be exceeded', function () {
    $f = makeClaimFixture();

    $result = app(ClaimPolicySimulationService::class)->simulate(
        employee: $f['employee'],
        assignmentLine: $f['line'],
        incurredOn: new DateTimeImmutable('2026-06-10'),
        requestedAmount: 10000.00,
    );

    expect($result['status'])->toBe('blocked');
    expect($result['blocking'])->not->toBe([]);
    expect($result['explanation'])->toContain('would be rejected');
});

it('simulation reports cohort ineligibility distinctly from cap exceedance', function () {
    $f = makeClaimFixture();
    $f['assignment']->update(['cohort_predicate' => ['employee_type' => 'agent']]);
    $f['employee']->update(['employee_type' => 'regular']);

    $result = app(ClaimPolicySimulationService::class)->simulate(
        employee: $f['employee']->refresh(),
        assignmentLine: $f['line'],
        incurredOn: new DateTimeImmutable('2026-06-10'),
        requestedAmount: 50.00,
    );

    expect($result['status'])->toBe('blocked');
    $joined = implode(' ', $result['blocking']);
    expect($joined)->toContain('not eligible for assignment');
});

it('simulation does not persist anything', function () {
    $f = makeClaimFixture();
    $beforeRequests = ClaimRequest::query()->count();
    $beforeLines = ClaimLine::query()->count();

    app(ClaimPolicySimulationService::class)->simulate(
        employee: $f['employee'],
        assignmentLine: $f['line'],
        incurredOn: new DateTimeImmutable('2026-06-10'),
        requestedAmount: 100.00,
    );

    expect(ClaimRequest::query()->count())->toBe($beforeRequests);
    expect(ClaimLine::query()->count())->toBe($beforeLines);
});
