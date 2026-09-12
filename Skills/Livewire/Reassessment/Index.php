<?php

namespace App\Domains\People\Skills\Livewire\Reassessment;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Department;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Skills\Enums\ReassessmentRequestStatus;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillReassessmentRequest;
use App\Domains\People\Skills\Services\SkillAudience;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Which reassessments are waiting, and how late each one is (0007-g).
 *
 * A request is written by a head of department from the team-gaps page, or
 * opened automatically by a confirmed training pass, and until now nothing
 * displayed either. The data has existed since 0006-b with no screen at all,
 * so a request could be raised and then be invisible to the person expected to
 * act on it. #16 asks for a reassessment aging queue beside the development
 * action one; this is that queue.
 *
 * It shows and does not act. Performing a reassessment is 0006-c and already
 * has its own capability; reminders and escalation are #18. Making the queue
 * visible first matters because automating an escalation over a list nobody
 * can open would make the escalation the first place the queue is ever seen.
 */
final class Index extends Component
{
    /**
     * Reading the queue, distinct from submitting a request (HOD) or
     * performing one (HR). HR reads the queue without being able to request,
     * which is what the submit capability's own note in authz.php describes.
     */
    public const VIEW_CAPABILITY = 'people.skill.reassessment.view';

    /** Pending by default: the queue's job is what is still waiting. */
    #[Url(as: 'status')]
    public string $status = 'pending';

    /** '1' to show only requests already past their due date. */
    #[Url(as: 'overdue')]
    public string $overdueOnly = '';

    /** 'hod' or 'training'; a person's request and the system's are answered differently. */
    #[Url(as: 'source')]
    public string $source = '';

    /** Department id to narrow to, or empty for every department in scope. */
    #[Url(as: 'department')]
    public string $department = '';

    public function mount(): void
    {
        $this->authorizeView();
    }

    public function render(SkillAudience $audience, TenantContext $tenants): View
    {
        $this->authorizeView();
        $actor = Auth::user();
        $tenantId = (int) $tenants->requireTenantId();
        $companyId = (int) $actor->company_id;

        // The audience decides whose rows these are: HR the company, a head
        // their own reports. Asking the shared scoper rather than filtering by
        // department here keeps one answer to "who may this person see".
        $visible = $audience->visibleEmployeeEntityIdsFor(
            $actor,
            $companyId,
            self::VIEW_CAPABILITY,
        );

        $asOf = now()->toDateString();
        // Resolved once and handed down: rows() needed the same map, and
        // calling the helper in both places cost four queries per render where
        // two do -- paid on every keystroke, since the filters are wire:live.
        $departmentNames = $this->departmentNames($companyId);
        $rows = $this->rows($tenantId, $companyId, $visible, $asOf, $departmentNames);

        return view('people::livewire.reassessment.index', [
            'rows' => $rows,
            'asOf' => $asOf,
            // Only departments this viewer's own rows can come from. The helper
            // was copied from BackupCoverage, which is HR-only; this page is
            // also granted to a head, and offering them a filter listing every
            // department in the company discloses names their audience does not
            // otherwise reach.
            'departments' => $this->visibleDepartments($departmentNames, $visible),
            'statuses' => ReassessmentRequestStatus::cases(),
            'overdueCount' => count(array_filter($rows, static fn (array $r): bool => $r['overdue'])),
        ]);
    }

