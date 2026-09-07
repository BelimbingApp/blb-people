<?php

namespace App\Domains\People\Skills\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Domains\People\Skills\Data\AssessmentLogDryRunResult;
use App\Domains\People\Skills\Enums\AssessmentStatus;
use App\Domains\People\Skills\Exceptions\InvalidAssessmentException;
use App\Domains\People\Skills\Import\SkillWorkbookReader;
use App\Domains\People\Skills\Import\UnreadableSkillWorkbook;
use App\Domains\People\Skills\Import\WorkbookDefect;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillAssessment;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * Tells HR what importing 04 Assessment Log would do, before anything is
 * written. Staff IDs resolve through the workforce seam for the named company
 * only: a sibling company's employee is a cross-company defect, another
 * tenant's employees are never consulted. Every defect is a reason code and a
 * cell; cell values never leave the workbook.
 */
final class AssessmentLogDryRun
{
    public const string UNKNOWN_EMPLOYEE = 'unknown_employee';

    public const string INACTIVE_EMPLOYEE = 'inactive_employee';

    public const string CROSS_COMPANY_EMPLOYEE = 'cross_company_employee';

    public const string UNKNOWN_SKILL = 'unknown_skill';

    public const string INACTIVE_SKILL = 'inactive_skill';

    public const string NO_PUBLISHED_SCALE = 'no_published_scale';

    public const string INVALID_LEVEL = 'invalid_level';

    public const string LEVEL_OUT_OF_RANGE = 'level_out_of_range';

    public const string INVALID_DATE = 'invalid_date';

    public const string FUTURE_ASSESSMENT_DATE = 'future_assessment_date';

    public const string VALID_UNTIL_BEFORE_ASSESSMENT = 'valid_until_before_assessment';

