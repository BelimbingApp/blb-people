<?php

namespace App\Modules\People\Claim\Http\Controllers;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Services\ClaimAccountingExportBuilder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finance/accounting CSV export, one row per claim line.
 *
 * Defaults to the four settled-side statuses (approved, queued_for_payroll, reimbursed, settled)
 * so the export reflects amounts the GL should already see or expect. Operations exports
 * (request-level, all statuses) remain at people.claim.operations.export.csv.
 */
class ClaimAccountingExportController
{
    private const DEFAULT_STATUSES = [
        ClaimRequest::STATUS_APPROVED,
        ClaimRequest::STATUS_QUEUED_FOR_PAYROLL,
        ClaimRequest::STATUS_REIMBURSED,
        ClaimRequest::STATUS_SETTLED,
    ];

    public function __invoke(Request $request, ClaimAccountingExportBuilder $exportBuilder): StreamedResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $statusParam = (string) $request->query('status', '');
        $statuses = $statusParam === ''
            ? self::DEFAULT_STATUSES
            : array_filter(array_map('trim', explode(',', $statusParam)));

        $claims = ClaimRequest::query()
            ->where('company_id', $user->company_id ?? Company::LICENSEE_ID)
            ->whereIn('status', $statuses)
            ->with(['employee.department', 'lines.type'])
            ->when(
                $request->query('from'),
                fn ($q, string $from) => $q->whereDate('submitted_at', '>=', $from),
            )
            ->when(
                $request->query('to'),
                fn ($q, string $to) => $q->whereDate('submitted_at', '<=', $to),
            )
            ->latest('submitted_at')
            ->latest('id')
            ->limit(2000)
            ->get();

        $export = $exportBuilder->csv($claims);

        return new StreamedResponse(
            function () use ($export): void {
                echo $export['content'];
            },
            200,
            [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
                'Cache-Control' => 'no-store, max-age=0',
            ],
        );
    }
}
