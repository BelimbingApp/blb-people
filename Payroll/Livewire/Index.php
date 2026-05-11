<?php

namespace App\Modules\People\Payroll\Livewire;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Payroll\Exceptions\ClosedPayrollRunException;
use App\Modules\People\Payroll\Models\PayrollEmployeeStatutoryProfile;
use App\Modules\People\Payroll\Models\PayrollEmployerStatutoryProfile;
use App\Modules\People\Payroll\Models\PayrollInput;
use App\Modules\People\Payroll\Models\PayrollPayItem;
use App\Modules\People\Payroll\Models\PayrollPayItemClassification;
use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Payroll\Models\PayrollStatutoryRuleRow;
use App\Modules\People\Payroll\Models\PayrollStatutoryRuleSet;
use App\Modules\People\Payroll\Services\PayrollCountryPackRegistry;
use App\Modules\People\Payroll\Services\PayrollPayslipBuilder;
use App\Modules\People\Payroll\Services\PayrollRunCalculator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $tab = 'runs';

    public string $search = '';

    public ?int $selectedRunId = null;

    public string $payItemCode = '';

    public string $payItemName = '';

    public string $payItemInputType = PayrollInput::TYPE_EARNING;

    public string $classificationPayItemId = '';

    public string $classificationCountryIso = 'MY';

    public string $classificationKey = 'statutory_wage_base';

    public string $classificationValue = '';

    public string $classificationEffectiveFrom = '2026-01-01';

    public string $classificationSourcePack = 'belimbing/payroll-my';

    public string $classificationSourceVersion = '2026.dev';

    public string $employerProfileCountryIso = 'MY';

    public string $employerProfileSourcePack = 'belimbing/payroll-my';

    public string $employerProfileSourceVersion = '2026.dev';

    public string $employerProfileEffectiveFrom = '2026-01-01';

    public string $employerProfileData = '{
    "epf_employer_number": "",
    "socso_employer_number": "",
    "lhdn_employer_number": "",
    "hrd_levy_applicable": true
}';

    public string $employeeProfileEmployeeId = '';

    public string $employeeProfileCountryIso = 'MY';

    public string $employeeProfileSourcePack = 'belimbing/payroll-my';

    public string $employeeProfileSourceVersion = '2026.dev';

    public string $employeeProfileEffectiveFrom = '2026-01-01';

    public string $employeeProfileData = '{
    "citizenship_status": "citizen",
    "tax_residency": "resident",
    "epf_number": "",
    "socso_number": "",
    "tax_number": ""
}';

    public string $ruleSetCountryIso = 'MY';

    public string $ruleSetRuleKey = '';

    public string $ruleSetName = '';

    public string $ruleSetSourcePack = 'belimbing/payroll-my';

    public string $ruleSetSourceVersion = '2026.dev';

    public string $ruleSetEffectiveFrom = '2026-01-01';

    public string $ruleSetRoundingPolicy = '{"mode":"ceiling","precision":"0.01"}';

    public string $ruleRowRuleSetId = '';

    public string $ruleRowKey = '';

    public string $ruleRowMinWage = '';

    public string $ruleRowMaxWage = '';

    public string $ruleRowEmployeeRate = '';

    public string $ruleRowEmployerRate = '';

    public string $ruleRowLevyRate = '';

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

    public function createPayItem(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'payItemCode' => ['required', 'string', 'max:100'],
            'payItemName' => ['required', 'string', 'max:255'],
            'payItemInputType' => ['required', Rule::in([PayrollInput::TYPE_EARNING, PayrollInput::TYPE_DEDUCTION, PayrollInput::TYPE_REIMBURSEMENT])],
        ]);
        $code = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', trim($validated['payItemCode'])));
        $code = trim($code, '_');

        if ($code === '') {
            throw ValidationException::withMessages([
                'payItemCode' => __('Enter at least one letter or number.'),
            ]);
        }

        if (PayrollPayItem::query()->where('company_id', $this->companyId())->where('code', $code)->exists()) {
            throw ValidationException::withMessages([
                'payItemCode' => __('This pay item code is already used.'),
            ]);
        }

        $payItem = PayrollPayItem::query()->create([
            'company_id' => $this->companyId(),
            'code' => $code,
            'name' => $validated['payItemName'],
            'input_type' => $validated['payItemInputType'],
            'status' => 'active',
        ]);

        $this->classificationPayItemId = (string) $payItem->id;
        $this->reset('payItemCode', 'payItemName');
        $this->payItemInputType = PayrollInput::TYPE_EARNING;
        session()->flash('success', __('Pay item created.'));
    }

    public function createClassification(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'classificationPayItemId' => ['required', 'integer', Rule::exists(PayrollPayItem::class, 'id')->where('company_id', $this->companyId())],
            'classificationCountryIso' => ['nullable', 'string', 'size:2'],
            'classificationKey' => ['required', 'string', 'max:100'],
            'classificationValue' => ['required', 'string', 'max:255'],
            'classificationEffectiveFrom' => ['required', 'date'],
            'classificationSourcePack' => ['required', 'string', 'max:255'],
            'classificationSourceVersion' => ['required', 'string', 'max:100'],
        ]);

        PayrollPayItemClassification::query()->updateOrCreate(
            [
                'payroll_pay_item_id' => (int) $validated['classificationPayItemId'],
                'country_iso' => filled($validated['classificationCountryIso'] ?? null) ? strtoupper((string) $validated['classificationCountryIso']) : null,
                'classification_key' => $validated['classificationKey'],
                'effective_from' => $validated['classificationEffectiveFrom'],
            ],
            [
                'classification_value' => $validated['classificationValue'],
                'source_pack' => $validated['classificationSourcePack'],
                'source_version' => $validated['classificationSourceVersion'],
                'metadata' => ['source' => 'payroll-workbench'],
            ],
        );

        $this->reset('classificationValue');
        session()->flash('success', __('Pay item classification saved.'));
    }

    public function createEmployerProfile(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'employerProfileCountryIso' => ['required', 'string', 'size:2'],
            'employerProfileSourcePack' => ['required', 'string', 'max:255'],
            'employerProfileSourceVersion' => ['required', 'string', 'max:100'],
            'employerProfileEffectiveFrom' => ['required', 'date'],
            'employerProfileData' => ['required', 'string'],
        ]);

        PayrollEmployerStatutoryProfile::query()->updateOrCreate(
            [
                'company_id' => $this->companyId(),
                'country_iso' => strtoupper($validated['employerProfileCountryIso']),
                'effective_from' => $validated['employerProfileEffectiveFrom'],
            ],
            [
                'source_pack' => $validated['employerProfileSourcePack'],
                'source_version' => $validated['employerProfileSourceVersion'],
                'profile_data' => $this->jsonPayload($validated['employerProfileData'], 'employerProfileData'),
                'validation_messages' => [],
                'metadata' => ['source' => 'payroll-workbench'],
            ],
        );

        session()->flash('success', __('Employer statutory profile saved.'));
    }

    public function createEmployeeProfile(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'employeeProfileEmployeeId' => ['required', 'integer', Rule::exists(Employee::class, 'id')->where('company_id', $this->companyId())],
            'employeeProfileCountryIso' => ['required', 'string', 'size:2'],
            'employeeProfileSourcePack' => ['required', 'string', 'max:255'],
            'employeeProfileSourceVersion' => ['required', 'string', 'max:100'],
            'employeeProfileEffectiveFrom' => ['required', 'date'],
            'employeeProfileData' => ['required', 'string'],
        ]);

        PayrollEmployeeStatutoryProfile::query()->updateOrCreate(
            [
                'company_id' => $this->companyId(),
                'employee_id' => (int) $validated['employeeProfileEmployeeId'],
                'country_iso' => strtoupper($validated['employeeProfileCountryIso']),
                'effective_from' => $validated['employeeProfileEffectiveFrom'],
            ],
            [
                'source_pack' => $validated['employeeProfileSourcePack'],
                'source_version' => $validated['employeeProfileSourceVersion'],
                'profile_data' => $this->jsonPayload($validated['employeeProfileData'], 'employeeProfileData'),
                'validation_messages' => [],
                'metadata' => ['source' => 'payroll-workbench'],
            ],
        );

        session()->flash('success', __('Employee statutory profile saved.'));
    }

    public function createRuleSet(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'ruleSetCountryIso' => ['required', 'string', 'size:2'],
            'ruleSetRuleKey' => ['required', 'string', 'max:100'],
            'ruleSetName' => ['required', 'string', 'max:255'],
            'ruleSetSourcePack' => ['required', 'string', 'max:255'],
            'ruleSetSourceVersion' => ['required', 'string', 'max:100'],
            'ruleSetEffectiveFrom' => ['required', 'date'],
            'ruleSetRoundingPolicy' => ['nullable', 'string'],
        ]);

        $ruleSet = PayrollStatutoryRuleSet::query()->updateOrCreate(
            [
                'country_iso' => strtoupper($validated['ruleSetCountryIso']),
                'rule_key' => $validated['ruleSetRuleKey'],
                'source_pack' => $validated['ruleSetSourcePack'],
                'source_version' => $validated['ruleSetSourceVersion'],
                'effective_from' => $validated['ruleSetEffectiveFrom'],
            ],
            [
                'name' => $validated['ruleSetName'],
                'rounding_policy' => $this->optionalJsonPayload($validated['ruleSetRoundingPolicy'] ?? null, 'ruleSetRoundingPolicy'),
                'metadata' => ['source' => 'payroll-workbench'],
            ],
        );

        $this->ruleRowRuleSetId = (string) $ruleSet->id;
        session()->flash('success', __('Statutory rule set saved.'));
    }

    public function createRuleRow(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'ruleRowRuleSetId' => ['required', 'integer', Rule::exists(PayrollStatutoryRuleSet::class, 'id')],
            'ruleRowKey' => ['nullable', 'string', 'max:100'],
            'ruleRowMinWage' => ['nullable', 'numeric'],
            'ruleRowMaxWage' => ['nullable', 'numeric'],
            'ruleRowEmployeeRate' => ['nullable', 'numeric'],
            'ruleRowEmployerRate' => ['nullable', 'numeric'],
            'ruleRowLevyRate' => ['nullable', 'numeric'],
        ]);

        $nextOrder = (int) PayrollStatutoryRuleRow::query()
            ->where('payroll_statutory_rule_set_id', $validated['ruleRowRuleSetId'])
            ->max('sort_order') + 10;

        PayrollStatutoryRuleRow::query()->create([
            'payroll_statutory_rule_set_id' => (int) $validated['ruleRowRuleSetId'],
            'sort_order' => $nextOrder,
            'row_key' => $this->blankToNull($validated['ruleRowKey']),
            'min_wage' => $this->blankToNull($validated['ruleRowMinWage']),
            'max_wage' => $this->blankToNull($validated['ruleRowMaxWage']),
            'employee_rate' => $this->blankToNull($validated['ruleRowEmployeeRate']),
            'employer_rate' => $this->blankToNull($validated['ruleRowEmployerRate']),
            'levy_rate' => $this->blankToNull($validated['ruleRowLevyRate']),
            'metadata' => ['source' => 'payroll-workbench'],
        ]);

        $this->reset('ruleRowKey', 'ruleRowMinWage', 'ruleRowMaxWage', 'ruleRowEmployeeRate', 'ruleRowEmployerRate', 'ruleRowLevyRate');
        session()->flash('success', __('Statutory rule row added.'));
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
        $authActor = Actor::forUser(Auth::user());
        $canManage = app(AuthorizationService::class)
            ->can($authActor, 'people.payroll.manage')
            ->allowed;

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
            'employees' => $this->employees(),
            'countryPacks' => $this->countryPacks(),
            'canManage' => $canManage,
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

    private function employees()
    {
        return Employee::query()
            ->where('company_id', $this->companyId())
            ->where('status', 'active')
            ->orderBy('full_name')
            ->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function countryPacks(): array
    {
        return collect(app(PayrollCountryPackRegistry::class)->all())
            ->map(function ($pack): array {
                $manifest = $pack->manifest();

                return [
                    'country_iso' => $manifest->normalizedCountryIso(),
                    'pack_identifier' => $manifest->packIdentifier,
                    'pack_version' => $manifest->packVersion,
                    'statutory_data_versions' => $manifest->statutoryDataVersions,
                    'employer_schema' => $pack->profileSchemas()->employerSchema(),
                    'employee_schema' => $pack->profileSchemas()->employeeSchema(),
                    'exports' => $pack->exports()->definitions(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonPayload(string $payload, string $field): array
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                $field => __('Enter a valid JSON object.'),
            ]);
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function optionalJsonPayload(?string $payload, string $field): ?array
    {
        if ($payload === null || trim($payload) === '') {
            return null;
        }

        return $this->jsonPayload($payload, $field);
    }

    private function blankToNull(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
