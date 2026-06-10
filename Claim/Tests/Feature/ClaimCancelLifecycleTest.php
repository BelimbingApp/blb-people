<?php

use App\Modules\People\Claim\Exceptions\ClaimRequestLifecycleException;
use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Models\ClaimRequestAuditEvent;
use App\Modules\People\Claim\Services\CancelClaimRequestService;
use App\Modules\People\Claim\Services\ClaimNotificationDispatcher;
use App\Modules\People\Settings\Models\PeopleNotificationDeliveryLog;

require_once __DIR__.'/ClaimPolicyEvaluationTest.php';

it('cancels a submitted request with audit + notification', function () {
    $f = makeClaimFixture();
    $request = makeClaimWith($f, ClaimRequest::STATUS_SUBMITTED, requested: 100.00);

    app(CancelClaimRequestService::class)->cancel($request, actorUserId: null, reason: 'Closed by HR');

    $request->refresh();
    expect($request->status)->toBe(ClaimRequest::STATUS_CANCELLED);
    expect($request->cancelled_at)->not->toBeNull();
    expect($request->decision_reason)->toBe('Closed by HR');

    $audit = ClaimRequestAuditEvent::query()->where('claim_request_id', $request->id)->latest('id')->first();
    expect($audit?->from_status)->toBe(ClaimRequest::STATUS_SUBMITTED);
    expect($audit?->to_status)->toBe(ClaimRequest::STATUS_CANCELLED);

    $notif = PeopleNotificationDeliveryLog::query()
        ->where('notifiable_type', ClaimRequest::class)
        ->where('notifiable_id', $request->id)
        ->where('subject', ClaimNotificationDispatcher::EVENT_CANCELLED)
        ->first();
    expect($notif)->not->toBeNull();
});

it('cancels approved requests', function () {
    $f = makeClaimFixture();
    $request = makeClaimWith($f, ClaimRequest::STATUS_APPROVED, requested: 100.00, approved: 100.00);

    app(CancelClaimRequestService::class)->cancel($request);

    $request->refresh();
    expect($request->status)->toBe(ClaimRequest::STATUS_CANCELLED);
});

it('refuses to cancel already-rejected/withdrawn/queued/reimbursed', function () {
    $f = makeClaimFixture();

    foreach ([
        ClaimRequest::STATUS_REJECTED,
        ClaimRequest::STATUS_WITHDRAWN,
        ClaimRequest::STATUS_QUEUED_FOR_PAYROLL,
        ClaimRequest::STATUS_REIMBURSED,
        ClaimRequest::STATUS_CANCELLED,
    ] as $terminal) {
        $request = makeClaimWith($f, $terminal, requested: 100.00);
        expect(fn () => app(CancelClaimRequestService::class)->cancel($request))
            ->toThrow(ClaimRequestLifecycleException::class);
    }
});
