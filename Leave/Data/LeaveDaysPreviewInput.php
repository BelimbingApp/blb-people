<?php

namespace App\Domains\People\Leave\Data;

use App\Core\Employee\Models\Employee;
use App\Domains\People\Leave\Models\LeaveRequest;
use App\Domains\People\Leave\Models\LeaveRequestPolicy;
use DateTimeImmutable;

final readonly class LeaveDaysPreviewInput
{
    public function __construct(
        public Employee $employee,
        public DateTimeImmutable $startsOn,
        public DateTimeImmutable $endsOn,
        public LeaveRequestPolicy $policy,
        public string $unit = LeaveRequest::UNIT_DAY,
        public ?LeaveDaysPreviewOptions $options = null,
    ) {}
}
