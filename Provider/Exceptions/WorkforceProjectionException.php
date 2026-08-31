<?php

namespace App\Domains\People\Provider\Exceptions;

use App\Base\Foundation\Exceptions\BlbDataContractException;

final class WorkforceProjectionException extends BlbDataContractException
{
    public static function organizationUnitWithoutName(int $departmentId): self
    {
        return new self(
            "Department [{$departmentId}] cannot be projected without a department type name.",
            context: ['department_id' => $departmentId],
        );
    }
}
