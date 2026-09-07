<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use App\Domains\People\Training\Enums\BudgetAuditKind;
use App\Domains\People\Training\Exceptions\InvalidTrainingBudgetException;

/**
 * How a budget got to the amount it now holds.
 *
 * Append-only: the record says what the budget is, the audit says who changed
 * it, from what, to what and why. A budget that can be quietly revised upward
 * after the money is spent is not a budget.
 */
final class TrainingDepartmentBudgetAudit extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_department_budget_audits';

    protected static function booted(): void
    {
        self::updating(function (): void {
            throw new InvalidTrainingBudgetException('A training budget audit record cannot be modified.');
        });

        self::deleting(function (): void {
            throw new InvalidTrainingBudgetException('A training budget audit record cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'kind' => BudgetAuditKind::class,
            'previous_amount' => 'decimal:4',
            'overage_amount' => 'decimal:4',
            'amount' => 'decimal:4',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
