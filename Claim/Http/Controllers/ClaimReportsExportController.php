<?php

namespace App\Modules\People\Claim\Http\Controllers;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Services\ClaimApprovalAgingBuilder;
use App\Modules\People\Claim\Services\ClaimReimbursementStatementBuilder;
use App\Modules\People\Claim\Services\ClaimUtilizationReportBuilder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClaimReportsExportController
{
    public function reimbursementStatement(Request $request, ClaimReimbursementStatementBuilder $builder): StreamedResponse
    {
        $companyId = $this->resolveCompanyId($request);

        $claims = ClaimRequest::query()
            ->where('company_id', $companyId)
            ->with(['employee'])
            ->when($request->query('from'), fn ($q, string $from) => $q->whereDate('submitted_at', '>=', $from))
            ->when($request->query('to'), fn ($q, string $to) => $q->whereDate('submitted_at', '<=', $to))
            ->limit(5000)
            ->get();

        return $this->streamCsv($builder->csv($claims));
    }

    public function utilization(Request $request, ClaimUtilizationReportBuilder $builder): StreamedResponse
    {
        $companyId = $this->resolveCompanyId($request);
        $year = $request->query('year') !== null ? (int) $request->query('year') : null;

        return $this->streamCsv($builder->csv($companyId, $year));
    }

    public function approvalAging(Request $request, ClaimApprovalAgingBuilder $builder): StreamedResponse
    {
        $companyId = $this->resolveCompanyId($request);

        $claims = ClaimRequest::query()
            ->where('company_id', $companyId)
            ->whereIn('status', [
                ClaimRequest::STATUS_SUBMITTED,
                ClaimRequest::STATUS_RESUBMITTED,
                ClaimRequest::STATUS_NEEDS_MORE_INFO,
            ])
            ->with(['employee', 'lines.type'])
            ->limit(5000)
            ->get();

        return $this->streamCsv($builder->csv($claims));
    }

    private function resolveCompanyId(Request $request): int
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return (int) ($user->company_id ?? Company::LICENSEE_ID);
    }

    /** @param  array{filename: string, content: string}  $export */
    private function streamCsv(array $export): StreamedResponse
    {
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
