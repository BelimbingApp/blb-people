<?php

namespace App\Domains\People\Training\Services;

use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Skills\Services\CompanyAttribution;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Enums\TrainingEventStatus;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use Illuminate\Database\Eloquent\Builder;

/** Deep HR/company and HOD/department boundary for training records. */
final class TrainingAudience
{
    public const VIEW = 'people.training.event.view';

    public const MANAGE = 'people.training.event.manage';

    public const CALENDAR_VIEW = 'people.training.calendar.view';

    public const EXPORT = 'people.training.participation.export';

    public function __construct(
        private readonly SkillAudience $skills,
        private readonly CompanyAttribution $companies,
        private readonly TenantContext $tenantContext,
    ) {}

    /** @return array<int, string> */
    public function allowedCompanies(User $user): array
    {
        return $this->skills->allowedCompanies($user, self::VIEW);
    }

    /** @return array<int, string> */
    public function allowedCalendarCompanies(User $user): array
    {
        return $this->skills->allowedCompanies($user, self::CALENDAR_VIEW);
    }

    public function canManage(User $user, int $companyEntityId): bool
    {
        try {
            $audiences = $this->skills->authorizeAudience($user, self::MANAGE);
        } catch (AuthorizationDeniedException) {
            return false;
        }

        return in_array(SkillAudience::HR, $audiences, true)
            && $this->companies->mayActFor($user, $companyEntityId);
    }

    public function authorizeManage(User $user, int $companyEntityId): void
    {
        if (! $this->canManage($user, $companyEntityId)) {
            $this->deny();
        }
    }

    /**
     * Whether the user may download an event's attendance register (0011-f):
     * the export capability with the HR audience, for a company they may
     * act for. A trainer assigned to the event holds neither.
     */
    public function canExport(User $user, int $companyEntityId): bool
    {
        try {
            $audiences = $this->skills->authorizeAudience($user, self::EXPORT);
        } catch (AuthorizationDeniedException) {
            return false;
        }

        return in_array(SkillAudience::HR, $audiences, true)
            && $this->companies->mayActFor($user, $companyEntityId);
    }

    public function authorizeExport(User $user, int $companyEntityId): void
    {
        if (! $this->canExport($user, $companyEntityId)) {
            $this->deny();
        }
    }

    public function visibleEvents(User $user, int $companyEntityId): Builder
    {
        $audiences = $this->skills->authorizeAudience($user, self::VIEW);
        if (! $this->companies->mayActFor($user, $companyEntityId)) {
            $this->deny();
        }

        $query = TrainingEvent::query()
            ->forCompany($this->tenantContext->requireTenantId(), $companyEntityId);

        if (in_array(SkillAudience::HR, $audiences, true)) {
            return $query;
        }

        if (in_array(SkillAudience::HOD, $audiences, true)) {
            $departments = $this->skills->visibleOrganizationUnitEntityIds($user, $companyEntityId, self::VIEW);

            // A NULL target is deliberately company-wide, so every attributed
            // HOD in the company sees it alongside events for departments they head.
            if ($departments === []) {
                return $query->whereNull('target_department_entity_id');
            }

            $parameters = implode(', ', array_fill(0, count($departments), '?'));

            return $query->whereRaw(
                "(target_department_entity_id is null or target_department_entity_id in ($parameters))",
                $departments,
            );
        }

        $this->deny();
    }

    /**
     * Events the user may see on the training calendar: HR sees the open
     * register; HODs see their departments; anyone else with the calendar
     * grant sees company-wide events, events they teach, and events they
     * are enrolled in. Enrolment is authorized against this same seam, so
     * the calendar never offers an event the store would refuse.
     */
    public function visibleCalendarEvents(User $user, int $companyEntityId): Builder
    {
        $audiences = $this->skills->authorizeAudience($user, self::CALENDAR_VIEW);
        if (! $this->companies->mayActFor($user, $companyEntityId)) {
            $this->deny();
        }

        $tenant = $this->tenantContext->requireTenantId();
        $open = TrainingEvent::query()
            ->forCompany($tenant, $companyEntityId)
            ->whereIn('status', [TrainingEventStatus::Scheduled, TrainingEventStatus::InProgress]);

        if (in_array(SkillAudience::HR, $audiences, true)) {
            return $open;
        }

        // One AND-only query per disjunct, merged in PHP: the company-scope
        // guard disqualifies any predicate tree containing an orWhere, so
        // the union is computed here rather than in SQL.
        $ids = [];
        if (in_array(SkillAudience::HOD, $audiences, true)) {
            $departments = $this->skills->visibleOrganizationUnitEntityIds($user, $companyEntityId, self::CALENDAR_VIEW);
            $ids = [...$ids, ...(clone $open)->whereNull('target_department_entity_id')->pluck('id')->map(intval(...))->all()];
            if ($departments !== []) {
                $ids = [...$ids, ...(clone $open)->whereIn('target_department_entity_id', $departments)->pluck('id')->map(intval(...))->all()];
            }
        } else {
            $ids = [...$ids, ...(clone $open)->whereNull('target_department_entity_id')->pluck('id')->map(intval(...))->all()];
        }

        $bound = $this->skills->boundEmployeeEntityId($user, $companyEntityId);
        if ($bound !== null) {
            $ids = [...$ids, ...(clone $open)->where('organizer_employee_entity_id', $bound)->pluck('id')->map(intval(...))->all()];
            $ids = [...$ids, ...(clone $open)->where('internal_trainer_employee_entity_id', $bound)->pluck('id')->map(intval(...))->all()];
            $ids = [...$ids, ...TrainingParticipant::query()
                ->forCompany($tenant, $companyEntityId)
                ->where('provider_id', ExternalReference::PROVIDER_ID)
                ->where('employee_subject_id', (string) $bound)
                ->whereNull('withdrawn_at')
                ->pluck('event_id')->map(intval(...))->all()];
        }

        return TrainingEvent::query()
            ->forCompany($tenant, $companyEntityId)
            ->whereIn('id', array_values(array_unique($ids)));
    }

    private function deny(): never
    {
        throw new AuthorizationDeniedException(AuthorizationDecision::deny(
            AuthorizationReasonCode::DENIED_MISSING_CAPABILITY,
            ['people_connector_training_audience'],
        ));
    }
}
