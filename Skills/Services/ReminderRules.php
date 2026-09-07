<?php

namespace App\Domains\People\Skills\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Skills\Data\DueReminder;
use App\Domains\People\Skills\Enums\DevelopmentActionClosure;
use App\Domains\People\Skills\Enums\DevelopmentActionStatus;
use App\Domains\People\Skills\Enums\ReminderRule;
use App\Domains\People\Skills\Models\DevelopmentAction;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\SkillAssessment;

/**
 * What is due for one company, and nothing else.
 *
 * Lists; does not send. The scope is a company rather than a tenant on purpose:
 * a reminder naming somebody else's employee is a disclosure, not a nuisance,
 * and the cheapest way to never do that is to never load the rows.
 */
final class ReminderRules
{
    public const DEFAULT_EXPIRING_WITHIN_DAYS = 30;

    public function __construct(private readonly TenantContext $tenantContext) {}

    /** @return list<DueReminder> */
    public function due(
        int $companyEntityId,
        ?\DateTimeImmutable $asOf = null,
        int $expiringWithinDays = self::DEFAULT_EXPIRING_WITHIN_DAYS,
    ): array {
        $tenantId = $this->tenantContext->requireTenantId();
        $asOf ??= new \DateTimeImmutable;

        if ($expiringWithinDays < 0) {
            throw new \InvalidArgumentException('A reminder window cannot look backwards; use a positive number of days.');
        }

        $horizon = $asOf->modify("+{$expiringWithinDays} days");

        // One query per rule rather than one with an OR. RequireCompanyScope
        // refuses an OR at any depth before it will look for the company pin —
        // deliberately, because an OR can admit a row the pin excludes — and
        // two queries say what these are anyway: two rules, not one condition
        // with a seam in it.
        $overdue = EmployeeSkillScore::query()
            ->forCompany($tenantId, $companyEntityId)
            ->whereNotNull('next_assessment_due')
            ->whereDate('next_assessment_due', '<=', $asOf->format('Y-m-d'))
            ->orderBy('id')
            ->get();

        // A lapsed certificate is still listed. "Already expired" is not "no
        // longer expiring", and dropping it would stop reminding about exactly
        // the certificates most in need of it.
        $expiring = EmployeeSkillScore::query()
            ->forCompany($tenantId, $companyEntityId)
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<=', $horizon->format('Y-m-d'))
            ->orderBy('id')
            ->get();

        // An overdue action is one the monthly review must confront: open by the
        // workbook's Open Actions definition, past its owner-accountable date.
        // A proposal nobody approved and held work nobody is pursuing are not
        // overdue — they are waiting — so status excludes them even when their
        // closure is still open.
        $actions = DevelopmentAction::query()
            ->forCompany($tenantId, $companyEntityId)
            ->whereIn('closure_status', [
                DevelopmentActionClosure::Open->value,
                DevelopmentActionClosure::PendingReassessment->value,
                DevelopmentActionClosure::FurtherActionRequired->value,
            ])
            ->whereNotIn('status', [
                DevelopmentActionStatus::Proposed->value,
                DevelopmentActionStatus::OnHold->value,
            ])
            ->whereDate('due_date', '<', $asOf->format('Y-m-d'))
            ->orderBy('id')
            ->get();

        $assessments = SkillAssessment::query()
            ->forCompany($tenantId, $companyEntityId)
            ->whereKey($actions->map(fn (DevelopmentAction $action): ?int => $action->source_assessment_id)->filter()->unique()->values()->all())
            ->get()
            ->keyBy(fn (SkillAssessment $assessment): int => (int) $assessment->getKey());

        $reminders = [];

        foreach ($overdue as $score) {
            $reminders[] = self::reminder($score, ReminderRule::OverdueReassessment, $score->next_assessment_due->toDateString());
        }

        foreach ($expiring as $score) {
            $reminders[] = self::reminder($score, ReminderRule::ExpiringCertificate, $score->valid_until->toDateString());
        }

        foreach ($actions as $action) {
            $reminders[] = self::actionReminder($action, $assessments->get((int) $action->source_assessment_id));
        }

        return $reminders;
    }

    private static function reminder(EmployeeSkillScore $score, ReminderRule $rule, string $dueOn): DueReminder
    {
        return new DueReminder(
            companyEntityId: (int) $score->company_entity_id,
            employeeEntityId: (int) $score->employee_entity_id,
            skillId: (int) $score->skill_id,
            rule: $rule,
            dueOn: new \DateTimeImmutable($dueOn),
            requirementReference: (string) $score->requirement_reference,
            requirementVersion: (int) $score->requirement_version,
        );
    }

    private static function actionReminder(DevelopmentAction $action, ?SkillAssessment $assessment): DueReminder
    {
        return new DueReminder(
            companyEntityId: (int) $action->company_entity_id,
            employeeEntityId: (int) $action->employee_entity_id,
            skillId: (int) $action->skill_id,
            rule: ReminderRule::OverdueDevelopmentAction,
            dueOn: new \DateTimeImmutable($action->due_date->toDateString()),
            requirementReference: $assessment === null
                ? (string) $action->action_key
                : (string) $assessment->requirement_reference,
            // A manual action is measured against no versioned requirement; 1
            // marks the unversioned baseline the record type requires.
            requirementVersion: $assessment === null ? 1 : (int) $assessment->requirement_version,
            developmentActionId: (int) $action->getKey(),
            ownerEmployeeEntityId: (int) $action->owner_employee_entity_id,
        );
    }
}
