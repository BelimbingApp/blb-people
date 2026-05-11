<?php
namespace App\Modules\People\Payroll\Livewire;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Modules\Core\Company\Models\Company;
use App\Modules\People\Payroll\Exceptions\ClosedPayrollRunException;
use App\Modules\People\Payroll\Models\PayrollEmployeeStatutoryProfile;
use App\Modules\People\Payroll\Models\PayrollEmployerStatutoryProfile;
use App\Modules\People\Payroll\Models\PayrollPayItem;
use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Payroll\Models\PayrollStatutoryRuleSet;
use App\Modules\People\Payroll\Services\PayrollPayslipBuilder;
use App\Modules\People\Payroll\Services\PayrollRunCalculator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $tab = 'runs';

    public string $search = '';

    public ?int $selectedRunId = null;

    /**
     * @return list<string>
     */
    public function tabs(): array
    {
        return ['runs', 'pay-items', 'profiles', 'rules'];
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, $this->tabs(), true)) {
            return;
        }

        $this->tab = $tab;
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function selectRun(int $runId): void
    {
        $this->selectedRunId = $runId;
        $this->tab = 'runs';
    }

    public function calculateRun(int $runId): void
    {
        $this->authorizeManage();

        try {
            $run = $this->runQuery()->findOrFail($runId);
            app(PayrollRunCalculator::class)->calculate($run);
            $this->selectedRunId = $runId;
            session()->flash('success', __('Payroll run calculated.'));
        } catch (ClosedPayrollRunException $exception) {
            session()->flash('error', $exception->getMessage());
        }
    }

    public function reviewRun(int $runId): void
    {
        $this->transitionRun($runId, 'markReviewed', __('Payroll run reviewed.'));
    }

    public function approveRun(int $runId): void
    {
        $this->transitionRun($runId, 'approve', __('Payroll run approved.'));
    }

    public function closeRun(int $runId): void
    {
        $this->transitionRun($runId, 'close', __('Payroll run closed.'));
    }

    public function voidRun(int $runId): void
    {
        $this->transitionRun($runId, 'void', __('Payroll run voided.'));
    }

    public function statusVariant(string $status): string
    {
        return match ($status) {
            PayrollRun::STATUS_CALCULATED, PayrollRun::STATUS_REVIEWED => 'info',
            PayrollRun::STATUS_APPROVED => 'success',
            PayrollRun::STATUS_CLOSED => 'default',
            PayrollRun::STATUS_VOIDED => 'danger',
            default => 'warning',
        };
    }

    public function render(): View
    {
        $runs = $this->runQuery()
            ->with(['calendar', 'period'])
            ->withCount(['participants', 'inputs', 'resultLines'])
            ->latest('id')
            ->paginate(10);

        $selectedRun = $this->selectedRunId !== null
            ? $this->runQuery()
                ->with([
                    'calendar',
                    'period',
                    'participants.employee',
                    'participants.inputs',
                    'participants.resultLines',
                    'auditEvents' => fn ($query) => $query->latest('occurred_at')->latest('id'),
                ])
                ->find($this->selectedRunId)
            : $runs->first();

        return view('livewire.people.payroll.index', [
            'runs' => $runs,
            'selectedRun' => $selectedRun,
            'payslips' => $this->payslips($selectedRun),
            'payItems' => $this->payItems(),
            'employerProfiles' => $this->employerProfiles(),
            'employeeProfiles' => $this->employeeProfiles(),
            'ruleSets' => $this->ruleSets(),
            'tabs' => [
                ['id' => 'runs', 'label' => __('Runs'), 'icon' => 'heroicon-o-play-circle'],
                ['id' => 'pay-items', 'label' => __('Pay Items'), 'icon' => 'heroicon-o-tag'],
                ['id' => 'profiles', 'label' => __('Statutory Profiles'), 'icon' => 'heroicon-o-identification'],
                ['id' => 'rules', 'label' => __('Rule Tables'), 'icon' => 'heroicon-o-table-cells'],
            ],
        ]);
    }

    private function transitionRun(int $runId, string $method, string $message): void
    {
        $this->authorizeManage();

        try {
            $run = $this->runQuery()->findOrFail($runId);
            $run->{$method}();
            $this->selectedRunId = $runId;
            session()->flash('success', $message);
        } catch (ClosedPayrollRunException $exception) {
            session()->flash('error', $exception->getMessage());
        }
    }

    private function companyId(): int
    {
        return auth()->user()?->company_id ?? Company::LICENSEE_ID;
    }

    private function authorizeManage(): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'people.payroll.manage',
        );
    }

    /**
     * @return Builder<PayrollRun>
     */
    private function runQuery(): Builder
    {
        return PayrollRun::query()
            ->where('company_id', $this->companyId())
            ->when($this->search !== '', function (Builder $query): void {
                $query->where(function (Builder $q): void {
                    $q->where('code', 'like', '%'.$this->search.'%')
                        ->orWhere('name', 'like', '%'.$this->search.'%')
                        ->orWhere('status', 'like', '%'.$this->search.'%');
                });
            });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function payslips(?PayrollRun $run): array
    {
        if ($run === null) {
            return [];
        }

        $builder = app(PayrollPayslipBuilder::class);

        return $run->participants
            ->map(fn ($participant): array => $builder->build($participant))
            ->all();
    }

    private function payItems()
    {
        return PayrollPayItem::query()
            ->with('classifications')
            ->where(function (Builder $query): void {
                $query->where('company_id', $this->companyId())
                    ->orWhereNull('company_id');
            })
            ->orderBy('code')
            ->get();
    }

    private function employerProfiles()
    {
        return PayrollEmployerStatutoryProfile::query()
            ->where('company_id', $this->companyId())
            ->latest('effective_from')
            ->get();
    }

    private function employeeProfiles()
    {
        return PayrollEmployeeStatutoryProfile::query()
            ->with('employee')
            ->where('company_id', $this->companyId())
            ->latest('effective_from')
            ->limit(25)
            ->get();
    }

    private function ruleSets()
    {
        return PayrollStatutoryRuleSet::query()
            ->with('rows')
            ->orderBy('country_iso')
            ->orderBy('rule_key')
            ->latest('effective_from')
            ->get();
    }
}
