<?php

namespace App\Domains\People\Skills\Enums;

enum HodVerification: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
