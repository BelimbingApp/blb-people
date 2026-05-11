<?php
namespace App\Modules\People\Payroll\Models;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollEmployeeStatutoryProfile extends Model
{
    protected $table = 'payroll_employee_statutory_profiles';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'employee_id',
        'country_iso',
        'source_pack',
        'source_version',
        'effective_from',
        'effective_to',
        'profile_data',
        'validation_messages',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'profile_data' => 'array',
            'validation_messages' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
