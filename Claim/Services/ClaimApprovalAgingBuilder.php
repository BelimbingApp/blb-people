<?php

namespace App\Modules\People\Claim\Services;

use App\Modules\People\Claim\Models\ClaimRequest;
use Carbon\CarbonImmutable;

/**
 * Approval aging report: for each request still waiting on approval (submitted, resubmitted,
 * needs_more_info), how long has it been in the queue and which aging bucket does it fall in?
 *
 * Buckets follow standard A/R aging convention: 0-3d, 4-7d, 8-14d, 15-30d, 30d+. Lets approvers
 * and HR see which claims are stalling.
 */
class ClaimApprovalAgingBuilder
{
    private const HEADERS = [
        'reference_number',
        'employee_number',
        'employee_name',
        'status',
        'submitted_at',
        'days_in_queue',
        'aging_bucket',
        'requested_amount',
        'currency',
        'approval_profile_key',
        'claim_types',
    ];

    /** @var list<string> */
    private const PENDING_STATUSES = [
        ClaimRequest::STATUS_SUBMITTED,
        ClaimRequest::STATUS_RESUBMITTED,
        ClaimRequest::STATUS_NEEDS_MORE_INFO,
    ];

    /**
     * @param  iterable<int, ClaimRequest>  $requests
     * @return array{filename: string, content: string}
     */
    public function csv(iterable $requests, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();
        $rows = [];

        foreach ($requests as $request) {
            if (! in_array($request->status, self::PENDING_STATUSES, true)) {
                continue;
            }

            $submittedAt = $request->submitted_at;
            $days = $submittedAt === null
                ? 0
                : (int) CarbonImmutable::instance($submittedAt)->diffInDays($asOf);

            $rows[] = [
                'reference_number' => $request->reference_number,
                'employee_number' => $request->employee?->employee_number,
                'employee_name' => $request->employee?->full_name,
                'status' => $request->status,
                'submitted_at' => $submittedAt?->format('Y-m-d H:i:s'),
                'days_in_queue' => (string) $days,
                'aging_bucket' => $this->bucket($days),
                'requested_amount' => (string) $request->requested_amount,
                'currency' => $request->currency,
                'approval_profile_key' => $request->approval_profile_key ?? '',
                'claim_types' => $request->lines->map(fn ($line) => $line->type?->code)->filter()->unique()->implode('; '),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => (int) $b['days_in_queue'] - (int) $a['days_in_queue']);

        return [
            'filename' => 'claim-approval-aging.csv',
            'content' => $this->csvContent(self::HEADERS, $rows),
        ];
    }

    private function bucket(int $days): string
    {
        return match (true) {
            $days <= 3 => '0-3d',
            $days <= 7 => '4-7d',
            $days <= 14 => '8-14d',
            $days <= 30 => '15-30d',
            default => '30d+',
        };
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, string|null>>  $rows
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
