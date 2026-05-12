<?php

namespace App\Modules\People\Settings\Models;

use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeopleReferenceAlias extends Model
{
    protected $table = 'people_reference_aliases';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'people_reference_entry_id',
        'source_system',
        'source_type',
        'source_code',
        'source_label',
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

    public function entry(): BelongsTo
    {
        return $this->belongsTo(PeopleReferenceEntry::class, 'people_reference_entry_id');
    }
}
