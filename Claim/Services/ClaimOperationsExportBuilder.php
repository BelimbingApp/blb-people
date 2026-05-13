<?php

namespace App\Modules\People\Claim\Services;

use App\Modules\People\Claim\Models\ClaimRequest;

class ClaimOperationsExportBuilder
{
    private const HEADERS = [
        'reference_number',
        'employee_number',
        'employee_name',
        'status',
        'submitted_at',
        'currency',
        'requested_amount',
        'approved_amount',
        'line_count',
        'claim_types',
        'duplicate_risk_count',
        'payroll_state',
        'payroll_eligible',
        'payroll_queued',
        'payroll_pending',
        'decision_reason',
    ];

    /**
     * @param  iterable<int, ClaimRequest>  $requests
     * @return array{filename: string, content: string}
     */
    public function csv(iterable $requests): array
    {
        $rows = [];

        foreach ($requests as $request) {
            $handoff = is_array($request->metadata) ? ($request->metadata['payroll_handoff'] ?? null) : null;
            $duplicateRisks = is_array($request->metadata) ? ($request->metadata['duplicate_risks'] ?? []) : [];
            $eligibleLines = $request->lines->filter(fn ($line): bool => (bool) $line->type?->payroll_eligible && $line->payroll_pay_item_code !== null);

            $rows[] = [
                'reference_number' => $request->reference_number,
                'employee_number' => $request->employee?->employee_number,
                'employee_name' => $request->employee?->full_name,
                'status' => $request->status,
                'submitted_at' => $request->submitted_at?->format('Y-m-d H:i:s'),
                'currency' => $request->currency,
                'requested_amount' => (string) $request->requested_amount,
                'approved_amount' => (string) $request->approved_amount,
                'line_count' => (string) $request->lines->count(),
                'claim_types' => $request->lines->map(fn ($line) => $line->type?->code)->filter()->unique()->implode('; '),
                'duplicate_risk_count' => (string) count($duplicateRisks),
                'payroll_state' => $this->payrollState($request),
                'payroll_eligible' => (string) (is_array($handoff) ? ($handoff['eligible'] ?? 0) : $eligibleLines->count()),
                'payroll_queued' => (string) (is_array($handoff) ? ($handoff['queued'] ?? 0) : 0),
                'payroll_pending' => (string) (is_array($handoff) ? ($handoff['pending'] ?? 0) : 0),
                'decision_reason' => $request->decision_reason,
            ];
        }

        return [
            'filename' => 'claim-operations.csv',
            'content' => $this->csvContent(self::HEADERS, $rows),
        ];
    }

    private function payrollState(ClaimRequest $request): string
    {
        $eligibleLines = $request->lines->filter(fn ($line): bool => (bool) $line->type?->payroll_eligible && $line->payroll_pay_item_code !== null);

        if ($eligibleLines->isEmpty()) {
            return 'not_eligible';
        }

        $handoff = is_array($request->metadata) ? ($request->metadata['payroll_handoff'] ?? null) : null;
        if (! is_array($handoff)) {
            return 'eligible';
        }

        if (($handoff['pending'] ?? 0) > 0) {
            return 'pending';
        }

        return ($handoff['queued'] ?? 0) > 0 ? 'queued' : 'eligible';
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $rows
     */
    private function csvContent(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                static fn (string $header): string => (string) ($row[$header] ?? ''),
                $headers,
            ));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }
}
