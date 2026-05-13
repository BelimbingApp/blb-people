<?php

namespace App\Modules\People\Claim\Models;

use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClaimAssignment extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'people_claim_assignments';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'code',
        'name',
        'cohort_predicate',
        'effective_from',
        'effective_to',
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
            'cohort_predicate' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** @return HasMany<ClaimAssignmentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ClaimAssignmentLine::class, 'claim_assignment_id')->orderBy('sort_order');
    }
}
