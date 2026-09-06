<?php

namespace App\Domains\People\Training\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Models\TrainingEvaluation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

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

    /**
     * The participant's written words, which a departmental audience does not
     * get by hierarchy alone.
     *
     * The contract's HOD boundary is explicit: "No unrestricted free text,
     * unrelated employees/companies, private evidence, HR follow-up or export
     * solely by hierarchy." Ratings and completion state are departmental
     * management information; what someone wrote about their training is not.
     */
    private const FREE_TEXT_COLUMNS = [
        'most_useful_learning',
        'application_commitment',
        'support_needed',
        'recommendation',
        'issues_or_improvements',
        'notes',
    ];

    public function visibleTo(User $user, int $companyEntityId): Builder
    {
        $audiences = $this->audience->authorizeAudience($user, self::VIEW_CAPABILITY);
        $employeeEntityIds = $this->audience->visibleEmployeeEntityIdsFor(
            $user,
            $companyEntityId,
            self::VIEW_CAPABILITY,
        );

        $query = TrainingEvaluation::query()
            ->forCompany($this->tenantContext->requireTenantId(), $companyEntityId);

        // Redacted by not selecting, rather than by hiding after loading. A
        // column the query never asks for cannot be leaked later by a caller
        // reading the model, serialising it, or logging it.
        if (self::redactsFreeText($audiences)) {
            $query->select(array_values(array_diff(
                Schema::getColumnListing((new TrainingEvaluation)->getTable()),
                self::FREE_TEXT_COLUMNS,
            )));
        }

        // An empty scope needs no special case: whereIn on an empty list
        // compiles to a false predicate, so a reader with no audience matches
        // nothing rather than falling through unfiltered.
        return $query->whereIn('employee_subject_id', array_map('strval', $employeeEntityIds));
    }

    /**
     * HR holds training-governance access and a participant wrote their own
     * answers; both keep the free text. The restriction belongs to the
     * departmental audience alone, so somebody who is both HOD and HR is not
     * redacted by the lesser of their two audiences.
     *
     * @param  array<int, string>  $audiences
     */
    private static function redactsFreeText(array $audiences): bool
    {
        return in_array(SkillAudience::HOD, $audiences, true)
            && ! in_array(SkillAudience::HR, $audiences, true)
            && ! in_array(SkillAudience::EMPLOYEE, $audiences, true);
    }
}
