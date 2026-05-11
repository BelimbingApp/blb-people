<?php
namespace App\Modules\People\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollStatutoryRuleRow extends Model
{
    protected $table = 'payroll_statutory_rule_rows';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'payroll_statutory_rule_set_id',
        'sort_order',
        'row_key',
        'min_wage',
        'max_wage',
        'employee_rate',
        'employer_rate',
        'employee_amount',
        'employer_amount',
        'levy_rate',
        'row_data',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'min_wage' => 'decimal:4',
            'max_wage' => 'decimal:4',
            'employee_rate' => 'decimal:8',
            'employer_rate' => 'decimal:8',
            'employee_amount' => 'decimal:4',
            'employer_amount' => 'decimal:4',
            'levy_rate' => 'decimal:8',
            'row_data' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(PayrollStatutoryRuleSet::class, 'payroll_statutory_rule_set_id');
    }
}
