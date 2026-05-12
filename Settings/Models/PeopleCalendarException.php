<?php

namespace App\Modules\People\Settings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeopleCalendarException extends Model
{
    protected $table = 'people_calendar_exceptions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'work_calendar_id',
        'occurs_on',
        'name',
        'kind',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurs_on' => 'date',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function workCalendar(): BelongsTo
    {
        return $this->belongsTo(PeopleReferenceEntry::class, 'work_calendar_id');
    }
}
