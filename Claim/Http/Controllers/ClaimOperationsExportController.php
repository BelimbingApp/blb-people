<?php

namespace App\Modules\People\Claim\Http\Controllers;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Services\ClaimOperationsExportBuilder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClaimOperationsExportController
{
    public function __invoke(Request $request, ClaimOperationsExportBuilder $exportBuilder): StreamedResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $claims = ClaimRequest::query()
            ->where('company_id', $user->company_id ?? Company::LICENSEE_ID)
            ->with(['employee', 'lines.type'])
            ->when($request->query('status'), fn ($query, string $status) => $query->where('status', $status))
            ->latest('submitted_at')
            ->latest('id')
            ->limit(500)
            ->get();

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $claims = $claims->filter(fn (ClaimRequest $claim): bool => str_contains(strtolower((string) $claim->reference_number), strtolower($search))
                || str_contains(strtolower((string) $claim->employee?->employee_number), strtolower($search))
                || str_contains(strtolower((string) $claim->employee?->full_name), strtolower($search))
                || $claim->lines->contains(fn ($line): bool => str_contains(strtolower((string) $line->receipt_number), strtolower($search))
                    || str_contains(strtolower((string) $line->provider_name), strtolower($search))));
        }

        $risk = (string) $request->query('risk', '');
        if ($risk === 'duplicate') {
            $claims = $claims->filter(fn (ClaimRequest $claim): bool => ($claim->metadata['duplicate_risks'] ?? []) !== []);
        } elseif ($risk === 'clear') {
            $claims = $claims->filter(fn (ClaimRequest $claim): bool => ($claim->metadata['duplicate_risks'] ?? []) === []);
        }

        $payroll = (string) $request->query('payroll', '');
        if ($payroll !== '') {
            $claims = $claims->filter(fn (ClaimRequest $claim): bool => $this->payrollState($claim) === $payroll);
        }

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

    private function payrollState(ClaimRequest $claim): string
    {
        $eligibleLines = $claim->lines->filter(fn ($line): bool => (bool) $line->type?->payroll_eligible && $line->payroll_pay_item_code !== null);
        if ($eligibleLines->isEmpty()) {
            return 'not_eligible';
        }

        $handoff = $claim->metadata['payroll_handoff'] ?? null;
        if (! is_array($handoff)) {
            return 'eligible';
        }

        if (($handoff['pending'] ?? 0) > 0) {
            return 'pending';
        }

        return ($handoff['queued'] ?? 0) > 0 ? 'queued' : 'eligible';
    }
}
