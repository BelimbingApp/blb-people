<?php

namespace App\Domains\People\Skills\Services;

use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\PeopleConnector\Connector\Contracts\ExportsSupplementalSubjectRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Skills rows for one workforce subject on the connector data-subject export
 * (#410 / connector #308). The connector tags this class; it never names us.
 *
 * Keys match the connector's vocabulary: tenant id, owning company entity id,
 * and subject stable id as the employee entity id Skills stores. Catalog
 * tables are omitted — they are not about one person. Audience / delivery
 * ledgers with employee_entity_id (portal bindings, assessor roster, reminder
 * ticks) are deliberately excluded from the DSAR payload; they are named in
 * DELIBERATE_EMPLOYEE_ENTITY_EXCLUSIONS so a coverage ratchet cannot forget
 * them. Restorable is false: Skills owns restore; the connector records the
 * block as not_restored.
 */
final class SkillsSubjectExporter implements ExportsSupplementalSubjectRecords
{
    /** @var list<string> subject-keyed tables with employee_entity_id */
    public const EMPLOYEE_ENTITY_TABLES = [
        'people_connector_skill_assessments',
        'people_connector_skill_employee_scores',
        'people_connector_skill_reassessment_requests',
        'people_connector_skill_development_actions',
        'people_connector_skill_assessment_decisions',
        // A certificate is the subject's own qualification evidence -- issuer,
        // reference, validity, evidence link -- so it belongs in the payload
        // rather than in the deliberate exclusions beneath. The table carries
        // id/tenant_id/company_entity_id/employee_entity_id, so the generic
        // loop below exports it with no special case.
        'people_connector_skill_certifications',
    ];

    /**
     * Audience / delivery ledgers with employee_entity_id that #410 left out of
     * the DSAR payload on purpose (portal bindings, assessor roster, reminder
     * delivery ticks). Named so #412's coverage ratchet cannot silently forget them.
     *
     * @var list<string>
     */
    public const DELIBERATE_EMPLOYEE_ENTITY_EXCLUSIONS = [
        'people_connector_skill_actor_bindings',
        'people_connector_skill_assessor_assignments',
        'people_connector_skill_reminder_deliveries',
    ];

    public function name(): string
    {
        return 'people.skills';
    }

    public function restorable(): bool
    {
        return false;
    }

    public function sections(WorkforceSubject $subject, int $tenantId, int $companyEntityId): array
    {
        if ($subject->tenantId !== null && $subject->tenantId !== $tenantId) {
            return [];
        }
        if ($subject->companyId !== null && $subject->companyId !== $companyEntityId) {
            return [];
        }
        if (! ctype_digit($subject->stableId)) {
            return [];
        }

        $employeeEntityId = (int) $subject->stableId;
        $sections = [];

        foreach (self::EMPLOYEE_ENTITY_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $rows = DB::table($table)
                ->where('tenant_id', $tenantId)
                ->where('company_entity_id', $companyEntityId)
                ->where('employee_entity_id', $employeeEntityId)
                ->orderBy('id')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
            if ($rows !== []) {
                $sections[$table] = $rows;
            }
        }

        if (Schema::hasTable('people_connector_skill_development_action_events') && isset($sections['people_connector_skill_development_actions'])) {
            $actionIds = array_column($sections['people_connector_skill_development_actions'], 'id');
            $rows = DB::table('people_connector_skill_development_action_events')
                ->where('tenant_id', $tenantId)
                ->where('company_entity_id', $companyEntityId)
                ->whereIn('development_action_id', $actionIds)
                ->orderBy('id')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
            if ($rows !== []) {
                $sections['people_connector_skill_development_action_events'] = $rows;
            }
        }

        ksort($sections);

        return $sections;
    }
}
