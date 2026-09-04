<?php

namespace App\Domains\People\Provider\Contracts;

use App\Domains\People\Provider\Data\WorkforceChangePage;
use App\Domains\People\Provider\Data\WorkforceChangeRequest;

/**
 * The incremental half of the native workforce projection seam ([1006]).
 *
 * Replays every company, organization unit and employee whose facts changed
 * at or after the instant named by the resume cursor — the bootstrap's
 * captured start, or the previous incremental read's — so the window a
 * running bootstrap cannot freeze is closed by the first incremental read.
 * Like ReadsWorkforceBootstrap it requires an ambient tenant context and
 * fails closed without one.
 */
interface ReadsWorkforceChanges
{
    public function read(WorkforceChangeRequest $request): WorkforceChangePage;
}
