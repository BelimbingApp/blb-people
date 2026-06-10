<?php

use App\Modules\Core\User\Models\User;
use App\Modules\People\Claim\Exceptions\ClaimRequestLifecycleException;
use App\Modules\People\Claim\Models\ClaimRequestAuditEvent;
use App\Modules\People\Claim\Services\SubmitClaimRequestService;

require_once __DIR__.'/ClaimPolicyEvaluationTest.php';

it('records on-behalf actor and reason on the request and audit event', function () {
    $f = makeClaimFixture();
    $actor = User::factory()->create();

    $request = app(SubmitClaimRequestService::class)->submitLines(
        employee: $f['employee'],
        assignment: $f['assignment'],
        lineSpecs: [[
            'assignment_line' => $f['line'],
            'incurred_on' => new DateTimeImmutable('2026-06-10'),
            'requested_amount' => 100.00,
        ]],
        options: [
            'on_behalf_actor_user_id' => $actor->id,
            'on_behalf_reason' => 'Employee on leave; HR submitting late receipt.',
        ],
    );

    expect($request->on_behalf_actor_user_id)->toBe($actor->id);
    expect($request->on_behalf_reason)->toContain('HR submitting');

    $audit = ClaimRequestAuditEvent::query()->where('claim_request_id', $request->id)->first();
    expect($audit?->actor_user_id)->toBe($actor->id);
});

it('refuses on-behalf submission without a reason', function () {
    $f = makeClaimFixture();
    $actor = User::factory()->create();

    expect(fn () => app(SubmitClaimRequestService::class)->submitLines(
        employee: $f['employee'],
        assignment: $f['assignment'],
        lineSpecs: [[
            'assignment_line' => $f['line'],
            'incurred_on' => new DateTimeImmutable('2026-06-10'),
            'requested_amount' => 100.00,
        ]],
        options: ['on_behalf_actor_user_id' => $actor->id],
    ))->toThrow(ClaimRequestLifecycleException::class, 'require a reason');
});

it('refuses on-behalf submission when claim type forbids it', function () {
    $f = makeClaimFixture();
    $f['type']->update(['allow_on_behalf_submission' => false]);
    $actor = User::factory()->create();

    expect(fn () => app(SubmitClaimRequestService::class)->submitLines(
        employee: $f['employee'],
        assignment: $f['assignment'],
        lineSpecs: [[
            'assignment_line' => $f['line'],
            'incurred_on' => new DateTimeImmutable('2026-06-10'),
            'requested_amount' => 100.00,
        ]],
        options: [
            'on_behalf_actor_user_id' => $actor->id,
            'on_behalf_reason' => 'covering for staff',
        ],
    ))->toThrow(ClaimRequestLifecycleException::class, 'on-behalf submission');
});
