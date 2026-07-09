<?php

namespace App\Modules\People\Payroll\Models;

use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollPayItem extends Model
{
    protected $table = 'people_payroll_pay_items';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'code',
        'name',
        'input_type',
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
     * @return HasMany<PayrollPayItemClassification, $this>
     */
    public function classifications(): HasMany
    {
        return $this->hasMany(PayrollPayItemClassification::class, 'payroll_pay_item_id');
    }
}
