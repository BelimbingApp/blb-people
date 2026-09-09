<?php

namespace App\Domains\People\Skills\Data;

use App\Domains\People\Skills\Enums\CertificationRenewalStatus;
use DateTimeInterface;

/**
 * Input for one employee certification or qualification record.
 *
 * Skill ids are deliberately a list: one external certificate may attest to
 * several skills, while an empty list remains a valid historical record that
 * cannot contribute to skill coverage until it is explicitly mapped.
 */
final readonly class SkillCertificationDraft
{
    /**
     * @param  list<int>  $skillIds
     */
    public function __construct(
        public int $employeeEntityId,
        public string $issuer,
        public string $externalReference,
        public DateTimeInterface $issuedOn,
        public ?DateTimeInterface $expiresOn = null,
        public CertificationRenewalStatus $renewalStatus = CertificationRenewalStatus::Current,
        public string $evidenceLink = '',
        public array $skillIds = [],
    ) {}
}
