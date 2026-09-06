<?php

namespace App\Domains\People\Training\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Services\CompanyAttribution;
use App\Domains\People\Training\Data\DepartmentTrainingSpend;
use App\Domains\People\Training\Enums\TrainingRequestStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingBudgetException;
use App\Domains\People\Training\Models\TrainingDepartmentBudget;
use App\Domains\People\Training\Models\TrainingDepartmentBudgetAudit;
use App\Domains\People\Training\Models\TrainingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What each department has committed for a year, against what it was given.
 *
 * Every amount is a decimal string with four places and every total is built
 * with bcadd. Float addition is not used anywhere here on purpose: SQLite and
 * PostgreSQL do not agree about what SUM() over a decimal column returns, and
 * a budget page that is a cent out is a budget page nobody trusts.
 */
final class TrainingBudgetStore
{
    public const VIEW = 'people.training.budget.view';

    public const MANAGE = 'people.training.budget.manage';

    private const SCALE = 4;

    /**
     * Asked for but not yet decided. A draft is deliberately absent: nobody
     * has been asked for the money yet, so counting it would inflate the
     * department's demand with things its own staff may never submit.
     */
    private const PENDING = [
        TrainingRequestStatus::PendingHod,
        TrainingRequestStatus::PendingHr,
        TrainingRequestStatus::PendingApproval,
    ];

    public function __construct(
        private TenantContext $tenants,
        private AuthorizationService $authorization,
        private CompanyAttribution $companies,
    ) {}

    /**
     * One row per department that has either a budget or a request this year.
     *
     * @return list<DepartmentTrainingSpend>
     */
    public function rollUp(User $actor, int $companyEntityId, int $year): array
    {
        $tenantId = $this->authorize($actor, $companyEntityId, self::VIEW);

        $requests = TrainingRequest::query()->forCompany($tenantId, $companyEntityId)
            ->whereYear('created_at', $year)
            ->get();

        $budgets = TrainingDepartmentBudget::query()->forCompany($tenantId, $companyEntityId)
            ->where('budget_year', $year)
            ->get()
            ->keyBy(static fn (TrainingDepartmentBudget $budget): int => (int) $budget->department_entity_id);

        $departmentIds = $requests
            ->map(static fn (TrainingRequest $request): int => (int) $request->department_subject_id)
            ->merge($budgets->keys())
            ->unique()
            ->sort()
            ->values();

        $names = PeopleReferenceEntry::query()
            ->where('company_id', $companyEntityId)
            ->whereIn('id', $departmentIds)
            ->pluck('name', 'id');

        $rows = [];

        foreach ($departmentIds as $departmentId) {
            $mine = $requests->filter(
                static fn (TrainingRequest $request): bool => (int) $request->department_subject_id === $departmentId,
            );
            $approved = $this->total($mine->filter(
                static fn (TrainingRequest $request): bool => $request->status === TrainingRequestStatus::Approved,
            ));
            $pending = $this->total($mine->filter(
                static fn (TrainingRequest $request): bool => in_array($request->status, self::PENDING, true),
            ));
            $budget = $budgets->get($departmentId);
            $amount = $budget === null ? null : (string) $budget->amount;

            $rows[] = new DepartmentTrainingSpend(
                departmentEntityId: $departmentId,
                departmentName: (string) ($names[$departmentId] ?? 'Unknown department'),
                approved: $approved,
                pending: $pending,
                budget: $amount,
                // Pending is shown but not deducted: it is not committed until
                // somebody approves it.
                remaining: $amount === null ? null : bcsub($amount, $approved, self::SCALE),
            );
        }

        return $rows;
    }

    /**
     * Set this year's amount for one department, recording how it changed.
     *
     * The budget row carries the amount now; the audit carries every step that
     * got it there, including the first allocation, whose previous amount is
     * null because there was none.
     */
    public function setBudget(
        User $actor,
        int $companyEntityId,
        int $departmentEntityId,
        int $year,
        string $amount,
        string $reason,
    ): TrainingDepartmentBudget {
        $tenantId = $this->authorize($actor, $companyEntityId, self::MANAGE);

        if (trim($reason) === '') {
            throw new InvalidTrainingBudgetException('Changing a training budget needs a stated reason.');
        }
        if (bccomp($amount, '0', self::SCALE) < 0) {
            throw new InvalidTrainingBudgetException('A training budget cannot be negative.');
        }
        $this->assertDepartment($companyEntityId, $departmentEntityId);

        return DB::transaction(function () use (
            $tenantId, $companyEntityId, $departmentEntityId, $year, $amount, $reason, $actor
        ): TrainingDepartmentBudget {
            $scaled = bcadd($amount, '0', self::SCALE);
            $budget = TrainingDepartmentBudget::query()->forCompany($tenantId, $companyEntityId)
                ->where('department_entity_id', $departmentEntityId)
                ->where('budget_year', $year)
                ->first();
            $previous = $budget === null ? null : (string) $budget->amount;

            if ($budget === null) {
                $budget = TrainingDepartmentBudget::query()->create([
                    'tenant_id' => $tenantId, 'company_entity_id' => $companyEntityId,
                    'department_entity_id' => $departmentEntityId, 'budget_year' => $year,
                    'amount' => $scaled, 'set_by_user_id' => $actor->getKey(),
                ]);
            } else {
                $budget->update(['amount' => $scaled, 'set_by_user_id' => $actor->getKey()]);
            }

            TrainingDepartmentBudgetAudit::query()->create([
                'tenant_id' => $tenantId, 'company_entity_id' => $companyEntityId,
                'training_department_budget_id' => $budget->id,
                'previous_amount' => $previous, 'amount' => $scaled,
                'reason' => trim($reason), 'actor_user_id' => $actor->getKey(),
                'occurred_at' => now(),
            ]);

            return $budget->refresh();
        });
    }

    /** @param  Collection<int, TrainingRequest>  $requests */
    private function total(Collection $requests): string
    {
        return $requests->reduce(
            // An unpriced request contributes nothing, said explicitly rather
            // than left to bcadd's reading of an empty string. It is still
            // listed: a department whose requests nobody has costed is exactly
            // the one HR wants to see, so it is not filtered out of the query.
            static fn (string $carry, TrainingRequest $request): string => bcadd(
                $carry, $request->estimated_cost ?? '0', self::SCALE,
            ),
            bcadd('0', '0', self::SCALE),
        );
    }

    private function assertDepartment(int $companyEntityId, int $departmentEntityId): void
    {
        $exists = PeopleReferenceEntry::query()
            ->where('company_id', $companyEntityId)
            ->where('type', PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT)
            ->whereKey($departmentEntityId)
            ->exists();

        if (! $exists) {
            throw new InvalidTrainingBudgetException('The department does not belong to this company.');
        }
    }

    private function authorize(User $actor, int $companyEntityId, string $capability): int
    {
        $tenantId = $this->tenants->currentTenantId()
            ?? throw new InvalidTrainingBudgetException('A tenant context is required for training budgets.');

        if (! $this->companies->mayActFor($actor, $companyEntityId)) {
            throw new InvalidTrainingBudgetException('The training budget is unavailable in the current company scope.');
        }
        $this->authorization->authorize(Actor::forUser($actor), $capability);

        return $tenantId;
    }
}
