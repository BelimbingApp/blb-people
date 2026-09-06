<?php

namespace App\Domains\People\Organisation\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Organisation\Data\PositionAssignmentDraft;
use App\Domains\People\Organisation\Data\PositionVersionDraft;
use App\Domains\People\Organisation\Enums\PositionAssignmentType;
use App\Domains\People\Organisation\Exceptions\InvalidPositionDirectoryException;
use App\Domains\People\Organisation\Models\PositionAssignment;
use App\Domains\People\Organisation\Models\PositionVersion;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stable position identity, immutable position versions and effective-dated
 * assignments (0001).
 *
 * The point of separating the two is that a position and its holder change on
 * different clocks. A transfer changes who holds a position, not what the
 * position is; a restructure changes what the position is, not who holds it;
 * and a vacancy changes neither the position nor its description. Collapsing
 * them into a "current holder" is what makes a transfer, an acting spell and a
 * vacancy indistinguishable, which is exactly what JP-A02 refuses.
 */
final class PositionDirectory
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function recordVersion(int $companyEntityId, PositionVersionDraft $draft): PositionVersion
    {
        $tenantId = $this->tenantId();
        $this->assertPosition($companyEntityId, $draft->positionStableId);
        if ($draft->effectiveTo !== null && $this->day($draft->effectiveTo) < $this->day($draft->effectiveFrom)) {
            throw new InvalidPositionDirectoryException('A position version cannot end before it starts.');
        }

        return DB::transaction(function () use ($tenantId, $companyEntityId, $draft): PositionVersion {
            $clash = $this->overlapping(
                PositionVersion::query()->forCompany($tenantId, $companyEntityId)
                    ->where('position_stable_id', $draft->positionStableId),
                $draft->effectiveFrom,
                $draft->effectiveTo,
            )->exists();
            if ($clash) {
                throw new InvalidPositionDirectoryException(
                    'The position version overlaps an existing version of the same position.',
                );
            }

            return PositionVersion::query()->create([
                'tenant_id' => $tenantId,
                'company_entity_id' => $companyEntityId,
                'position_stable_id' => $draft->positionStableId,
                'version' => $draft->version,
                'title' => trim($draft->title),
                'effective_from' => $draft->effectiveFrom,
                'effective_to' => $draft->effectiveTo,
            ]);
        });
    }

    public function versionAt(int $companyEntityId, string $positionStableId, DateTimeInterface $asOf): ?PositionVersion
    {
        return $this->effectiveOn(
            PositionVersion::query()->forCompany($this->tenantId(), $companyEntityId)
                ->where('position_stable_id', $positionStableId),
            $asOf,
        )->orderByDesc('effective_from')->first();
    }

    public function assign(int $companyEntityId, PositionAssignmentDraft $draft): PositionAssignment
    {
        $tenantId = $this->tenantId();
        $this->assertPosition($companyEntityId, $draft->positionStableId);
        if ($draft->effectiveTo !== null && $this->day($draft->effectiveTo) < $this->day($draft->effectiveFrom)) {
            throw new InvalidPositionDirectoryException('An assignment cannot end before it starts.');
        }

        return DB::transaction(function () use ($tenantId, $companyEntityId, $draft): PositionAssignment {
            // Acting cover and concurrent appointments are allowed to overlap
            // anything; only the substantive holder is exclusive.
            if ($draft->type === PositionAssignmentType::Substantive) {
                $clash = $this->overlapping(
                    PositionAssignment::query()->forCompany($tenantId, $companyEntityId)
                        ->where('position_stable_id', $draft->positionStableId)
                        ->where('type', PositionAssignmentType::Substantive->value),
                    $draft->effectiveFrom,
                    $draft->effectiveTo,
                )->exists();
                if ($clash) {
                    throw new InvalidPositionDirectoryException(
                        'The position already has a substantive holder over those days; record acting or concurrent cover instead.',
                    );
                }
            }

            return PositionAssignment::query()->create([
                'tenant_id' => $tenantId,
                'company_entity_id' => $companyEntityId,
                'position_stable_id' => $draft->positionStableId,
                'employee_entity_id' => $draft->employeeEntityId,
                'type' => $draft->type,
                'effective_from' => $draft->effectiveFrom,
                'effective_to' => $draft->effectiveTo,
            ]);
        });
    }

    /** @return list<PositionAssignment> */
    public function assignmentsAt(int $companyEntityId, string $positionStableId, DateTimeInterface $asOf): array
    {
        return $this->effectiveOn(
            PositionAssignment::query()->forCompany($this->tenantId(), $companyEntityId)
                ->where('position_stable_id', $positionStableId),
            $asOf,
        )->orderBy('type')->orderBy('id')->get()->all();
    }

    /** @return list<PositionAssignment> */
    public function assignmentsForEmployee(int $companyEntityId, int $employeeEntityId, DateTimeInterface $asOf): array
    {
        return $this->effectiveOn(
            PositionAssignment::query()->forCompany($this->tenantId(), $companyEntityId)
                ->where('employee_entity_id', $employeeEntityId),
            $asOf,
        )->orderBy('type')->orderBy('id')->get()->all();
    }

    /**
     * Half-open at neither end: both bounds are inclusive days, and a null end
     * is open. Two intervals overlap when each starts on or before the other
     * ends.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    private function overlapping($query, DateTimeInterface $from, ?DateTimeInterface $to)
    {
        $fromDay = $this->day($from);

        return $query
            ->whereRaw('(effective_to is null or effective_to >= ?)', [$fromDay])
            ->when($to !== null, fn ($inner) => $inner->where('effective_from', '<=', $this->day($to)));
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    private function effectiveOn($query, DateTimeInterface $asOf)
    {
        $day = $this->day($asOf);

        return $query->where('effective_from', '<=', $day)
            ->whereRaw('(effective_to is null or effective_to >= ?)', [$day]);
    }

    private function day(DateTimeInterface $moment): string
    {
        return $moment->format('Y-m-d');
    }

    private function assertPosition(int $companyEntityId, string $positionStableId): void
    {
        $exists = PeopleReferenceEntry::query()
            ->where('company_id', $companyEntityId)
            ->where('type', PeopleReferenceEntry::TYPE_JOB_TITLE)
            ->whereKey($positionStableId)
            ->exists();
        if (! $exists) {
            throw new InvalidPositionDirectoryException('The position is not a job title in this company.');
        }
    }

    private function tenantId(): int
    {
        return $this->tenants->currentTenantId()
            ?? throw new InvalidPositionDirectoryException('A tenant context is required for the position directory.');
    }
}
