<?php

namespace App\Domains\People\Training\Livewire\TrainingKpi;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Data\TrainingKpiSummaryResult;
use App\Domains\People\Training\Services\TrainingKpiSummary;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * HR training KPI dashboard: the revised-workbook training controls for one
 * company and each department, each with its definition, as-of and a link
 * to the page that lists the records behind the number (0007-f, #389).
 *
 * Read-only. HR only: the HOD audience has the evaluations dashboard and
 * the effectiveness form for its own department and never sees company
 * controls here. The company is one of the HR user's allowed companies and
 * every read is pinned to it.
 */
final class Index extends Component
{
    public const VIEW_CAPABILITY = 'people.training.kpi.view';

    public ?int $companyEntityId = null;

    /** The as-of date, Y-m-d, from the shared picker; never later than today. */
    public string $asOf = '';

    /** @var array<int, string>|null */
    private ?array $allowedCompanies = null;

    public function mount(): void
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $this->companyEntityId = $companies === [] ? null : (int) array_key_first($companies);
        $this->asOf = now()->toDateString();
    }

    public function selectCompany(int $companyEntityId): void
    {
        $this->authorizeView();
        abort_unless(array_key_exists($companyEntityId, $this->allowedCompanies()), 404);
        $this->companyEntityId = $companyEntityId;
    }

    /** A malformed or future date is ignored: the picker already refused it, and a forged one changes nothing. */
    #[On('standing-as-of-changed')]
    public function setAsOf(string $date): void
    {
        $this->authorizeView();
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date || $date > now()->toDateString()) {
            return;
        }
        $this->asOf = $date;
    }

    public function render(TenantContext $tenants, TrainingKpiSummary $kpis): View
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $summary = null;

        if ($this->companyEntityId !== null && array_key_exists($this->companyEntityId, $companies)) {
            $summary = $kpis->forCompany((int) $tenants->requireTenantId(), $this->companyEntityId, new DateTimeImmutable($this->asOf));
        }

        return view('people::livewire.training-kpi.index', [
            'companies' => $companies,
            'summary' => $summary,
            'companyMetrics' => TrainingKpiSummaryResult::COMPANY,
            'departmentMetrics' => TrainingKpiSummaryResult::DEPARTMENT,
        ]);
    }

    /** @return array<int, string> */
    private function allowedCompanies(): array
    {
        return $this->allowedCompanies ??= app(SkillAudience::class)->allowedCompanies($this->user(), self::VIEW_CAPABILITY);
    }

    private function authorizeView(): void
    {
        try {
            $audiences = app(SkillAudience::class)->authorizeAudience($this->user(), self::VIEW_CAPABILITY);
        } catch (AuthorizationDeniedException) {
            abort(403);
        }

        abort_unless(in_array(SkillAudience::HR, $audiences, true), 403);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
