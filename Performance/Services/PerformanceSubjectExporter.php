<?php

namespace App\Domains\People\Performance\Services;

use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\PeopleConnector\Connector\Contracts\ExportsSupplementalSubjectRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Performance rows for one workforce subject on the connector data-subject
 * export (#412). Tagged by Performance's service provider; the connector never
 * names this class.
 *
 * Reviews and observations key `employee_entity_id` as the person the record
 * is about. Review responses key `employee_entity_id` as the respondent (see
 * PerformanceReviewStore::recordEmployeeResponse): a subject's package carries
 * responses they authored, including on somebody else's review. Responses
 * others wrote on that subject's review travel with those authors' packages;
 * the review and observation rows about the subject still travel here.
 *
 * Restorable is false: Performance owns restore; the connector lists the block
 * as not_restored.
 */
final class PerformanceSubjectExporter implements ExportsSupplementalSubjectRecords
{
    /** @var list<string> */
    public const EMPLOYEE_ENTITY_TABLES = [
        'people_performance_reviews',
        'people_performance_observations',
        'people_performance_review_responses',
    ];

    /** @var list<string> */
    public const DELIBERATE_EMPLOYEE_ENTITY_EXCLUSIONS = [];

    public function name(): string
    {
        return 'people.performance';
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

        ksort($sections);

        return $sections;
    }
}