    public const string DUPLICATE_ROW = 'duplicate_row';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly SkillWorkbookReader $reader,
        private readonly WorkforceSubjects $workforce,
        private readonly ProficiencyScaleStore $scales,
    ) {}

    /** @throws UnreadableSkillWorkbook */
    public function run(int $tenantId, int $companyEntityId, string $workbookPath): AssessmentLogDryRunResult
    {
        if ($this->tenants->requireTenantId() !== $tenantId) {
            throw new InvalidAssessmentException('The dry run tenant must match the bound tenant context.');
        }

        $workbook = $this->reader->read($workbookPath, [SkillWorkbookReader::ASSESSMENT_LOG]);
        $defects = array_values(array_filter(
            $workbook->defects,
            static fn (WorkbookDefect $defect): bool => $defect->source->sheet === SkillWorkbookReader::ASSESSMENT_LOG,
        ));
        $sha256 = $workbook->assessments[0]->source->sha256 ?? $defects[0]->source->sha256 ?? hash_file('sha256', $workbookPath);

        $employees = [];
        foreach ($this->workforce->employees($companyEntityId) as $employee) {
            if ($employee->employeeNumber !== null) {
                $employees[trim($employee->employeeNumber)] = $employee;
            }
        }
        $siblings = null;

        $skills = [];
        foreach (Skill::query()->forCompany($tenantId, $companyEntityId)->get(['id', 'code', 'active']) as $skill) {
            $skills[$skill->code] = $skill;
        }

        $scale = $this->scales->currentScale($companyEntityId, SkillCatalogDefaults::SCALE_CODE);
        $levels = $scale === null ? [] : $scale->levels()->pluck('level')->map(fn ($level): int => (int) $level)->all();
        $today = CarbonImmutable::today()->format('Y-m-d');

        $wouldCreate = $wouldSkip = 0;
        $seen = [];

        foreach ($workbook->assessments as $row) {
            $problems = [];
            $number = $row->source->row;

            $staffId = trim($row->staffId);
            $employee = $employees[$staffId] ?? null;
            $employeeId = null;
            if ($employee === null) {
                $siblings ??= $this->siblingStaffIds($tenantId, $companyEntityId);
                $problems[] = new WorkbookDefect(
                    isset($siblings[$staffId]) ? self::CROSS_COMPANY_EMPLOYEE : self::UNKNOWN_EMPLOYEE,
                    'D'.$number,
                    $row->source,
                );
            } elseif (! $employee->active) {
                $problems[] = new WorkbookDefect(self::INACTIVE_EMPLOYEE, 'D'.$number, $row->source);
            } else {
                $employeeId = (int) $employee->reference->externalId;
            }

            $skill = $skills[strtolower(trim($row->skillId))] ?? null;
            if ($skill === null) {
                $problems[] = new WorkbookDefect(self::UNKNOWN_SKILL, 'E'.$number, $row->source);
            } elseif (! $skill->active) {
                $problems[] = new WorkbookDefect(self::INACTIVE_SKILL, 'E'.$number, $row->source);
            }

            $level = trim($row->assessedLevel);
            if ($levels === []) {
                $problems[] = new WorkbookDefect(self::NO_PUBLISHED_SCALE, 'F'.$number, $row->source);
            } elseif (preg_match('/^\d{1,3}$/D', $level) !== 1) {
                $problems[] = new WorkbookDefect(self::INVALID_LEVEL, 'F'.$number, $row->source);
            } elseif (! in_array((int) $level, $levels, true)) {
                $problems[] = new WorkbookDefect(self::LEVEL_OUT_OF_RANGE, 'F'.$number, $row->source);
            }

            $assessedOn = $this->date($row->assessedOn);
            if ($assessedOn === null) {
                $problems[] = new WorkbookDefect(self::INVALID_DATE, 'C'.$number, $row->source);
            } elseif ($assessedOn > $today) {
                $problems[] = new WorkbookDefect(self::FUTURE_ASSESSMENT_DATE, 'C'.$number, $row->source);
            }

            if (trim($row->validUntil) !== '') {
                $validUntil = $this->date($row->validUntil);
                if ($validUntil === null) {
                    $problems[] = new WorkbookDefect(self::INVALID_DATE, 'L'.$number, $row->source);
                } elseif ($assessedOn !== null && $validUntil < $assessedOn) {
                    $problems[] = new WorkbookDefect(self::VALID_UNTIL_BEFORE_ASSESSMENT, 'L'.$number, $row->source);
                }
            }

            if ($problems === []) {
                $key = $employeeId.'|'.$skill->id.'|'.$assessedOn;
                if (isset($seen[$key])) {
                    $problems[] = new WorkbookDefect(self::DUPLICATE_ROW, 'D'.$number, $row->source);
                }
                $seen[$key] = true;
            }

            if ($problems !== []) {
                array_push($defects, ...$problems);

                continue;
            }

            if ($this->finalizedExists($tenantId, $companyEntityId, $employeeId, (int) $skill->id, $assessedOn)) {
                $wouldSkip++;
            } else {
                $wouldCreate++;
            }
        }

        return new AssessmentLogDryRunResult($sha256, $wouldCreate, $wouldSkip, $defects);
    }

    /**
     * Staff IDs of the tenant's other active companies, so a sibling's employee
     * is named as such rather than as unknown. Company::forTenant never
     * crosses the tenant, and the directory scopes to the bound tenant.
     *
     * @return array<string, true>
     */
    private function siblingStaffIds(int $tenantId, int $companyEntityId): array
    {
        $ids = [];
        $companies = Company::query()->forTenant($tenantId)
            ->where('status', 'active')
            ->whereKeyNot($companyEntityId)
            ->pluck('id');

        foreach ($companies as $companyId) {
            foreach ($this->workforce->employees((int) $companyId) as $employee) {
                if ($employee->employeeNumber !== null) {
                    $ids[trim($employee->employeeNumber)] = true;
                }
            }
        }

        return $ids;
    }

    private function finalizedExists(int $tenantId, int $companyEntityId, int $employeeId, int $skillId, string $assessedOn): bool
    {
        return SkillAssessment::query()->forCompany($tenantId, $companyEntityId)
            ->where('employee_entity_id', $employeeId)
            ->where('skill_id', $skillId)
            ->where('status', AssessmentStatus::Finalized->value)
            ->whereDate('assessed_at', $assessedOn)
            ->exists();
    }

    /** ISO date text or an Excel day serial to Y-m-d; null when it is neither. */
    private function date(string $text): ?string
    {
        $text = trim($text);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $text) === 1) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $text);

            return $parsed !== false && $parsed->format('Y-m-d') === $text ? $text : null;
        }

        if (preg_match('/^\d{1,6}(?:\.0+)?$/D', $text) === 1) {
            return (new DateTimeImmutable('1899-12-30'))->modify('+'.(int) $text.' days')->format('Y-m-d');
        }

        return null;
    }
}
