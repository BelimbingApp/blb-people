<?php

namespace App\Modules\People\Claim\Models;

use App\Base\Database\Concerns\BelongsToCompany;
use App\Base\Database\Concerns\HasActiveInactiveStatus;
use App\Base\Database\Concerns\HasEffectiveDateRange;
use App\Base\Database\Concerns\TracksExternalSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClaimAssignment extends Model
{
    use BelongsToCompany;
    use HasActiveInactiveStatus;
    use HasEffectiveDateRange;
    use TracksExternalSource;

    protected $table = 'people_claim_assignments';

    /** @var list<string> */
    protected $fillable = [
        ...self::COMPANY_FILLABLE,
        'code',
        'name',
        'cohort_predicate',
        ...self::EFFECTIVE_DATE_RANGE_FILLABLE,
        'status',
        ...self::EXTERNAL_SOURCE_FILLABLE,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cohort_predicate' => 'array',
            ...self::EFFECTIVE_DATE_RANGE_CASTS,
            ...self::EXTERNAL_SOURCE_CASTS,
        ];
    }

    /** @return HasMany<ClaimAssignmentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ClaimAssignmentLine::class, 'claim_assignment_id')->orderBy('sort_order');
    }
}
