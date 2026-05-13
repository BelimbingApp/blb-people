<?php
namespace App\Modules\People\Payroll\Models;

use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollRunAuditEvent extends Model
{
    protected $table = 'people_payroll_run_audit_events';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'payroll_run_id',
        'user_id',
        'action',
        'message',
        'payload',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
