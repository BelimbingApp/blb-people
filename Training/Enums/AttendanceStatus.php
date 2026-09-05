<?php

namespace App\Domains\People\Training\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Cancelled = 'cancelled';
}
