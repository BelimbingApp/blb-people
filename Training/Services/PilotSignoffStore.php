<?php

namespace App\Domains\People\Training\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Skills\Services\CompanyAttribution;
use App\Domains\People\Skills\Services\DepartmentHeads;
use App\Domains\People\Training\Enums\PilotSignoffRole;
use App\Domains\People\Training\Exceptions\InvalidPilotSignoffException;
use App\Domains\People\Training\Models\TrainingPilotSignoff;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Append-only HOD and HR pilot sign-offs against a readiness snapshot (0015-c).
 *
 * HOD may sign only when they head the unit and every readiness row is green.
 * HR may sign only after that HOD row exists. Both store the snapshot they signed.
 */
final class PilotSignoffStore
{
    public const VIEW = 'people.training.migration.view';

    public const HOD_APPROVE = 'people.training.migration.hod-approve';

    public const APPROVE = 'people.training.migration.approve';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuthorizationService $authorization,
        private readonly CompanyAttribution $companies,
        private readonly DepartmentPilotReadiness $readiness,
        private readonly DepartmentHeads $heads,
    ) {}

    public function signAsHod(
        User $actor,
        int $companyEntityId,
        int $organizationUnitEntityId,
        ?string $note = null,
    ): TrainingPilotSignoff {
        $tenantId = $this->authorize($actor, $companyEntityId, self::HOD_APPROVE);
        $rows = $this->readiness->rows($companyEntityId, $organizationUnitEntityId);
        $this->assertAllGreen($rows);
        $this->assertIsUnitHead($actor, $companyEntityId, $organizationUnitEntityId);

        return $this->append(
            $tenantId,
            $companyEntityId,
            $organizationUnitEntityId,
            PilotSignoffRole::Hod,
            $actor,
            $rows,
            $note,
            'This department already has a HOD pilot sign-off.',
        );
    }

    public function signAsHr(
        User $actor,
        int $companyEntityId,
        int $organizationUnitEntityId,
        ?string $note = null,
    ): TrainingPilotSignoff {
        $tenantId = $this->authorize($actor, $companyEntityId, self::APPROVE);

        if (! $this->hasHodSignoff($tenantId, $companyEntityId, $organizationUnitEntityId)) {
            throw new InvalidPilotSignoffException('HR cannot sign pilot readiness before the HOD has signed.');
        }

        $rows = $this->readiness->rows($companyEntityId, $organizationUnitEntityId);

        return $this->append(
            $tenantId,
            $companyEntityId,
            $organizationUnitEntityId,
            PilotSignoffRole::Hr,
            $actor,
            $rows,
            $note,
            'This department already has an HR pilot sign-off.',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function append(
        int $tenantId,
        int $companyEntityId,
        int $organizationUnitEntityId,
        PilotSignoffRole $role,
        User $actor,
        array $rows,
        ?string $note,
        string $duplicateMessage,
    ): TrainingPilotSignoff {
        if ($this->exists($tenantId, $companyEntityId, $organizationUnitEntityId, $role)) {
            throw new InvalidPilotSignoffException($duplicateMessage);
        }

        try {
            return DB::transaction(static fn (): TrainingPilotSignoff => TrainingPilotSignoff::query()->create([
                'tenant_id' => $tenantId,
                'company_entity_id' => $companyEntityId,
                'organization_unit_entity_id' => $organizationUnitEntityId,
                'role' => $role,
                'signed_by' => $actor->getKey(),
                'signed_at' => now(),
                'readiness_snapshot' => $rows,
                'note' => trim((string) $note) ?: null,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw new InvalidPilotSignoffException($duplicateMessage);
        }
    }

    /** @param  list<array{status: string}>  $rows */
    private function assertAllGreen(array $rows): void
    {
        foreach ($rows as $row) {
            if (($row['status'] ?? null) !== 'green') {
                throw new InvalidPilotSignoffException('Pilot readiness is not green; the HOD cannot sign yet.');
            }
        }
    }

    private function assertIsUnitHead(User $actor, int $companyEntityId, int $organizationUnitEntityId): void
    {
        $employeeIds = EmployeeWorkProfile::query()
            ->where('organization_unit_id', $organizationUnitEntityId)
            ->pluck('employee_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        foreach ($employeeIds as $employeeId) {
            $headUserId = $this->heads->headUserOf($companyEntityId, $employeeId);
            if ($headUserId !== null && $headUserId === (int) $actor->getKey()) {
                return;
            }
        }

        throw new InvalidPilotSignoffException('Only the head of this department can sign as HOD.');
    }

    private function hasHodSignoff(int $tenantId, int $companyEntityId, int $organizationUnitEntityId): bool
    {
        return $this->exists($tenantId, $companyEntityId, $organizationUnitEntityId, PilotSignoffRole::Hod);
    }

    private function exists(
        int $tenantId,
        int $companyEntityId,
        int $organizationUnitEntityId,
        PilotSignoffRole $role,
    ): bool {
        return TrainingPilotSignoff::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('organization_unit_entity_id', $organizationUnitEntityId)
            ->where('role', $role->value)
            ->exists();
    }

    private function authorize(User $actor, int $companyEntityId, string $capability): int
    {
        $tenantId = $this->tenants->currentTenantId()
            ?? throw new InvalidPilotSignoffException('A tenant context is required for pilot sign-off.');

        if (! $this->companies->mayActFor($actor, $companyEntityId)) {
            throw new InvalidPilotSignoffException('Pilot sign-off is unavailable in the current company scope.');
        }

        $this->authorization->authorize(Actor::forUser($actor), $capability);

        return $tenantId;
    }
}
