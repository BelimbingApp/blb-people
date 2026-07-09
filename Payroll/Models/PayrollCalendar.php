<?php

namespace App\Modules\People\Payroll\Models;

use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollCalendar extends Model
{
    protected $table = 'people_payroll_calendars';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'code',
        'name',
        'country_iso',
        'currency',
        'frequency',
        'status',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * @return HasMany<PayrollPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(PayrollPeriod::class, 'payroll_calendar_id');
    }
}
