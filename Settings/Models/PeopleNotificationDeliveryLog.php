<?php

namespace App\Modules\People\Settings\Models;

use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PeopleNotificationDeliveryLog extends Model
{
    protected $table = 'people_notification_delivery_logs';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'notifiable_type',
        'notifiable_id',
        'channel',
        'recipient',
        'subject',
        'status',
        'sent_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }
}
