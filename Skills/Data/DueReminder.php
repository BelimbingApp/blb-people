<?php

namespace App\Domains\People\Skills\Data;

use App\Domains\People\Skills\Enums\ReminderRule;

/**
 * One thing that is due, for one person, for one reason.
 *
 * It names the subject and the reason and stops there. Nothing here is a
 * message, an address or a channel: this lane decides what is due, and who is
 * told and how is a later decision that should not be pre-empted by the shape
 * of this record.
 *
 * The requirement reference and version travel with it so a reminder can say
 * which requirement it is measured against — a reassessment is only overdue
 * relative to some version's expectations.
 */
final readonly class DueReminder
{
    public function __construct(
        public int $companyEntityId,
        public int $employeeEntityId,
        public int $skillId,
        public ReminderRule $rule,
        public \DateTimeImmutable $dueOn,
        public string $requirementReference,
        public int $requirementVersion,
    ) {}
}
