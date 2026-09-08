<?php

namespace App\Domains\People\Training\Exceptions;

use App\Domains\People\Training\Enums\CutoverWorkflow;
use DateTimeImmutable;
use RuntimeException;

/** A write refused because the cutover window names the legacy portal as authoritative. */
final class CutoverWriteRefusedException extends RuntimeException
{
    public function __construct(
        public readonly CutoverWorkflow $workflow,
        public readonly DateTimeImmutable $windowStartsAt,
        public readonly ?DateTimeImmutable $windowEndsAt,
    ) {
        $until = $windowEndsAt === null ? 'onwards' : 'to '.$windowEndsAt->format('Y-m-d H:i:s');
        parent::__construct(sprintf(
            'Writes to %s are refused while the legacy portal is authoritative from %s %s.',
            $workflow->label(),
            $windowStartsAt->format('Y-m-d H:i:s'),
            $until,
        ));
    }
}
