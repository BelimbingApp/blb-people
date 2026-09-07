<?php

namespace App\Domains\People\Training\Services;

use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\PeopleConnector\Connector\Contracts\ExportsSupplementalSubjectRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Training rows for one workforce subject on the connector data-subject export
 * (#410 / connector #308). Tagged by Training's service provider; the connector
 * never names this class.
 *
 * Participant rows key the subject as `employee_subject_id` (provider stable
 * id string). Related participation, evidence, evaluation and passport rows
 * hang off those participants or carry the same subject id. Restorable is
 * false: Training owns restore; the connector lists the block as not_restored.
 */
final class TrainingSubjectExporter implements ExportsSupplementalSubjectRecords
{
    public function name(): string
    {
        return 'people.training';
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

        $subjectId = $subject->stableId;
        $sections = [];

        if (! Schema::hasTable('people_training_participants')) {
            return [];
        }

        $participants = DB::table('people_training_participants')
            ->where('tenant_id', $tenantId)
            ->where('company_entity_id', $companyEntityId)
            ->where('employee_subject_id', $subjectId)
            ->orderBy('id')
            ->get();
        if ($participants->isEmpty()) {
            return [];
        }

        $sections['people_training_participants'] = $participants->map(static fn (object $row): array => (array) $row)->all();
        $participantIds = $participants->pluck('id')->all();

        $this->appendByParticipantIds($sections, 'people_training_participation_facts', $tenantId, $companyEntityId, $participantIds);
        $this->appendByParticipantIds($sections, 'people_training_evidence_submissions', $tenantId, $companyEntityId, $participantIds);
        $this->appendByParticipantIds($sections, 'people_training_evidence_decisions', $tenantId, $companyEntityId, $participantIds);
        $this->appendByParticipantIds($sections, 'people_training_effectiveness_reviews', $tenantId, $companyEntityId, $participantIds);
        $this->appendByParticipantIds($sections, 'people_training_effectiveness_answers', $tenantId, $companyEntityId, $participantIds);
        $this->appendByParticipantIds($sections, 'people_training_effectiveness_reminders', $tenantId, $companyEntityId, $participantIds);

        if (Schema::hasTable('people_training_evaluations')) {
            $rows = DB::table('people_training_evaluations')
                ->where('tenant_id', $tenantId)
                ->where('company_entity_id', $companyEntityId)
                ->where('employee_subject_id', $subjectId)
                ->orderBy('id')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
            if ($rows !== []) {
                $sections['people_training_evaluations'] = $rows;
            }
        }

        // Passport documents key the platform employee entity id (numeric
        // stable id), not the provider subject string participants use.
        if (Schema::hasTable('people_training_passport_documents') && ctype_digit($subjectId)) {
            $employeeEntityId = (int) $subjectId;
            $rows = DB::table('people_training_passport_documents')
                ->where('tenant_id', $tenantId)
                ->where('company_entity_id', $companyEntityId)
                ->where('employee_entity_id', $employeeEntityId)
                ->orderBy('id')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
            if ($rows !== []) {
                $sections['people_training_passport_documents'] = $rows;
                if (Schema::hasTable('people_training_passport_document_audits')) {
                    $docIds = array_column($rows, 'id');
                    $audits = DB::table('people_training_passport_document_audits')
                        ->where('tenant_id', $tenantId)
                        ->where('company_entity_id', $companyEntityId)
                        ->whereIn('document_id', $docIds)
                        ->orderBy('id')
                        ->get()
                        ->map(static fn (object $row): array => (array) $row)
                        ->all();
                    if ($audits !== []) {
                        $sections['people_training_passport_document_audits'] = $audits;
                    }
                }
            }
        }

        ksort($sections);

        return $sections;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $sections
     * @param  list<int|string>  $participantIds
     */
    private function appendByParticipantIds(array &$sections, string $table, int $tenantId, int $companyEntityId, array $participantIds): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'participant_id')) {
            return;
        }

        $query = DB::table($table)->where('tenant_id', $tenantId)->whereIn('participant_id', $participantIds);
        if (Schema::hasColumn($table, 'company_entity_id')) {
            $query->where('company_entity_id', $companyEntityId);
        }

        $rows = $query->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        if ($rows !== []) {
            $sections[$table] = $rows;
        }
    }
}
