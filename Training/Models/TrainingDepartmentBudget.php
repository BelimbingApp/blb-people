<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** What one department was given for one year. */
final class TrainingDepartmentBudget extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_department_budgets';

    public function audits(): HasMany
    {
        return $this->hasMany(TrainingDepartmentBudgetAudit::class, 'training_department_budget_id')
            ->forCompany((int) $this->tenant_id, (int) $this->company_entity_id)->orderBy('id');
    }

    protected function casts(): array
    {
        return ['budget_year' => 'integer', 'amount' => 'decimal:4'];
    }
}
