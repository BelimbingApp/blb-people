<?php

namespace App\Modules\People\Claim\Services;

use App\Modules\People\Claim\Models\ClaimRequest;
use Illuminate\Support\Collection;

/**
 * Per-employee reimbursement statement: totals approved, reimbursed, and outstanding amounts
 * grouped by employee within a date window. Finance/HR uses this to reconcile what payroll has
 * paid vs what is still queued.
 *
 * Excludes draft, rejected, withdrawn, and cancelled requests — those never reach Finance.
 */
class ClaimReimbursementStatementBuilder
{
    private const HEADERS = [
        'employee_number',
        'employee_name',
        'currency',
        'request_count',
        'approved_total',
        'reimbursed_total',
        'outstanding_approved',
        'outstanding_queued',
        'last_submitted_at',
        'last_reimbursed_at',
    ];

    private const INCLUDED_STATUSES = [
        ClaimRequest::STATUS_APPROVED,
        ClaimRequest::STATUS_QUEUED_FOR_PAYROLL,
        ClaimRequest::STATUS_REIMBURSED,
        ClaimRequest::STATUS_SETTLED,
    ];

    /**
     * @param  iterable<int, ClaimRequest>  $requests
     * @return array{filename: string, content: string}
     */
    public function csv(iterable $requests): array
    {
        $rows = $this->aggregate($requests);

        return [
            'filename' => 'claim-reimbursement-statement.csv',
            'content' => ClaimCsvWriter::write(self::HEADERS, $rows),
        ];
    }

    /**
     * @param  iterable<int, ClaimRequest>  $requests
     * @return list<array<string, string|null>>
     */
    private function aggregate(iterable $requests): array
    {
        $grouped = [];

        foreach ($requests as $request) {
            if (! in_array($request->status, self::INCLUDED_STATUSES, true)) {
                continue;
            }

            $key = ($request->employee?->getKey() ?? 0).'|'.($request->currency ?? 'MYR');

            $grouped[$key] ??= [
                'employee_number' => $request->employee?->employee_number,
                'employee_name' => $request->employee?->full_name,
                'currency' => $request->currency,
                'request_count' => 0,
                'approved_total' => 0.0,
                'reimbursed_total' => 0.0,
                'outstanding_approved' => 0.0,
                'outstanding_queued' => 0.0,
                'last_submitted_at' => null,
                'last_reimbursed_at' => null,
            ];
            $row = &$grouped[$key];

            $row['request_count']++;
            $row['approved_total'] += (float) $request->approved_amount;
            $row['reimbursed_total'] += (float) $request->reimbursed_amount;

            if ($request->status === ClaimRequest::STATUS_APPROVED) {
                $row['outstanding_approved'] += (float) $request->approved_amount;
            } elseif ($request->status === ClaimRequest::STATUS_QUEUED_FOR_PAYROLL) {
                $row['outstanding_queued'] += (float) $request->approved_amount;
            }

            $row['last_submitted_at'] = $this->maxDate($row['last_submitted_at'], $request->submitted_at?->format('Y-m-d H:i:s'));
            $row['last_reimbursed_at'] = $this->maxDate($row['last_reimbursed_at'], $request->reimbursed_at?->format('Y-m-d H:i:s'));

            unset($row);
        }

        return Collection::make($grouped)
            ->map(fn (array $row): array => [
                'employee_number' => $row['employee_number'],
                'employee_name' => $row['employee_name'],
                'currency' => $row['currency'],
                'request_count' => (string) $row['request_count'],
                'approved_total' => number_format($row['approved_total'], 2, '.', ''),
                'reimbursed_total' => number_format($row['reimbursed_total'], 2, '.', ''),
                'outstanding_approved' => number_format($row['outstanding_approved'], 2, '.', ''),
                'outstanding_queued' => number_format($row['outstanding_queued'], 2, '.', ''),
                'last_submitted_at' => $row['last_submitted_at'],
                'last_reimbursed_at' => $row['last_reimbursed_at'],
            ])
            ->sortBy('employee_number')
            ->values()
            ->all();
    }

    private function maxDate(?string $a, ?string $b): ?string
    {
        return match (true) {
            $a === null => $b,
            $b === null => $a,
            default => $a > $b ? $a : $b,
        };
    }
}
