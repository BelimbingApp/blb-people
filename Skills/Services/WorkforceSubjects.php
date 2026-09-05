<?php

namespace App\Domains\People\Skills\Services;

use App\Domains\People\Provider\Contracts\ResolvesWorkforceSubjects;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use Illuminate\Database\Eloquent\Model;

/**
 * Skills' narrow adapter to the People-owned workforce-subject seam.
 */
final class WorkforceSubjects
{
    public function __construct(private readonly ResolvesWorkforceSubjects $resolver) {}

    public function resolve(
        int $tenantId,
        int $companyId,
        WorkforceResourceType $type,
        int $stableId,
    ): ?Model {
        return $this->resolver->resolve(new WorkforceSubject(
            tenantId: $tenantId,
            companyId: $companyId,
            type: $type,
            stableId: (string) $stableId,
        ))->record;
    }
}
