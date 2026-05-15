<?php

namespace App\Modules\People\Attendance\Livewire\PolicyStudio;

use App\Modules\People\Attendance\Livewire\Concerns\InteractsWithAttendanceScreen;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Services\PolicyTemplateSerializer;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Library extends Component
{
    use InteractsWithAttendanceScreen;

    public string $policyTemplateExportJson = '';

    public function togglePolicyStatus(int $policyGroupId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $policy = $this->policyGroup($policyGroupId);
        $policy->update([
            'status' => $policy->status === AttendancePolicyGroup::STATUS_ACTIVE
                ? AttendancePolicyGroup::STATUS_INACTIVE
                : AttendancePolicyGroup::STATUS_ACTIVE,
        ]);

        session()->flash('success', __('Policy status updated.'));
    }

    public function deletePolicyGroup(int $policyGroupId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $this->policyGroup($policyGroupId)->delete();

        session()->flash('success', __('Policy group deleted.'));
    }

    public function editPolicyGroup(int $policyGroupId)
    {
        return redirect()->route('people.attendance.policy-studio.builder', ['policyGroup' => $policyGroupId]);
    }

    public function duplicatePolicyGroup(int $policyGroupId)
    {
        return redirect()->route('people.attendance.policy-studio.builder', ['duplicateFrom' => $policyGroupId]);
    }

    public function simulatePolicyGroup(int $policyGroupId)
    {
        return redirect()->route('people.attendance.policy-studio.validator', ['policyGroup' => $policyGroupId]);
    }

    public function exportPolicyGroupTemplate(int $policyGroupId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $policy = $this->policyGroup($policyGroupId);
        $serializer = app(PolicyTemplateSerializer::class);
        $this->policyTemplateExportJson = $serializer->toJson($serializer->fromPolicyGroup($policy));

        session()->flash('success', __('Policy template JSON ready to download from :policy.', ['policy' => $policy->code]));
    }

    public function render(): View
    {
        $schemaReady = $this->schemaReady();

        return view('livewire.people.attendance.policy-studio.library', [
            'schemaReady' => $schemaReady,
            'canManage' => $this->canAttendance('people.attendance.manage'),
            'policyGroups' => $schemaReady
                ? AttendancePolicyGroup::query()
                    ->where('company_id', $this->companyId())
                    ->with('allowanceRules')
                    ->orderBy('code')
                    ->get()
                : collect(),
        ]);
    }

    private function policyGroup(int $policyGroupId): AttendancePolicyGroup
    {
        return AttendancePolicyGroup::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($policyGroupId);
    }
}
