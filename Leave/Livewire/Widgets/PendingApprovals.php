<?php

namespace App\Modules\People\Leave\Livewire\Widgets;

use App\Base\Dashboard\Widget;
use App\Base\DateTime\Contracts\DateTimeDisplayService;
use App\Modules\Core\Company\Models\Company;
use App\Modules\People\Leave\Models\LeaveRequest;
use Illuminate\Contracts\View\View;

/**
 * Dashboard widget: leave requests awaiting approval in the user's company.
 *
 * Visibility is gated by `people.leave.approve` in Config/dashboard.php.
 */
class PendingApprovals extends Widget
{
    protected function content(DateTimeDisplayService $dates): View
    {
        $pending = LeaveRequest::query()
            ->where('company_id', $this->companyId())
            ->where('status', LeaveRequest::STATUS_SUBMITTED)
            ->selectRaw('count(*) as total, min(starts_on) as earliest_start')
            ->first();

        $earliestStart = $pending?->earliest_start;

        return view('people-leave::livewire.people.leave.widgets.pending-approvals', [
            'pendingCount' => (int) ($pending->total ?? 0),
            'earliestStart' => $earliestStart !== null ? $dates->formatDate($earliestStart) : null,
        ]);
    }

    private function companyId(): int
    {
        return auth()->user()?->company_id ?? Company::LICENSEE_ID;
    }
}
