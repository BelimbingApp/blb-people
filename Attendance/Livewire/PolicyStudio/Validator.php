<?php

namespace App\Modules\People\Attendance\Livewire\PolicyStudio;

use App\Modules\People\Attendance\Livewire\Concerns\InteractsWithAttendanceScreen;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use App\Modules\People\Attendance\Services\AttendancePolicySimulationService;
use App\Modules\People\Attendance\Services\AttendancePolicyValidationService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Validator extends Component
{
    use InteractsWithAttendanceScreen;

    public string $policyPreviewPolicyId = '';

    public string $policyPreviewShiftId = '';

    public string $policyPreviewDate = '';

    public string $policyPreviewClockIn = '08:00';

    public string $policyPreviewClockOut = '17:00';

    /** @var array<string, mixed>|null */
    public ?array $policyValidationResult = null;

    /** @var array<string, mixed>|null */
    public ?array $policySimulationResult = null;

    public function mount(?int $policyGroup = null): void
    {
        $this->policyPreviewDate = now()->toDateString();

        if ($policyGroup !== null) {
            $this->policyPreviewPolicyId = (string) $policyGroup;
        }
    }

    public function validatePolicyPreview(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $policyGroup = $this->selectedPolicyGroup();
        if (! $policyGroup instanceof AttendancePolicyGroup) {
            $this->policyValidationResult = $this->errorResult('policy_required', __('Choose an attendance policy group first.'), 'policyPreviewPolicyId');

            return;
        }

        $this->policyValidationResult = app(AttendancePolicyValidationService::class)->validate($policyGroup);
    }

    public function simulatePolicyPreview(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $validated = $this->validate([
            'policyPreviewPolicyId' => ['required', 'integer'],
            'policyPreviewShiftId' => ['required', 'integer'],
            'policyPreviewDate' => ['required', 'date'],
            'policyPreviewClockIn' => ['required', 'date_format:H:i'],
            'policyPreviewClockOut' => ['required', 'date_format:H:i'],
        ]);

        $policyGroup = $this->selectedPolicyGroup();
        $shiftTemplate = $this->selectedShiftTemplate();
        if (! $policyGroup instanceof AttendancePolicyGroup || ! $shiftTemplate instanceof AttendanceShiftTemplate) {
            $this->policySimulationResult = $this->errorResult('preview_selection_invalid', __('Choose a policy group and shift template from this company.'), 'policyPreviewPolicyId');

            return;
        }

        $this->policySimulationResult = app(AttendancePolicySimulationService::class)->simulate(
            $policyGroup,
            $shiftTemplate,
            $validated['policyPreviewDate'],
            $validated['policyPreviewClockIn'],
            $validated['policyPreviewClockOut'],
        );
    }

    public function render(): View
    {
        $companyId = $this->companyId();
        $schemaReady = $this->schemaReady();

        return view('livewire.people.attendance.policy-studio.validator', [
            'schemaReady' => $schemaReady,
            'canManage' => $this->canAttendance('people.attendance.manage'),
            'policyGroups' => $schemaReady
                ? AttendancePolicyGroup::query()
                    ->where('company_id', $companyId)
                    ->orderBy('code')
                    ->get()
                : collect(),
            'shiftTemplates' => $schemaReady
                ? AttendanceShiftTemplate::query()
                    ->where('company_id', $companyId)
                    ->orderBy('code')
                    ->get()
                : collect(),
        ]);
    }

    private function selectedPolicyGroup(): ?AttendancePolicyGroup
    {
        if ($this->policyPreviewPolicyId === '') {
            return null;
        }

        return AttendancePolicyGroup::query()
            ->where('company_id', $this->companyId())
            ->find((int) $this->policyPreviewPolicyId);
    }

    private function selectedShiftTemplate(): ?AttendanceShiftTemplate
    {
        if ($this->policyPreviewShiftId === '') {
            return null;
        }

        return AttendanceShiftTemplate::query()
            ->where('company_id', $this->companyId())
            ->find((int) $this->policyPreviewShiftId);
    }
}
