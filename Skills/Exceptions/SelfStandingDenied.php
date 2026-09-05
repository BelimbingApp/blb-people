<?php

namespace App\Domains\People\Skills\Exceptions;

use App\Domains\People\Skills\Enums\SelfStandingRefusal;

final class SelfStandingDenied extends \DomainException
{
    public function __construct(public readonly SelfStandingRefusal $reason)
    {
        parent::__construct('The requested self record cannot be read.');
    }
}
