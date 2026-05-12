<?php

namespace App\Modules\People\Settings\Models;

use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeopleRestrictedPersonEntry extends Model
{
    protected $table = 'people_restricted_person_entries';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'person_name',
        'document_type',
        'document_number',
        'status',
        'visibility',
        'summary',
        'details',
        'metadata',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            $entry->status ??= 'active';
            $entry->visibility ??= 'restricted';
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
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
