<?php

namespace App\Domains\People\Organisation\Services;

use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\PeopleConnector\Connector\Contracts\ExportsSupplementalSubjectRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Position assignment rows for one workforce subject on the connector
 * data-subject export (#412). Lives in Organisation (owner of
 * `people_position_assignments`); package section name is `people.positions`
 * so the export vocabulary matches the issue's Positions surface.
 *
 * Position version catalog rows are omitted — they describe the role, not one
 * person. Restorable is false: Organisation owns restore; the connector lists
 * the block as not_restored.
 */
final class PositionSubjectExporter implements ExportsSupplementalSubjectRecords
{
    /** @var list<string> */
    public const EMPLOYEE_ENTITY_TABLES = [
        'people_position_assignments',
    ];

    /** @var list<string> */
    public const DELIBERATE_EMPLOYEE_ENTITY_EXCLUSIONS = [];

    public function name(): string
    {
        return 'people.positions';
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
