<?php

namespace App\Modules\People\Attendance\Livewire;

use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Modules\People\Attendance\Livewire\Concerns\InteractsWithAttendanceScreen;
use App\Modules\People\Attendance\Models\AttendanceGeofence;
use App\Modules\People\Attendance\Models\AttendanceGeofenceGroup;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Locations extends Component
{
    use InteractsWithAttendanceScreen;
    use InteractsWithNotifications;

    public function render(): View
    {
        $companyId = $this->companyId();
        $schemaReady = $this->schemaReady();

        return view('people-attendance::livewire.people.attendance.locations', [
            'schemaReady' => $schemaReady,
            'geofences' => $schemaReady
                ? AttendanceGeofence::query()
                    ->where('company_id', $companyId)
                    ->orderBy('code')
                    ->get()
                : collect(),
            'geofenceGroups' => $schemaReady
                ? AttendanceGeofenceGroup::query()
                    ->where('company_id', $companyId)
                    ->with('fences')
                    ->orderBy('code')
                    ->get()
                : collect(),
        ]);
    }
}
