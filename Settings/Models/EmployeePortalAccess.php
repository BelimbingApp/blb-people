<?php

namespace App\Modules\People\Settings\Models;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class EmployeePortalAccess extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    protected $table = 'people_employee_portal_accesses';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'user_id',
        'login_identifier',
        'display_name',
        'email',
        'status',
        'activated_at',
        'revoked_at',
        'last_invited_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_invited_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function activate(?Carbon $at = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_ACTIVE,
            'activated_at' => $at ?? now(),
            'revoked_at' => null,
        ])->save();
    }

    public function revoke(?Carbon $at = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_REVOKED,
            'revoked_at' => $at ?? now(),
        ])->save();
    }

    public function markInvited(?Carbon $at = null): void
    {
        $this->forceFill(['last_invited_at' => $at ?? now()])->save();
    }
}
