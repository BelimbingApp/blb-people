<?php

namespace App\Domains\People\Provider\Contracts;

use App\Domains\People\Provider\Data\WorkforceBootstrapPage;
use App\Domains\People\Provider\Data\WorkforceBootstrapRequest;

/**
 * People-owned projection seam used by both in-process and authenticated remote transports.
 */
interface ReadsWorkforceBootstrap
{
    public function read(WorkforceBootstrapRequest $request): WorkforceBootstrapPage;
}
