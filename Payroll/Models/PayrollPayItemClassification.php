<?php
namespace App\Modules\People\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollPayItemClassification extends Model
{
    protected $table = 'people_payroll_pay_item_classifications';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'payroll_pay_item_id',
        'country_iso',
        'classification_key',
        'classification_value',
        'effective_from',
        'effective_to',
        'source_pack',
        'source_version',
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
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function payItem(): BelongsTo
    {
        return $this->belongsTo(PayrollPayItem::class, 'payroll_pay_item_id');
    }
}
