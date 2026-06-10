<?php

use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Services\ClaimApprovalAgingBuilder;
use Carbon\CarbonImmutable;

require_once __DIR__.'/ClaimPolicyEvaluationTest.php';

it('buckets pending requests by days in queue and sorts oldest first', function () {
    $f = makeClaimFixture();

    $fresh = makeClaimWith($f, ClaimRequest::STATUS_SUBMITTED, requested: 50);
    $fresh->update(['submitted_at' => CarbonImmutable::now()->subDays(2)]);

    $week = makeClaimWith($f, ClaimRequest::STATUS_SUBMITTED, requested: 50);
    $week->update(['submitted_at' => CarbonImmutable::now()->subDays(5)]);

    $stale = makeClaimWith($f, ClaimRequest::STATUS_NEEDS_MORE_INFO, requested: 50);
    $stale->update(['submitted_at' => CarbonImmutable::now()->subDays(20)]);

    // Approved should NOT appear
    makeClaimWith($f, ClaimRequest::STATUS_APPROVED, requested: 50, approved: 50);

    $claims = ClaimRequest::query()->with(['employee', 'lines.type'])->get();
    $export = app(ClaimApprovalAgingBuilder::class)->csv($claims);

    $lines = array_filter(explode("\n", $export['content']));
    expect($lines)->toHaveCount(4); // header + 3 pending

    $header = str_getcsv($lines[0]);
    $first = array_combine($header, str_getcsv($lines[1]));
    $second = array_combine($header, str_getcsv($lines[2]));
    $third = array_combine($header, str_getcsv($lines[3]));

    // Oldest first
    expect($first['aging_bucket'])->toBe('15-30d');
    expect($second['aging_bucket'])->toBe('4-7d');
    expect($third['aging_bucket'])->toBe('0-3d');
});

it('excludes terminal states from aging report', function () {
    $f = makeClaimFixture();
    makeClaimWith($f, ClaimRequest::STATUS_APPROVED, requested: 50, approved: 50);
    makeClaimWith($f, ClaimRequest::STATUS_REJECTED, requested: 50);
    makeClaimWith($f, ClaimRequest::STATUS_CANCELLED, requested: 50);

    $claims = ClaimRequest::query()->with(['employee', 'lines.type'])->get();
    $export = app(ClaimApprovalAgingBuilder::class)->csv($claims);

    $lines = array_filter(explode("\n", $export['content']));
    expect($lines)->toHaveCount(1); // header only
});
