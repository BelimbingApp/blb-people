<?php

namespace App\Domains\People\Skills\Livewire\HrDashboard;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Skills\Data\SkillKpiSummaryResult;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\SkillKpiSummary;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * HR skill KPI dashboard: the #16 contractual metrics for one company, per
 * department, each with its definition and as-of, and a link to the page that
 * lists the records behind the number (0007-e, #363).
 *
 * Read-only. HR only: the HOD audience has its own planning and team-gap
 * pages and never sees company-level rates here.
 */
final class Index extends Component
{
    public const VIEW_CAPABILITY = 'people.skill.hr.view';

    public ?int $companyEntityId = null;

    /** Department (organisation unit) id to narrow the department table to, or empty for all. */
    public string $department = '';

    /** @var array<int, string>|null */
    private ?array $allowedCompanies = null;

    public function mount(): void
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $this->companyEntityId = $companies === [] ? null : (int) array_key_first($companies);
    }

    public function selectCompany(int $companyEntityId): void
    {
        $this->authorizeView();
        abort_unless(array_key_exists($companyEntityId, $this->allowedCompanies()), 404);
        $this->companyEntityId = $companyEntityId;
        $this->department = '';
    }

    public function selectDepartment(string $department): void
    {
        $this->authorizeView();
        $this->department = ctype_digit($department) ? $department : '';
    }

    public function render(TenantContext $tenants, SkillKpiSummary $kpis): View
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $summary = null;
        $departmentRows = [];

        if ($this->companyEntityId !== null && array_key_exists($this->companyEntityId, $companies)) {
            $summary = $kpis->forCompany((int) $tenants->requireTenantId(), $this->companyEntityId);
            $departmentRows = $this->department === ''
                ? $summary->departments
                : array_values(array_filter(
                    $summary->departments,
                    fn (array $row): bool => $row['department_entity_id'] === (int) $this->department,
                ));
        }

        return view('people::livewire.hr-dashboard.index', [
            'companies' => $companies,
            'summary' => $summary,
            'departmentRows' => $departmentRows,
            'metrics' => SkillKpiSummaryResult::METRICS,
            'links' => $summary === null ? [] : $this->links($summary),
        ]);
    }

    /**
     * Where each count metric's records are listed: department key (or
     * 'company') => metric => URL. Rates have no list of their own.
     *
     * @return array<string, array<string, string>>
     */
    private function links(SkillKpiSummaryResult $summary): array
    {
        $targets = [
            'active_staff' => ['people.skill.assessment.matrix', []],
            'expected_assessments' => ['people.skill.assessment.matrix', []],
            'latest_records' => ['people.skill.assessment.matrix', []],
            'verified_competent' => ['people.skill.assessment.matrix', ['band' => 'meets,exceeds']],
            'major_critical_gaps' => ['people.skill.assessment.matrix', ['band' => 'major_gap,critical_gap']],
            'due_within_30_days' => ['people.skill.assessment.matrix', []],
            'open_actions' => ['people.skill.development-actions.index', ['closure' => 'open,pending_reassessment,further_action_required']],
            'overdue_actions' => ['people.skill.development-actions.index', ['closure' => 'open,pending_reassessment,further_action_required', 'overdue' => '1']],
            'scheduled_training' => ['people.training.events.index', ['status' => 'scheduled,in_progress']],
        ];
        $scopes = ['company' => null];

        foreach ($summary->departments as $row) {
            $scopes[(string) ($row['department_entity_id'] ?? 'none')] = $row['department_entity_id'];
        }

        $links = [];

        foreach ($scopes as $scope => $departmentId) {
            foreach ($targets as $metric => [$route, $query]) {
                if ($departmentId !== null) {
                    $query['department'] = $departmentId;
                }
                $links[$scope][$metric] = route($route, $query);
            }
        }

        return $links;
    }

    /** @return array<int, string> */
    private function allowedCompanies(): array
    {
        return $this->allowedCompanies ??= app(SkillAudience::class)->allowedCompanies(Auth::user(), self::VIEW_CAPABILITY);
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
