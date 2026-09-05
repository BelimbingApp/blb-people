<?php

namespace App\Domains\People\Training\Enums;

enum TrainingDeliveryApproach: string
{
    case InHouse = 'in_house';
    case External = 'external';
    case Mixed = 'mixed';
}
