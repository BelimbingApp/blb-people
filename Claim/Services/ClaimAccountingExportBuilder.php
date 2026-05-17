<?php

namespace App\Modules\People\Claim\Services;

use App\Modules\People\Claim\Models\ClaimLine;
use App\Modules\People\Claim\Models\ClaimRequest;

/**
 * Builds a finance-oriented CSV of claim lines (one row per line) for accounting export.
 *
 * Distinct from {@see ClaimOperationsExportBuilder}, which is request-level. This export carries
 * the GL/account/pay-item snapshot persisted on each line at submission/approval time, so
 * Finance can post journals without back-reading current type/policy state.
 */
class ClaimAccountingExportBuilder
{
    private const DATE_TIME_FORMAT = 'Y-m-d H:i:s';

    private const HEADERS = [
        'reference_number',
        'line_id',
        'request_status',
        'employee_number',
        'employee_name',
        'department',
        'claim_type_code',
        'claim_type_name',
        'incurred_on',
        'currency',
        'requested_amount',
        'approved_amount',
        'reimbursed_amount',
        'payroll_pay_item_code',
        'debit_account_code',
        'credit_account_code',
        'taxability_hint',
        'receipt_state',
        'settlement_state',
        'provider_name',
        'receipt_number',
        'submitted_at',
        'approved_at',
        'queued_for_payroll_at',
        'reimbursed_at',
    ];

    /**
     * @param  iterable<int, ClaimRequest>  $requests
     * @return array{filename: string, content: string}
     */
    public function csv(iterable $requests): array
    {
        $rows = [];

        foreach ($requests as $request) {
            foreach ($request->lines as $line) {
                $rows[] = $this->rowFor($request, $line);
            }
        }

        return [
            'filename' => 'claim-accounting.csv',
            'content' => ClaimCsvWriter::write(self::HEADERS, $rows),
        ];
    }

    /** @return array<string, string|null> */
    private function rowFor(ClaimRequest $request, ClaimLine $line): array
    {
        $accountingSnapshot = is_array($line->accounting_snapshot) ? $line->accounting_snapshot : [];

        return [
            'reference_number' => $request->reference_number,
            'line_id' => (string) $line->getKey(),
            'request_status' => $request->status,
            'employee_number' => $request->employee?->employee_number,
            'employee_name' => $request->employee?->full_name,
            'department' => $request->employee?->department?->name,
            'claim_type_code' => $line->type?->code,
            'claim_type_name' => $line->type?->name,
            'incurred_on' => $line->incurred_on?->format('Y-m-d'),
            'currency' => $line->currency,
            'requested_amount' => (string) $line->requested_amount,
            'approved_amount' => (string) $line->approved_amount,
            'reimbursed_amount' => (string) $line->reimbursed_amount,
            'payroll_pay_item_code' => $line->payroll_pay_item_code ?? ($accountingSnapshot['payroll_pay_item_code'] ?? null),
            'debit_account_code' => $line->debit_account_code ?? ($accountingSnapshot['debit_account_code'] ?? null),
            'credit_account_code' => $line->credit_account_code ?? ($accountingSnapshot['credit_account_code'] ?? null),
            'taxability_hint' => $line->type?->taxability_hint,
            'receipt_state' => ((int) $line->attachment_count) > 0 ? 'has_receipt' : 'no_receipt',
            'settlement_state' => $this->settlementState($request),
            'provider_name' => $line->provider_name,
            'receipt_number' => $line->receipt_number,
            'submitted_at' => $request->submitted_at?->format(self::DATE_TIME_FORMAT),
            'approved_at' => $request->approved_at?->format(self::DATE_TIME_FORMAT),
            'queued_for_payroll_at' => $request->queued_for_payroll_at?->format(self::DATE_TIME_FORMAT),
            'reimbursed_at' => $request->reimbursed_at?->format(self::DATE_TIME_FORMAT),
        ];
    }

    private function settlementState(ClaimRequest $request): string
    {
        return match ($request->status) {
            ClaimRequest::STATUS_APPROVED => 'approved',
            ClaimRequest::STATUS_QUEUED_FOR_PAYROLL => 'queued',
            ClaimRequest::STATUS_REIMBURSED => 'reimbursed',
            ClaimRequest::STATUS_SETTLED => 'settled',
            default => $request->status,
        };
    }
}
