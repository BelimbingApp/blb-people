<?php

namespace App\Domains\People\Skills\Models;

use App\Domains\People\Skills\Exceptions\InvalidSkillCertificationException;
use App\Domains\People\Skills\Models\Concerns\CompanyOwned;

/** Immutable mapping between one certification and an attested skill. */
class SkillCertificationSkill extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_connector_skill_certification_skills';

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new InvalidSkillCertificationException(
                'Certification skill mappings are append-only; create a new certification record instead.',
            );
        });

        static::deleting(function (): never {
            throw new InvalidSkillCertificationException(
                'Certification skill mappings are append-only and cannot be deleted.',
            );
        });
    }

    protected function casts(): array
    {
        return [
            'certification_id' => 'integer',
            'skill_id' => 'integer',
        ];
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'skill_certification_skill', 'id' => $this->getKey()];
    }
}
