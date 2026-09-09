<?php

namespace App\Domains\People\Skills\Models;

use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Contracts\ReferencesWorkforceEntities;
use App\Domains\People\Skills\Data\WorkforceReference;
use App\Domains\People\Skills\Enums\CertificationRenewalStatus;
use App\Domains\People\Skills\Exceptions\InvalidSkillCertificationException;
use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use DateTimeInterface;

/**
 * Append-only evidence that an employee holds an externally issued
 * certification or qualification. A renewal is a new row linked through
 * supersedes_certification_id; the original remains readable history.
 */
class SkillCertification extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    protected $table = 'people_connector_skill_certifications';

    public function workforceReferences(): array
    {
        return [
            new WorkforceReference('employee_entity_id', WorkforceResourceType::Employee),
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new InvalidSkillCertificationException(
                'Certification records are append-only; create a renewal record instead of editing one.',
            );
        });

        static::deleting(function (): never {
            throw new InvalidSkillCertificationException(
                'Certification records are append-only and cannot be deleted.',
            );
        });
    }

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'expires_on' => 'date',
            'renewal_status' => CertificationRenewalStatus::class,
            'supersedes_certification_id' => 'integer',
        ];
    }

    public function isCurrent(?DateTimeInterface $asOf = null): bool
    {
        if ($this->renewal_status === CertificationRenewalStatus::Renewed) {
            return false;
        }

        return $this->expires_on === null
            || $this->expires_on->format('Y-m-d') >= ($asOf ?? now())->format('Y-m-d');
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'skill_certification', 'id' => $this->getKey()];
    }
}
