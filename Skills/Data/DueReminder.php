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
 *
 * A critical coverage gap names a department and a skill instead of an
 * employee: `employeeEntityId` is 0, `departmentId` carries the subject, and
 * `holders`, `minimum` and `requiredLevel` say how short the cover is. The
 * requirement reference is empty because the gap is measured against the
 * strictest requirement anyone in the department carries, not one version.
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
        public ?int $developmentActionId = null,
        public ?int $ownerEmployeeEntityId = null,
        public ?int $departmentId = null,
        public ?int $holders = null,
        public ?int $minimum = null,
        public ?int $requiredLevel = null,
    ) {}
}
