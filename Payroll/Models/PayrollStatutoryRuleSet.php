<?php
namespace App\Modules\People\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollStatutoryRuleSet extends Model
{
    protected $table = 'people_payroll_statutory_rule_sets';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'country_iso',
        'rule_key',
        'name',
        'source_pack',
        'source_version',
        'effective_from',
        'effective_to',
        'rounding_policy',
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
            'rounding_policy' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PayrollStatutoryRuleRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(PayrollStatutoryRuleRow::class, 'payroll_statutory_rule_set_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
