<?php

use App\Modules\People\Claim\Exceptions\ClaimRequestLifecycleException;
use App\Modules\People\Claim\Models\ClaimEntitlementUsageEntry;
use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Models\ClaimRequestAuditEvent;
use App\Modules\People\Claim\Services\ClaimNotificationDispatcher;
use App\Modules\People\Claim\Services\ReimburseClaimRequestService;
use App\Modules\People\Settings\Models\PeopleNotificationDeliveryLog;

require_once __DIR__.'/ClaimPolicyEvaluationTest.php';

it('reimburses an approved request and copies approved → reimbursed', function () {
    $f = makeClaimFixture();
    $request = makeClaimWith($f, ClaimRequest::STATUS_APPROVED, requested: 100.00, approved: 80.00);

    app(ReimburseClaimRequestService::class)->reimburse($request, actorUserId: null, reason: 'Paid via May payroll');

    $request->refresh();
    expect($request->status)->toBe(ClaimRequest::STATUS_REIMBURSED);
    expect((float) $request->reimbursed_amount)->toBe(80.00);
    expect($request->reimbursed_at)->not->toBeNull();

    $line = $request->lines()->first();
    expect((float) $line->reimbursed_amount)->toBe(80.00);

    $audit = ClaimRequestAuditEvent::query()->where('claim_request_id', $request->id)->latest('id')->first();
    expect($audit?->to_status)->toBe(ClaimRequest::STATUS_REIMBURSED);

    $usage = ClaimEntitlementUsageEntry::query()
        ->where('claim_line_id', $line->id)
        ->where('entry_type', ClaimEntitlementUsageEntry::ENTRY_REIMBURSED)
        ->first();
    expect($usage)->not->toBeNull();
    expect((float) $usage->amount)->toBe(80.00);

    $notif = PeopleNotificationDeliveryLog::query()
        ->where('notifiable_type', ClaimRequest::class)
        ->where('notifiable_id', $request->id)
        ->where('subject', ClaimNotificationDispatcher::EVENT_REIMBURSED)
        ->first();
    expect($notif)->not->toBeNull();
});

it('reimburses a queued_for_payroll request', function () {
    $f = makeClaimFixture();
    $request = makeClaimWith($f, ClaimRequest::STATUS_QUEUED_FOR_PAYROLL, requested: 100.00, approved: 100.00);

    app(ReimburseClaimRequestService::class)->reimburse($request);

    $request->refresh();
    expect($request->status)->toBe(ClaimRequest::STATUS_REIMBURSED);
});

it('refuses to reimburse requests in non-payable states', function () {
    $f = makeClaimFixture();

    foreach ([
        ClaimRequest::STATUS_DRAFT,
        ClaimRequest::STATUS_SUBMITTED,
        ClaimRequest::STATUS_REJECTED,
        ClaimRequest::STATUS_WITHDRAWN,
        ClaimRequest::STATUS_CANCELLED,
        ClaimRequest::STATUS_REIMBURSED,
    ] as $status) {
        $request = makeClaimWith($f, $status, requested: 100.00, approved: 100.00);
        expect(fn () => app(ReimburseClaimRequestService::class)->reimburse($request))
            ->toThrow(ClaimRequestLifecycleException::class);
    }
});
