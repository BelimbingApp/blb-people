<?php

namespace App\Domains\People\Provider\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Provider\Contracts\ReadsWorkforceBootstrap;
use App\Domains\People\Provider\Data\WorkforceBootstrapCursor;
use App\Domains\People\Provider\Data\WorkforceBootstrapPage;
use App\Domains\People\Provider\Data\WorkforceBootstrapRequest;

final class NativeWorkforceBootstrapReader implements ReadsWorkforceBootstrap
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly WorkforceBootstrapCursorCodec $cursorCodec,
        private readonly WorkforceRecordProjector $projector,
    ) {}

    public function read(WorkforceBootstrapRequest $request): WorkforceBootstrapPage
    {
        $tenantId = $this->tenantContext->requireTenantId();
        if ($request->pageCursor === null) {
            $startedAt = $this->projector->now();
            $cursor = new WorkforceBootstrapCursor(
                $tenantId,
                0,
                $this->projector->employeeWatermark($tenantId),
                $startedAt,
            );
        } else {
            $cursor = $this->cursorCodec->decodePage($request->pageCursor, $tenantId);
        }

        $firstPage = $cursor->afterEmployeeId === 0;

        $rows = $this->projector->employeeRows(
            $tenantId,
            $cursor->afterEmployeeId,
            $cursor->throughEmployeeId,
            $request->limit + 1,
        );
        $hasMore = $rows->count() > $request->limit;
        $pageRows = $rows->take($request->limit)->values();
        $employees = $this->projector->projectEmployees(
            $tenantId,
            $pageRows,
            $cursor->startedAt,
            $this->projector->organizationCompanies($tenantId),
        );

        $lastEmployeeId = $pageRows->last()?->getKey();
        $nextPageCursor = $hasMore && is_numeric($lastEmployeeId)
            ? $this->cursorCodec->encodePage(new WorkforceBootstrapCursor(
                $tenantId,
                (int) $lastEmployeeId,
                $cursor->throughEmployeeId,
                $cursor->startedAt,
            ))
            : null;

        return new WorkforceBootstrapPage(
            employees: $employees,
            companies: $firstPage ? $this->projector->companies($tenantId, $cursor->startedAt) : [],
            organizationUnits: $firstPage ? $this->projector->organizationUnits($tenantId, $cursor->startedAt) : [],
            asOf: $cursor->startedAt,
            nextPageCursor: $nextPageCursor,
            resumeCursor: $hasMore ? null : $this->cursorCodec->encodeResume($tenantId, $cursor->startedAt),
            complete: ! $hasMore,
        );
    }
}
