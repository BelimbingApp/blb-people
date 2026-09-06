<?php

namespace App\Domains\People\Training\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Models\TrainingEvaluation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which participant evaluations a reader may see.
 *
 * Scoping comes from SkillAudience rather than a second policy engine — plan
 * 0008 asks for exactly one — but the capability is Training's own, so Skills
 * never learns about another module's permissions in order to scope these rows.
 *
 * There is deliberately no trainer branch. docs/contracts/training-evaluation.md
 * defines no automatic evaluation audience for that role and says teaching an
 * event is insufficient; the record rates trainer effectiveness and carries
 * free text about the session. A trainer who was also a participant still sees
 * their own evaluation, because that is the participant audience, not a
 * trainer one — the distinction the contract is drawing.
 */
final class TrainingEvaluationReader
{
    public const VIEW_CAPABILITY = 'people.training.evaluation.view';

    public function __construct(
        private readonly SkillAudience $audience,
        private readonly TenantContext $tenantContext,
    ) {}

    public function visibleTo(User $user, int $companyEntityId): Builder
    {
        $employeeEntityIds = $this->audience->visibleEmployeeEntityIdsFor(
            $user,
            $companyEntityId,
            TrainingEvaluationReader::VIEW_CAPABILITY,
        );

        $query = TrainingEvaluation::query()
            ->forCompany($this->tenantContext->requireTenantId(), $companyEntityId);

        // No audience is not "everything". An empty scope means this reader may
        // see nobody's evaluation, and the query has to say so rather than
        // fall through unfiltered.
        if ($employeeEntityIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('employee_subject_id', array_map('strval', $employeeEntityIds));
    }
}
