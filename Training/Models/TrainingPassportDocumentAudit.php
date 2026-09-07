<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Contracts\ReferencesWorkforceEntities;
use App\Domains\People\Skills\Data\WorkforceReference;
use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use App\Domains\People\Training\Exceptions\TrainingPassportDenied;
use Carbon\CarbonImmutable;

/**
 * Append-only trail of who generated or downloaded a passport document.
 *
 * @property int $id
 * @property int $document_id
 * @property int $employee_entity_id
 * @property string $event_type
 * @property int $actor_user_id
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable $occurred_at
 */
final class TrainingPassportDocumentAudit extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    public $timestamps = false;

    protected $table = 'people_training_passport_document_audits';

    public function workforceReferences(): array
    {
        return [new WorkforceReference('employee_entity_id', WorkforceResourceType::Employee)];
    }

    protected function casts(): array
    {
        return [
            'document_id' => 'integer',
            'employee_entity_id' => 'integer',
            'actor_user_id' => 'integer',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        $immutable = fn (): never => throw new TrainingPassportDenied(
            'Training passport document audit records are append-only.',
        );

        self::updating($immutable);
        self::deleting($immutable);
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'training_passport_document_audit', 'id' => $this->getKey()];
    }
}
