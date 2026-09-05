<?php

namespace App\Domains\People\Training\Data;

use App\Domains\People\Training\Enums\AttendanceStatus;
use DateTimeInterface;

final readonly class ParticipationFactDraft
{
    /** @param list<string> $evidenceReferences Opaque governed-document identifiers, never URLs or filenames. */
    public function __construct(
        public AttendanceStatus $attendance,
        public int $actualMinutes,
        public string $source,
        public string $sourceReference,
        public ?LearningTestResult $preTest = null,
        public ?LearningTestResult $postTest = null,
        public ?string $certificateReference = null,
        public ?DateTimeInterface $certificateValidFrom = null,
        public ?DateTimeInterface $certificateValidUntil = null,
        public array $evidenceReferences = [],
    ) {}
}
