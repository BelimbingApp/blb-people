<?php

namespace App\Modules\People\Attendance\Models;

use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AttendanceGeofenceGroup extends Model
{
    protected $table = 'people_attendance_geofence_groups';

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'cohort_predicate',
        'status',
        'source_system',
        'source_label',
        'source_code',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'cohort_predicate' => 'array',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function fences(): BelongsToMany
    {
        return $this->belongsToMany(
            AttendanceGeofence::class,
            'attendance_geofence_group_fences',
            'attendance_geofence_group_id',
            'attendance_geofence_id',
        )->withPivot(['sort_order', 'metadata'])->withTimestamps();
    }
}
