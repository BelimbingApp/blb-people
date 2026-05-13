<?php

namespace App\Modules\People\Claim\Models;

use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimContext extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'people_claim_contexts';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'code',
        'label',
        'max_claim_limit',
        'status',
        'source_system',
        'source_label',
        'source_code',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'max_claim_limit' => 'decimal:2',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
