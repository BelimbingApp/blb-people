<?php

namespace App\Domains\People\Training\Enums;

enum TrainingPriority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
