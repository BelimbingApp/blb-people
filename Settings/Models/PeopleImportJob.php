<?php

namespace App\Modules\People\Settings\Models;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeopleImportJob extends Model
{
    public const STATUS_VALIDATED = 'validated';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_FAILED = 'failed';

    protected $table = 'people_import_jobs';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'created_by_user_id',
        'source_system',
        'source_label',
        'target_type',
        'dry_run',
        'status',
        'summary',
        'row_results',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'summary' => 'array',
            'row_results' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