    /**
     * @param  list<int>  $visible
     * @return list<array{id:int,employee:string,skill:string,department:string,reason:string,
     *                    due_at:string,days:int,overdue:bool,status:string,source:string}>
     */
    private function rows(int $tenantId, int $companyId, array $visible, string $asOf,
        array $departmentNames): array
    {
        if ($visible === []) {
            return [];
        }

        $query = SkillReassessmentRequest::query()
            ->forCompany($tenantId, $companyId)
            ->whereIn('employee_entity_id', $visible);

        if ($this->status !== '') {
            $query->where('status', $this->status);
        }
        if ($this->source !== '') {
            $query->where('source', $this->source);
        }
        if ($this->overdueOnly === '1') {
            // Strictly before today: a request due today still has today to be
            // done in, so the boundary sits on the day itself.
            //
            // whereDate is defensive rather than strictly required here. These
            // columns do carry a time component, but for a '<' comparison
            // against a date-only bound both spellings agree; the two diverge
            // at '<=', which is where a bare compare would answer the wrong
            // question. Spelled this way so changing the operator later cannot
            // quietly introduce that.
            $query->whereDate('due_at', '<', $asOf)
                ->whereIn('status', $this->openStatuses());
        }

        $requests = $query->orderBy('due_at')->orderBy('id')->get();

        if ($requests->isEmpty()) {
            return [];
        }

        $employeeIds = $requests->pluck('employee_entity_id')->map(intval(...))->unique()->all();
        $employees = Employee::query()->whereIn('id', $employeeIds)
            ->get(['id', 'full_name', 'department_id']);
        $names = $employees->pluck('full_name', 'id');
        $departmentOf = $employees->pluck('department_id', 'id');
        $skills = Skill::query()->forCompany($tenantId, $companyId)
            ->whereIn('id', $requests->pluck('skill_id')->unique()->all())
            ->pluck('name', 'id');

        $today = Carbon::parse($asOf)->startOfDay();
        $rows = [];

        foreach ($requests as $request) {
            $employeeId = (int) $request->employee_entity_id;
            $departmentId = $departmentOf[$employeeId] ?? null;

            if ($this->department !== '' && (string) $departmentId !== $this->department) {
                continue;
            }

            $due = $request->due_at->copy()->startOfDay();
            // Signed whole days: negative is time remaining, positive is lateness.
            $days = (int) $due->diffInDays($today, false);
            // Only an open request can be late. A resolved or cancelled one is
            // past its due date and nobody's problem -- labelling it "3 days
            // late" and returning it under "overdue only" would send someone to
            // chase work that is already closed. Reachable from this page's own
            // status filter, so the default of 'pending' was hiding it.
            $isOpen = $request->isOpen();

            $rows[] = [
                'id' => (int) $request->id,
                'employee' => (string) ($names[$employeeId] ?? __('Unknown employee')),
                'skill' => (string) ($skills[$request->skill_id] ?? __('Unknown skill')),
                'department' => (string) ($departmentNames[$departmentId] ?? __('No department')),
                'reason' => (string) $request->reason,
                'due_at' => $due?->toDateString() ?? '',
                'days' => $days,
                'overdue' => $isOpen && $days > 0,
                'open' => $isOpen,
                'status' => $request->status instanceof ReassessmentRequestStatus
                    ? $request->status->label()
                    : (string) $request->status,
                'source' => (string) $request->source,
            ];
        }

        return $rows;
    }

    /**
     * The departments this viewer's audience covers, for the filter.
     *
     * Derived from the visible employee set, not from the rendered rows: rows
     * are already narrowed by the department filter itself, so building the
     * list from them would collapse it to the current selection and leave no
     * way back. Not from the company either -- this page is granted to a head,
     * and listing every department would disclose names their audience does
     * not otherwise reach.
     *
     * @param  array<int, string>  $departmentNames
     * @param  list<int>  $visible
     * @return array<int, string>
     */
    private function visibleDepartments(array $departmentNames, array $visible): array
    {
        if ($visible === []) {
            return [];
        }

        $ids = Employee::query()->whereIn('id', $visible)
            ->pluck('department_id')->filter()->map(intval(...))->unique()->all();

        return array_intersect_key($departmentNames, array_flip($ids));
    }

    /**
     * Status values that can still be late. Derived from the enum rather than
     * listed here, so a new state cannot silently become permanently overdue.
     *
     * @return list<string>
     */
    private function openStatuses(): array
    {
        return array_values(array_map(
            static fn (ReassessmentRequestStatus $case): string => $case->value,
            array_filter(ReassessmentRequestStatus::cases(),
                static fn (ReassessmentRequestStatus $case): bool => $case->isOpen()),
        ));
    }

    /** @return array<int, string> */
    private function departmentNames(int $companyId): array
    {
        return Department::query()->where('company_id', $companyId)->with('type')->get()
            ->mapWithKeys(static fn (Department $department): array => [
                (int) $department->id => (string) ($department->name ?? __('Unnamed department')),
            ])
            ->all();
    }

    private function authorizeView(): void
    {
        try {
            app(SkillAudience::class)->authorizeAudience(Auth::user(), self::VIEW_CAPABILITY);
        } catch (AuthorizationDeniedException) {
            abort(403);
        }
    }
}
