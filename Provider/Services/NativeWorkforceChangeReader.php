<?php

namespace App\Domains\People\Provider\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Provider\Contracts\ReadsWorkforceChanges;
use App\Domains\People\Provider\Data\WorkforceChangeCursor;
use App\Domains\People\Provider\Data\WorkforceChangePage;
use App\Domains\People\Provider\Data\WorkforceChangeRequest;
use App\Domains\People\Provider\Data\WorkforceUpsert;

/**
 * Replays what changed at or after the resume instant, up to the moment this
 * read started ([1006]).
 *
 * The comparison is inclusive on purpose. A bootstrap's as-of is the instant
 * it started; rows edited during the bootstrap carry that instant or later,
 * and the bootstrap may or may not have seen them. Replaying them again costs
 * one idempotent upsert on the connector side; missing them would leave the
 * window the bootstrap cannot freeze permanently open.
 *
 * Companies and units come on the first page only, as in the bootstrap.
 * Employees are walked by ID under a watermark captured at the start, so a
 * hire during a multi-page read is not seen mid-way and is picked up by the
 * next read from this read's resume cursor. Employees are never deleted
 * from underneath the projection, so there is no employee deactivation here:
 * a leaver is an upsert with active = false. A soft-deleted company is the
 * one native record that stops existing, and is emitted as a deactivation.
 */
final class NativeWorkforceChangeReader implements ReadsWorkforceChanges
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly WorkforceBootstrapCursorCodec $cursorCodec,
        private readonly WorkforceRecordProjector $projector,
    ) {}

    public function read(WorkforceChangeRequest $request): WorkforceChangePage
    {
        $tenantId = $this->tenantContext->requireTenantId();

        if ($request->pageCursor === null) {
            $since = $this->cursorCodec->decodeResume($request->resumeCursor, $tenantId);
            $cursor = new WorkforceChangeCursor(
                $tenantId,
                $since,
                $this->projector->now(),
                0,
                $this->projector->employeeWatermark($tenantId),
            );
        } else {
            $cursor = $this->cursorCodec->decodeChangePage($request->pageCursor, $tenantId);
        }

        $firstPage = $cursor->afterEmployeeId === 0;
        $changes = [];

        if ($firstPage) {
            foreach ($this->projector->companies($tenantId, $cursor->startedAt, $cursor->since) as $company) {
                $changes[] = new WorkforceUpsert($company, $company->observedAt);
            }
            foreach ($this->projector->deletedCompanies($tenantId, $cursor->since) as $deactivation) {
                $changes[] = $deactivation;
            }
            foreach ($this->projector->organizationUnits($tenantId, $cursor->startedAt, $cursor->since) as $unit) {
                $changes[] = new WorkforceUpsert($unit, $unit->observedAt);
            }
        }

        $rows = $this->projector->employeeRows(
            $tenantId,
            $cursor->afterEmployeeId,
            $cursor->throughEmployeeId,
            $request->limit + 1,
            $cursor->since,
        );
        $hasMore = $rows->count() > $request->limit;
        $pageRows = $rows->take($request->limit)->values();

        foreach ($this->projector->projectEmployees($tenantId, $pageRows, $cursor->startedAt, $this->projector->organizationCompanies($tenantId)) as $employee) {
            $changes[] = new WorkforceUpsert($employee, $employee->observedAt);
        }

        $lastEmployeeId = $pageRows->last()?->getKey();
        $nextPageCursor = $hasMore && is_numeric($lastEmployeeId)
            ? $this->cursorCodec->encodeChangePage(new WorkforceChangeCursor(
                $tenantId,
                $cursor->since,
                $cursor->startedAt,
                (int) $lastEmployeeId,
                $cursor->throughEmployeeId,
            ))
            : null;

        return new WorkforceChangePage(
            changes: $changes,
            since: $cursor->since,
            asOf: $cursor->startedAt,
            nextPageCursor: $nextPageCursor,
            resumeCursor: $hasMore ? null : $this->cursorCodec->encodeChangeResume($tenantId, $cursor->startedAt),
            complete: ! $hasMore,
        );
    }
}
