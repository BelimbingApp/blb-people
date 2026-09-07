<?php

namespace App\Domains\People\Training\Models;

use App\Base\Media\Models\MediaAsset;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Contracts\ReferencesWorkforceEntities;
use App\Domains\People\Skills\Data\WorkforceReference;
use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One generated training passport PDF (0014-a): the media asset holding the
 * bytes, the employee it describes and the moment it stops being
 * downloadable. Rows are never edited; a new generation is a new row.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $company_entity_id
 * @property int $employee_entity_id
 * @property int|null $media_asset_id
 * @property string $template_version
 * @property string $data_version
 * @property string $sha256
 * @property int $bytes
 * @property int $generated_by_user_id
 * @property CarbonImmutable $generated_at
 * @property CarbonImmutable $expires_at
 * @property-read MediaAsset|null $asset
 */
final class TrainingPassportDocument extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    public const EVENT_GENERATED = 'generated';

    public const EVENT_DOWNLOADED = 'downloaded';

    protected $table = 'people_training_passport_documents';

    public function workforceReferences(): array
    {
        return [new WorkforceReference('employee_entity_id', WorkforceResourceType::Employee)];
    }

    protected function casts(): array
    {
        return [
            'employee_entity_id' => 'integer',
            'media_asset_id' => 'integer',
            'bytes' => 'integer',
            'generated_by_user_id' => 'integer',
            'generated_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    /** @return HasMany<TrainingPassportDocumentAudit, $this> */
    public function audits(): HasMany
    {
        return $this->hasMany(TrainingPassportDocumentAudit::class, 'document_id')->orderBy('id');
    }

    /** @param  Builder<self>  $query */
    public function scopeUnexpired(Builder $query): void
    {
        $query->where($this->qualifyColumn('expires_at'), '>', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isBefore(now()) || $this->expires_at->equalTo(now());
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'training_passport_document', 'id' => $this->getKey()];
    }
}
