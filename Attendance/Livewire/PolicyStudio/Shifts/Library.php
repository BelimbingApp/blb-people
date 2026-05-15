<?php

namespace App\Modules\People\Attendance\Livewire\PolicyStudio\Shifts;

use App\Modules\People\Attendance\Livewire\Concerns\InteractsWithAttendanceScreen;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use App\Modules\People\Attendance\Services\ShiftTemplateSerializer;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Library extends Component
{
    use InteractsWithAttendanceScreen;

    public string $shiftTemplateExportJson = '';

    public function editShiftTemplate(int $shiftTemplateId)
    {
        return redirect()->route('people.attendance.shifts', ['shift' => $shiftTemplateId]);
    }

    public function duplicateShiftTemplate(int $shiftTemplateId)
    {
        return redirect()->route('people.attendance.shifts', ['duplicateFrom' => $shiftTemplateId]);
    }

    public function toggleShiftStatus(int $shiftTemplateId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $shift = $this->shiftTemplate($shiftTemplateId);
        $shift->update([
            'status' => $shift->status === 'active' ? 'inactive' : 'active',
        ]);

        session()->flash('success', __('Shift status updated.'));
    }

    public function exportShiftTemplate(int $shiftTemplateId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $shift = $this->shiftTemplate($shiftTemplateId);
        $serializer = app(ShiftTemplateSerializer::class);
        $this->shiftTemplateExportJson = $serializer->toJson($serializer->fromShiftTemplate($shift));

        session()->flash('success', __('Shift template JSON ready to download from :shift.', ['shift' => $shift->code]));
    }

    public function render(): View
    {
        $schemaReady = $this->schemaReady();

        return view('livewire.people.attendance.policy-studio.shifts.library', [
            'schemaReady' => $schemaReady,
            'canManage' => $this->canAttendance('people.attendance.manage'),
            'shiftTemplates' => $schemaReady
                ? AttendanceShiftTemplate::query()
                    ->where('company_id', $this->companyId())
                    ->with('punchWindows')
                    ->orderBy('code')
                    ->get()
                : collect(),
        ]);
    }

    private function shiftTemplate(int $shiftTemplateId): AttendanceShiftTemplate
    {
        return AttendanceShiftTemplate::query()
            ->where('company_id', $this->companyId())
            ->with('punchWindows')
            ->findOrFail($shiftTemplateId);
    }
}
