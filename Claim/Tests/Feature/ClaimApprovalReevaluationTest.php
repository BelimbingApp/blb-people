<?php

use App\Modules\People\Claim\Exceptions\ClaimRequestLifecycleException;
use App\Modules\People\Claim\Models\ClaimLine;
use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Services\ApproveClaimRequestService;

require_once __DIR__.'/ClaimPolicyEvaluationTest.php';

it('blocks approval when later submissions would push the cap over', function () {
    // Per-month cap 500. We submit one for 400 (becomes pending), then another for 200,
    // then a third request that lands at 100 — total pending = 700. The first 400 was
    // already approved (consumes 400 of cap). Now approving the 200 should exceed 500.
    $f = makeClaimFixture();

    makeClaimWith($f, ClaimRequest::STATUS_APPROVED, requested: 400.00, approved: 400.00, incurred: '2026-06-05');

    $pending = makeClaimWith($f, ClaimRequest::STATUS_SUBMITTED, requested: 200.00, incurred: '2026-06-15');

    expect(fn () => app(ApproveClaimRequestService::class)->approve($pending))
        ->toThrow(ClaimRequestLifecycleException::class, 'Approval blocked');

    // The pending request should still be in submitted (the transaction rolled back)
    $pending->refresh();
    expect($pending->status)->toBe(ClaimRequest::STATUS_SUBMITTED);
});

it('approves successfully when current cap state permits it', function () {
    $f = makeClaimFixture();
    makeClaimWith($f, ClaimRequest::STATUS_APPROVED, requested: 200.00, approved: 200.00, incurred: '2026-06-05');

    $pending = makeClaimWith($f, ClaimRequest::STATUS_SUBMITTED, requested: 250.00, incurred: '2026-06-15');

    app(ApproveClaimRequestService::class)->approve($pending);

    $pending->refresh();
    expect($pending->status)->toBe(ClaimRequest::STATUS_APPROVED);
    /** @var ClaimLine $line */
    $line = $pending->lines()->first();
    expect((float) $line->approved_amount)->toBe(250.00);
});

it('does not double-count the request being approved against its own cap', function () {
    // Only one prior approved claim of 200. Per-month cap is 500. Approve a 300 request.
    // If approval re-eval double-counted itself: 200 + 300 (approving) + 300 (its own pending) = 800 > 500 → false-block
    $f = makeClaimFixture();
    makeClaimWith($f, ClaimRequest::STATUS_APPROVED, requested: 200.00, approved: 200.00, incurred: '2026-06-05');

    $pending = makeClaimWith($f, ClaimRequest::STATUS_SUBMITTED, requested: 300.00, incurred: '2026-06-15');

    app(ApproveClaimRequestService::class)->approve($pending);

    $pending->refresh();
    expect($pending->status)->toBe(ClaimRequest::STATUS_APPROVED);
});
