<?php

namespace App\Domains\People\Performance\Data;

use App\Domains\People\Performance\Exceptions\KpiRecordException;

final readonly class TeamKpiAttribution
{
    /** @param list<string> $employeeSubjectIds */
    private function __construct(public array $employeeSubjectIds) {}

    public static function notAttributed(): self
    {
        return new self([]);
    }

    /** @param list<string> $employeeSubjectIds */
    public static function declared(array $employeeSubjectIds): self
    {
        $ids = array_map(fn (string $id): string => trim($id), $employeeSubjectIds);

        if ($ids === [] || in_array('', $ids, true) || count($ids) !== count(array_unique($ids))) {
            throw new KpiRecordException('Team KPI attribution requires unique declared employee subjects.');
        }

        return new self($ids);
    }
}
