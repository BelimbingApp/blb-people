<?php

namespace App\Modules\People\Claim\Livewire\Concerns;

use App\Modules\People\Claim\Models\ClaimRequest;

trait HasPayrollOperationsStatus
{
    /** @return array<string, string> */
    private function payrollOperationsOptions(): array
    {
        return [
            'eligible' => __('Payroll eligible'),
            'pending' => __('Pending handoff'),
            'queued' => __('Queued'),
            'not_eligible' => __('Not payroll eligible'),
        ];
    }

    private function payrollOperationsState(ClaimRequest $request): string
    {
        $eligibleLines = $request->lines->filter(fn ($line): bool => (bool) $line->type?->payroll_eligible && $line->payroll_pay_item_code !== null);

        if ($eligibleLines->isEmpty()) {
            return 'not_eligible';
        }

        $handoff = $request->metadata['payroll_handoff'] ?? null;
        if (! is_array($handoff)) {
            return 'eligible';
        }

        if (($handoff['pending'] ?? 0) > 0) {
            return 'pending';
        }

        if (($handoff['queued'] ?? 0) > 0) {
            return 'queued';
        }

        return 'eligible';
    }
}
