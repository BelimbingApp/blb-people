<?php

namespace App\Domains\People\Training\Enums;

/** Workflows a cutover hands from the legacy portal to People one at a time (0015-f). */
enum CutoverWorkflow: string
{
    case TrainingRequests = 'training_requests';
    case Effectiveness = 'effectiveness';
    case Attendance = 'attendance';

    public function label(): string
    {
        return match ($this) {
            self::TrainingRequests => 'Training requests',
            self::Effectiveness => 'Effectiveness',
            self::Attendance => 'Attendance',
        };
    }
}
