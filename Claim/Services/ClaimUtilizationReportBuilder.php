<?php

namespace App\Modules\People\Claim\Services;

use App\Modules\People\Claim\Models\ClaimEntitlementUsageEntry;
use App\Modules\People\Claim\Models\ClaimLine;
use App\Modules\People\Claim\Models\ClaimRequest;
use Illuminate\Support\Collection;

/**
 * Entitlement utilization per (employee, claim_type, year). Shows approved and reimbursed
 * amounts already consumed plus the encumbered (pending) total still in flight, so HR can spot
 * employees nearing their annual cap before they exceed it.
 *
 * Sources approved/reimbursed totals from {@see ClaimEntitlementUsageEntry} (the ledger of
 * authoritative consumption events) and the pending encumbrance from {@see ClaimLine} on
 * submitted/needs-more-info/resubmitted requests.
 */
class ClaimUtilizationReportBuilder
{
    private const HEADERS = [
        'employee_number',
        'employee_name',
        'claim_type_code',
        'claim_year',
        'currency',
        'approved_total',
        'reimbursed_total',
        'encumbered_pending',
        'usage_event_count',
    ];

    /**
     * @return array{filename: string, content: string}
     */
    public function csv(int $companyId, ?int $year = null): array
    {
        $rows = $this->aggregate($companyId, $year);

        return [
            'filename' => 'claim-utilization.csv',
            'content' => ClaimCsvWriter::write(self::HEADERS, $rows),
        ];
    }

    /** @return list<array<string, string|null>> */
    private function aggregate(int $companyId, ?int $year): array
    {
        $entryQuery = ClaimEntitlementUsageEntry::query()
            ->where('company_id', $companyId)
            ->with(['type', 'line'])
            ->whereIn('entry_type', [
                ClaimEntitlementUsageEntry::ENTRY_APPROVED,
                ClaimEntitlementUsageEntry::ENTRY_REIMBURSED,
            ])
            ->when($year !== null, fn ($q) => $q->where('claim_year', $year));

        $grouped = [];

        foreach ($entryQuery->get() as $entry) {
            $key = $entry->employee_id.'|'.$entry->claim_type_id.'|'.$entry->claim_year.'|'.($entry->currency ?? 'MYR');

            $grouped[$key] ??= [
                'employee_id' => $entry->employee_id,
                'employee_number' => $entry->employee?->employee_number,
                'employee_name' => $entry->employee?->full_name,
                'claim_type_code' => $entry->type?->code,
                'claim_year' => (string) $entry->claim_year,
                'currency' => $entry->currency,
                'approved_total' => 0.0,
                'reimbursed_total' => 0.0,
                'encumbered_pending' => 0.0,
                'usage_event_count' => 0,
            ];

            $row = &$grouped[$key];
            $row['usage_event_count']++;
            if ($entry->entry_type === ClaimEntitlementUsageEntry::ENTRY_APPROVED) {
                $row['approved_total'] += (float) $entry->amount;
            } elseif ($entry->entry_type === ClaimEntitlementUsageEntry::ENTRY_REIMBURSED) {
                $row['reimbursed_total'] += (float) $entry->amount;
            }
            unset($row);
        }

        $pendingLines = ClaimLine::query()
            ->whereHas('request', fn ($q) => $q
                ->where('company_id', $companyId)
                ->whereIn('status', [
                    ClaimRequest::STATUS_SUBMITTED,
                    ClaimRequest::STATUS_NEEDS_MORE_INFO,
                    ClaimRequest::STATUS_RESUBMITTED,
                ]))
            ->with(['type', 'request.employee'])
            ->when(
                $year !== null,
                fn ($q) => $q->whereYear('incurred_on', $year),
            )
            ->get();

        foreach ($pendingLines as $line) {
            $request = $line->request;
            if ($request === null) {
                continue;
            }
            $claimYear = $line->incurred_on instanceof \DateTimeInterface
                ? (int) $line->incurred_on->format('Y')
                : (int) date('Y', strtotime((string) $line->incurred_on));
            $key = $request->employee_id.'|'.$line->claim_type_id.'|'.$claimYear.'|'.($line->currency ?? 'MYR');

            $grouped[$key] ??= [
                'employee_id' => $request->employee_id,
                'employee_number' => $request->employee?->employee_number,
                'employee_name' => $request->employee?->full_name,
                'claim_type_code' => $line->type?->code,
                'claim_year' => (string) $claimYear,
                'currency' => $line->currency,
                'approved_total' => 0.0,
                'reimbursed_total' => 0.0,
                'encumbered_pending' => 0.0,
                'usage_event_count' => 0,
            ];
            $grouped[$key]['encumbered_pending'] += (float) $line->requested_amount;
        }

        return Collection::make($grouped)
            ->map(fn (array $row): array => [
                'employee_number' => $row['employee_number'],
                'employee_name' => $row['employee_name'],
                'claim_type_code' => $row['claim_type_code'],
                'claim_year' => $row['claim_year'],
                'currency' => $row['currency'],
                'approved_total' => number_format($row['approved_total'], 2, '.', ''),
                'reimbursed_total' => number_format($row['reimbursed_total'], 2, '.', ''),
                'encumbered_pending' => number_format($row['encumbered_pending'], 2, '.', ''),
                'usage_event_count' => (string) $row['usage_event_count'],
            ])
            ->sortBy(['employee_number', 'claim_type_code', 'claim_year'])
            ->values()
            ->all();
    }
}
