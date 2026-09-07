<?php

namespace App\Domains\People\Skills\Services;

use App\Base\Foundation\Contracts\SemanticActionRecorder;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Data\RequirementItemDraft;
use App\Domains\People\Skills\Data\RequirementProfileDraft;
use App\Domains\People\Skills\Data\RequirementSelectorDraft;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Enums\SelectorType;
use App\Domains\People\Skills\Models\RequirementProfile;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillCategory;
use App\Domains\People\Training\Services\MigrationLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starter-profile workbook import (plan 0008, issue #299): one CSV in the
 * documented shape (department, role, skill, required level, criticality)
 * becomes missing skills plus one draft requirement profile per
 * (department, role), written through SkillCatalogStore and
 * RequirementProfileStore so every catalog invariant still applies.
 *
 * Every row is validated before anything is written: a single bad row
 * refuses the whole file with a per-row error list. The write pass runs
 * inside one transaction as a second line of defence, but the validate
 * pass is the one that decides. Re-importing the same file is idempotent:
 * a skill code or profile code that already exists is left alone.
 *
 * Scoping only, no authorization: the caller proves the actor may act for
 * the company (see SkillCatalogStore's class comment).
 */
final class StarterProfileImporter
{
    public const EVENT = 'people.skill.catalog.imported';

    public const HEADER = ['department', 'role', 'skill', 'required_level', 'criticality'];

    public const CATEGORY_CODE = 'imported';

    /** Inventory source_key this importer writes under (0015-a / 0015-d). */
    public const SOURCE_KEY = 'starter-profiles';

    private const MAX_ROWS = 2000;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SkillCatalogStore $catalog,
        private readonly RequirementProfileStore $profiles,
        private readonly WorkforceSubjects $workforce,
        private readonly MigrationLedger $ledger,
    ) {}

    /**
     * @return array{errors: list<array{row: int, message: string}>, rows: int, skills: int, requirements: int, profiles: int}
     */
    public function import(
        int $companyEntityId,
        string $path,
        string $filename,
        string $sourceKey = self::SOURCE_KEY,
        ?int $recordedByUserId = null,
    ): array {
        [$rows, $errors] = $this->parse($path);
        if ($errors === []) {
            $errors = $this->validate($companyEntityId, $rows);
        }

        if ($errors !== []) {
            return ['errors' => $errors, 'rows' => count($rows), 'skills' => 0, 'requirements' => 0, 'profiles' => 0];
        }

        $sha256 = hash_file('sha256', $path);
        if ($sha256 === false) {
            return ['errors' => [['row' => 0, 'message' => __('The workbook could not be read.')]], 'rows' => count($rows), 'skills' => 0, 'requirements' => 0, 'profiles' => 0];
        }

        $recordedBy = $recordedByUserId ?? 0;
        $written = DB::transaction(fn (): array => $this->write($companyEntityId, $rows, $filename, $sourceKey, $sha256, $recordedBy));

        app(SemanticActionRecorder::class)->record(
            event: self::EVENT,
            summary: __('Imported :file: :rows rows, :skills new skills, :requirements new requirements', [
                'file' => $filename, 'rows' => count($rows), 'skills' => $written['skills'], 'requirements' => $written['requirements'],
            ]),
            source: __('Skills'),
            subject: ['name' => 'skill-catalog-import', 'id' => $companyEntityId, 'identifier' => $filename],
            surface: 'people.skill.catalog.import',
            uiElement: 'import',
            context: ['company_entity_id' => $companyEntityId, 'file' => $filename, 'rows' => count($rows)] + $written,
        );

        return ['errors' => [], 'rows' => count($rows)] + $written;
    }

    /**
     * @return array{list<array{row: int, department: string, role: string, skill: string, level: string, criticality: string}>, list<array{row: int, message: string}>}
     */
    private function parse(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [[], [['row' => 0, 'message' => __('The workbook could not be read.')]]];
        }

        try {
            $header = fgetcsv($handle);
            if ($header === false) {
                return [[], [['row' => 1, 'message' => __('The workbook is empty.')]]];
            }
            $header = array_map(fn ($cell): string => strtolower(trim((string) $cell, " \t\xEF\xBB\xBF")), $header);
            if ($header !== self::HEADER) {
                return [[], [['row' => 1, 'message' => __('Expected the columns :columns.', ['columns' => implode(', ', self::HEADER)])]]];
            }

            $rows = [];
            $line = 1;
            while (($cells = fgetcsv($handle)) !== false) {
                $line++;
                if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') {
                    continue;
                }
                if (count($rows) >= self::MAX_ROWS) {
                    return [[], [['row' => $line, 'message' => __('At most :max rows per import.', ['max' => self::MAX_ROWS])]]];
                }
                $cells = array_map(fn ($cell): string => trim((string) $cell), array_pad($cells, 5, ''));
                $rows[] = ['row' => $line, 'department' => $cells[0], 'role' => $cells[1], 'skill' => $cells[2], 'level' => $cells[3], 'criticality' => $cells[4]];
            }

            return [$rows, []];
        } finally {
            fclose($handle);
        }
    }

    /**
     * The validate-before-write pass: every row is checked and every
     * problem collected before write() may run.
     *
     * @param  list<array{row: int, department: string, role: string, skill: string, level: string, criticality: string}>  $rows
     * @return list<array{row: int, message: string}>
     */
    private function validate(int $companyEntityId, array $rows): array
    {
        $errors = [];
        $departments = $this->departments($companyEntityId);
        $seen = [];

        foreach ($rows as $row) {
            $problems = [];
            $department = $departments[mb_strtolower($row['department'])] ?? null;

            if ($department === null) {
                $problems[] = __('unknown department [:name]', ['name' => $row['department']]);
            } elseif ($this->workforce->resolve($this->tenantContext->requireTenantId(), $companyEntityId, WorkforceResourceType::OrganizationUnit, $department) === null) {
                $problems[] = __('department [:name] has no employees yet and cannot be targeted', ['name' => $row['department']]);
            }
            if ($row['role'] === '') {
                $problems[] = __('role is blank');
            }
            if ($row['skill'] === '') {
                $problems[] = __('skill is blank');
            } elseif ($this->skillCode($row['skill']) === '') {
                $problems[] = __('skill [:skill] yields no usable skill code', ['skill' => $row['skill']]);
            }
            if ($row['role'] !== '' && $department !== null && Str::slug($row['role'], '_') === '') {
                $problems[] = __('role [:role] yields no usable profile code', ['role' => $row['role']]);
            }
            if (preg_match('/^[1-5]$/D', $row['level']) !== 1) {
                $problems[] = __('required level [:level] must be a whole number from 1 to 5', ['level' => $row['level']]);
            }
            if (RequirementCriticality::tryFrom(strtolower($row['criticality'])) === null) {
                $problems[] = __('unknown criticality [:value]; use critical, essential or development', ['value' => $row['criticality']]);
            }

            $key = mb_strtolower($row['department']).'|'.mb_strtolower($row['role']).'|'.$this->skillCode($row['skill']);
            if (isset($seen[$key])) {
                $problems[] = __('duplicate skill [:skill] for this role (first at row :row)', ['skill' => $row['skill'], 'row' => $seen[$key]]);
            }
            $seen[$key] ??= $row['row'];

            foreach ($problems as $problem) {
                $errors[] = ['row' => $row['row'], 'message' => $problem];
            }
        }

        return $errors;
    }

    /**
     * @param  list<array{row: int, department: string, role: string, skill: string, level: string, criticality: string}>  $rows
     * @return array{skills: int, requirements: int, profiles: int}
     */
    private function write(
        int $companyEntityId,
        array $rows,
        string $filename,
        string $sourceKey,
        string $sourceSha256,
        int $recordedBy,
    ): array {
        $tenantId = $this->tenantContext->requireTenantId();
        $departments = $this->departments($companyEntityId);
        $categoryId = $this->categoryId($tenantId, $companyEntityId);
        $skills = $requirements = $profiles = 0;

        $skillIds = [];
        foreach ($rows as $row) {
            $code = $this->skillCode($row['skill']);
            if (isset($skillIds[$code])) {
                continue;
            }
            $skill = Skill::query()->forCompany($tenantId, $companyEntityId)->where('code', $code)->first();
            if ($skill === null) {
                $skill = $this->catalog->defineSkill($companyEntityId, new SkillDraft(
                    code: $code,
                    name: $row['skill'],
                    definition: __('Imported from :file; define the standard before publication.', ['file' => $filename]),
                    categoryId: $categoryId,
                ));
                $skills++;
            }
            $skillIds[$code] = (int) $skill->getKey();
        }

        $groups = [];
        foreach ($rows as $row) {
            $groups[mb_strtolower($row['department']).'|'.mb_strtolower($row['role'])][] = $row;
        }

        foreach ($groups as $group) {
            $first = $group[0];
            $code = 'starter.'.Str::slug($first['department'], '_').'.'.Str::slug($first['role'], '_');
            if (RequirementProfile::query()->forCompany($tenantId, $companyEntityId)->where('code', $code)->exists()) {
                continue;
            }
            // Equal weights at the column's two decimals, with the rounding
            // remainder on the last item so the stored total is exactly 100:
            // six items at 16.67 sum to 100.02, which publish() rightly refuses.
            $count = count($group);
            $weight = round(100 / $count, 2);
            $last = round(100 - $weight * ($count - 1), 2);
            $items = [];
            foreach (array_values($group) as $index => $row) {
                $items[] = new RequirementItemDraft(
                    skillId: $skillIds[$this->skillCode($row['skill'])],
                    sequence: $index + 1,
                    requiredLevel: (int) $row['level'],
                    criticality: RequirementCriticality::from(strtolower($row['criticality'])),
                    weightPercent: $index === $count - 1 ? $last : $weight,
                );
            }
            $profile = $this->profiles->draft($companyEntityId, new RequirementProfileDraft(
                code: $code,
                name: $first['role'].' ('.$first['department'].')',
                selectors: [new RequirementSelectorDraft(SelectorType::Department, null, $departments[mb_strtolower($first['department'])])],
                items: $items,
            ));
            $this->ledger->recordMigrated(
                $companyEntityId,
                $sourceKey,
                $sourceSha256,
                (int) $first['row'],
                $profile->getTable(),
                (int) $profile->getKey(),
                $recordedBy,
            );
            $profiles++;
            $requirements += count($items);
        }

        return ['skills' => $skills, 'requirements' => $requirements, 'profiles' => $profiles];
    }

    /** @return array<string, int> lower-cased department name => organization-unit entity id */
    private function departments(int $companyEntityId): array
    {
        $names = [];
        foreach ($this->workforce->organizationUnits($companyEntityId) as $unit) {
            $names[mb_strtolower($unit->name)] = (int) $unit->reference->externalId;
        }

        return $names;
    }

    private function categoryId(int $tenantId, int $companyEntityId): int
    {
        $category = SkillCategory::query()->forCompany($tenantId, $companyEntityId)->where('code', self::CATEGORY_CODE)->first()
            ?? $this->catalog->defineCategory($companyEntityId, self::CATEGORY_CODE, 'Imported', 'Skills created by a starter-profile import; recategorize as the catalog matures.');

        return (int) $category->getKey();
    }

    private function skillCode(string $name): string
    {
        return Str::limit(Str::slug($name, '_'), 80, '');
    }
}
