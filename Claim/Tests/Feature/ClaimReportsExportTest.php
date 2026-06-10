<?php

use App\Modules\Core\Company\Models\Company;
use App\Modules\People\Claim\Models\ClaimEntitlementUsageEntry;
use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Services\ClaimReimbursementStatementBuilder;
use App\Modules\People\Claim\Services\ClaimUtilizationReportBuilder;

require_once __DIR__.'/ClaimPolicyEvaluationTest.php';

it('aggregates per-employee approved/reimbursed/outstanding totals', function () {
    $f = makeClaimFixture();

    // Approved 200 + queued 100 + reimbursed 50 → approved_total 350, reimbursed 50,
    // outstanding_approved 200, outstanding_queued 100
    makeClaimWith($f, ClaimRequest::STATUS_APPROVED, requested: 200, approved: 200, incurred: '2026-06-01');
    makeClaimWith($f, ClaimRequest::STATUS_QUEUED_FOR_PAYROLL, requested: 100, approved: 100, incurred: '2026-06-02');
    $reimbursed = makeClaimWith($f, ClaimRequest::STATUS_REIMBURSED, requested: 50, approved: 50, incurred: '2026-06-03');
    $reimbursed->update(['reimbursed_amount' => 50, 'reimbursed_at' => now()]);

    // Rejected should be ignored
    makeClaimWith($f, ClaimRequest::STATUS_REJECTED, requested: 999, incurred: '2026-06-04');

    $claims = ClaimRequest::query()->with('employee')->get();
    $export = app(ClaimReimbursementStatementBuilder::class)->csv($claims);

    $lines = array_filter(explode("\n", $export['content']));
    expect($lines)->toHaveCount(2); // header + 1 employee

    $row = array_combine(str_getcsv($lines[0]), str_getcsv($lines[1]));
    expect($row['request_count'])->toBe('3');
    expect($row['approved_total'])->toBe('350.00');
    expect($row['reimbursed_total'])->toBe('50.00');
    expect($row['outstanding_approved'])->toBe('200.00');
    expect($row['outstanding_queued'])->toBe('100.00');
});

it('utilization aggregates approved + reimbursed usage entries and pending lines', function () {
    $f = makeClaimFixture();
    $company = Company::query()->findOrFail(Company::LICENSEE_ID);

    // Pending submitted line → encumbrance only
    makeClaimWith($f, ClaimRequest::STATUS_SUBMITTED, requested: 80, incurred: '2026-06-10');

    // Approved + reimbursed → both usage entries
    $approved = makeClaimWith($f, ClaimRequest::STATUS_APPROVED, requested: 200, approved: 150, incurred: '2026-06-05');
    $line = $approved->lines()->first();
    ClaimEntitlementUsageEntry::query()->create([
        'company_id' => $company->id,
        'employee_id' => $f['employee']->id,
        'claim_type_id' => $f['type']->id,
        'claim_policy_id' => $f['policy']->id,
        'claim_line_id' => $line->id,
        'claim_year' => 2026,
        'entry_type' => ClaimEntitlementUsageEntry::ENTRY_APPROVED,
        'amount' => 150.00,
        'currency' => 'MYR',
        'source_type' => 'claim_request',
        'source_id' => $approved->id,
        'occurred_on' => '2026-06-06',
    ]);
    ClaimEntitlementUsageEntry::query()->create([
        'company_id' => $company->id,
        'employee_id' => $f['employee']->id,
        'claim_type_id' => $f['type']->id,
        'claim_policy_id' => $f['policy']->id,
        'claim_line_id' => $line->id,
        'claim_year' => 2026,
        'entry_type' => ClaimEntitlementUsageEntry::ENTRY_REIMBURSED,
        'amount' => 150.00,
        'currency' => 'MYR',
        'source_type' => 'claim_request',
        'source_id' => $approved->id,
        'occurred_on' => '2026-06-07',
    ]);

    $export = app(ClaimUtilizationReportBuilder::class)->csv($company->id, 2026);
    $lines = array_filter(explode("\n", $export['content']));
    expect($lines)->toHaveCount(2);

    $row = array_combine(str_getcsv($lines[0]), str_getcsv($lines[1]));
    expect($row['claim_year'])->toBe('2026');
    expect($row['approved_total'])->toBe('150.00');
    expect($row['reimbursed_total'])->toBe('150.00');
    expect($row['encumbered_pending'])->toBe('80.00');
});
