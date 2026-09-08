<?php

namespace App\Domains\People\Training\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
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
use App\Domains\People\Skills\Services\CompanyAttribution;
use App\Domains\People\Skills\Services\RequirementProfileStore;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Services\WorkforceSubjects;
use App\Domains\People\Training\Data\MigrationImportReport;
use App\Domains\People\Training\Exceptions\InvalidMigrationImportException;
use App\Domains\People\Training\Models\TrainingMigrationSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Idempotent, resumable Training migration import with dry run and quarantine (0015-e).
 *
 * A signed inventory is the gate: unsigned companies never open the file.
 * Each source row carries a durable ledger identity (source_key + sha256 +
 * row). Dry runs classify without writing; apply commits one row at a time so
 * an interrupted pass resumes without duplicating what landed. Rejected rows
 * quarantine with reason codes only — never raw source field values.
 */
final class MigrationImport
{
    public const HEADER = ['department', 'role', 'skill', 'required_level', 'criticality'];

    public const CATEGORY_CODE = 'migration-import';

    /** Reason codes — stable identifiers, never interpolated source text. */
    public const REASON_BLANK_DEPARTMENT = 'blank_department';

    public const REASON_BLANK_ROLE = 'blank_role';

    public const REASON_BLANK_SKILL = 'blank_skill';

    public const REASON_INVALID_LEVEL = 'invalid_level';

    public const REASON_INVALID_CRITICALITY = 'invalid_criticality';

    public const REASON_UNKNOWN_DEPARTMENT = 'unknown_department';

    public const REASON_DEPARTMENT_EMPTY = 'department_has_no_employees';

    public const REASON_UNUSABLE_CODES = 'unusable_skill_or_role_code';

    private const MAX_ROWS = 5000;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuthorizationService $authorization,
        private readonly CompanyAttribution $companies,
        private readonly TrainingMigrationSourceStore $sources,
        private readonly MigrationLedger $ledger,
        private readonly SkillCatalogStore $catalog,
        private readonly RequirementProfileStore $profiles,
        private readonly WorkforceSubjects $workforce,
    ) {}

    /**
     * Classify every row and write nothing.
     *
     * @param  (callable(int): void)|null  $afterRow  Test hook; invoked after each classified row with the 1-based processed count
     */
    public function dryRun(
        User $actor,
        int $companyEntityId,
        string $sourceKey,
        string $path,
        ?callable $afterRow = null,
    ): MigrationImportReport {
        return $this->run($actor, $companyEntityId, $sourceKey, $path, dryRun: true, failAfter: null, afterRow: $afterRow);
    }

    /**
     * Apply importable rows; quarantine rejects; skip ledger hits.
     *
     * @param  (callable(int): void)|null  $afterRow  Test hook after each committed or skipped/rejected row
     */
    public function import(
        User $actor,
        int $companyEntityId,
        string $sourceKey,
        string $path,
        ?int $failAfter = null,
        ?callable $afterRow = null,
    ): MigrationImportReport {
        return $this->run($actor, $companyEntityId, $sourceKey, $path, dryRun: false, failAfter: $failAfter, afterRow: $afterRow);
    }

    /**
     * @param  (callable(int): void)|null  $afterRow
     */
    private function run(
        User $actor,
        int $companyEntityId,
        string $sourceKey,
        string $path,
        bool $dryRun,
        ?int $failAfter,
        ?callable $afterRow,
    ): MigrationImportReport {
        $this->authorize($actor, $companyEntityId);

        if (! $this->sources->signedInventory($companyEntityId)) {
            throw new InvalidMigrationImportException('Migration import refuses an unsigned source inventory.');
        }

        $source = TrainingMigrationSource::query()
            ->forCompany($this->tenants->requireTenantId(), $companyEntityId)
            ->where('source_key', $sourceKey)
            ->first();

        if ($source === null) {
            throw new InvalidMigrationImportException('Migration import refuses a source key that is not in the inventory.');
        }

        $sha256 = hash_file('sha256', $path);
        if ($sha256 === false) {
            throw new InvalidMigrationImportException('Migration import could not read the source file.');
        }

        $rows = $this->parse($path);
        $imported = [];
        $skipped = [];
        $rejected = [];
        $processed = 0;

        foreach ($rows as $row) {
            $processed++;
            if ($failAfter !== null && ! $dryRun && count($imported) >= $failAfter) {
                throw new InvalidMigrationImportException('Migration import interrupted after '.$failAfter.' applied rows.');
            }

            $existing = $this->ledger->find($companyEntityId, $sourceKey, $sha256, $row['row']);
            if ($existing !== null) {
                $skipped[] = $row['row'];
                $afterRow !== null && $afterRow($processed);

                continue;
            }

            $reason = $this->classify($companyEntityId, $row);
            if ($reason !== null) {
                $rejected[] = ['row' => $row['row'], 'reason' => $reason];
                if (! $dryRun) {
                    // Own transaction so a later unique hit cannot abort quarantine siblings on Postgres.
                    DB::transaction(function () use ($companyEntityId, $sourceKey, $sha256, $row, $reason, $actor): void {
                        $this->ledger->recordRejected(
                            $companyEntityId,
                            $sourceKey,
                            $sha256,
                            $row['row'],
                            $reason,
                            ['row' => $row['row']],
                            (int) $actor->getKey(),
                        );
                    });
                }
                $afterRow !== null && $afterRow($processed);

                continue;
            }

            $imported[] = $row['row'];
            if (! $dryRun) {
                DB::transaction(function () use ($companyEntityId, $sourceKey, $sha256, $row, $actor): void {
                    $profile = $this->writeRow($companyEntityId, $row);
                    $this->ledger->recordMigrated(
                        $companyEntityId,
                        $sourceKey,
                        $sha256,
                        $row['row'],
                        $profile->getTable(),
                        (int) $profile->getKey(),
                        (int) $actor->getKey(),
                    );
                });
            }
            $afterRow !== null && $afterRow($processed);
        }

        return new MigrationImportReport(
            sourceKey: $sourceKey,
            sourceSha256: $sha256,
            dryRun: $dryRun,
            read: count($rows),
            importedRows: $imported,
            skippedRows: $skipped,
            rejected: $rejected,
        );
    }

    /**
     * @return list<array{row: int, department: string, role: string, skill: string, level: string, criticality: string}>
     */
    private function parse(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidMigrationImportException('Migration import could not read the source file.');
        }

        try {
            $header = fgetcsv($handle);
            if ($header === false) {
                throw new InvalidMigrationImportException('Migration import refuses an empty source file.');
            }
            $header = array_map(fn ($cell): string => strtolower(trim((string) $cell, " \t\xEF\xBB\xBF")), $header);
            if ($header !== self::HEADER) {
                throw new InvalidMigrationImportException('Migration import refuses a source file whose columns do not match the starter-profile shape.');
            }

            $rows = [];
            $line = 1;
            while (($cells = fgetcsv($handle)) !== false) {
                $line++;
                if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') {
                    continue;
                }
                if (count($rows) >= self::MAX_ROWS) {
                    throw new InvalidMigrationImportException('Migration import refuses a source file larger than '.self::MAX_ROWS.' data rows.');
                }
                $cells = array_map(fn ($cell): string => trim((string) $cell), array_pad($cells, 5, ''));
                $rows[] = [
                    'row' => $line,
                    'department' => $cells[0],
                    'role' => $cells[1],
                    'skill' => $cells[2],
                    'level' => $cells[3],
                    'criticality' => $cells[4],
                ];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array{row: int, department: string, role: string, skill: string, level: string, criticality: string}  $row
     */
    private function classify(int $companyEntityId, array $row): ?string
    {
        if ($row['department'] === '') {
            return self::REASON_BLANK_DEPARTMENT;
        }
        if ($row['role'] === '') {
            return self::REASON_BLANK_ROLE;
        }
        if ($row['skill'] === '') {
            return self::REASON_BLANK_SKILL;
        }
        if (preg_match('/^[1-5]$/D', $row['level']) !== 1) {
            return self::REASON_INVALID_LEVEL;
        }
        if (RequirementCriticality::tryFrom(strtolower($row['criticality'])) === null) {
            return self::REASON_INVALID_CRITICALITY;
        }

        $skillCode = $this->skillCode($row['skill']);
        $roleSlug = Str::slug($row['role'], '_');
        if ($skillCode === '' || $roleSlug === '') {
            return self::REASON_UNUSABLE_CODES;
        }

        $departments = $this->departments($companyEntityId);
        $departmentId = $departments[mb_strtolower($row['department'])] ?? null;
        if ($departmentId === null) {
            return self::REASON_UNKNOWN_DEPARTMENT;
        }
        if ($this->workforce->resolve(
            $this->tenants->requireTenantId(),
            $companyEntityId,
            WorkforceResourceType::OrganizationUnit,
            $departmentId,
        ) === null) {
            return self::REASON_DEPARTMENT_EMPTY;
        }

        return null;
    }

    /**
     * @param  array{row: int, department: string, role: string, skill: string, level: string, criticality: string}  $row
     */
    private function writeRow(int $companyEntityId, array $row): RequirementProfile
    {
        $tenantId = $this->tenants->requireTenantId();
        $departments = $this->departments($companyEntityId);
        $departmentId = $departments[mb_strtolower($row['department'])];
        $categoryId = $this->categoryId($tenantId, $companyEntityId);
        $skillCode = $this->skillCode($row['skill']);

        $skill = Skill::query()->forCompany($tenantId, $companyEntityId)->where('code', $skillCode)->first()
            ?? $this->catalog->defineSkill($companyEntityId, new SkillDraft(
                code: $skillCode,
                name: $row['skill'],
                definition: __('Imported by migration; define the standard before publication.'),
                categoryId: $categoryId,
            ));

        // Row number in the profile code keeps each source row a distinct
        // target so resumption compares identities rather than collapsing
        // same-named roles into one profile.
        $code = 'mig.'.$row['row'].'.'.Str::slug($row['department'], '_').'.'.Str::slug($row['role'], '_');

        return $this->profiles->draft($companyEntityId, new RequirementProfileDraft(
            code: $code,
            name: $row['role'].' ('.$row['department'].') #'.$row['row'],
            selectors: [new RequirementSelectorDraft(SelectorType::Department, null, $departmentId)],
            items: [new RequirementItemDraft(
                skillId: (int) $skill->getKey(),
                sequence: 1,
                requiredLevel: (int) $row['level'],
                criticality: RequirementCriticality::from(strtolower($row['criticality'])),
                weightPercent: 100.0,
            )],
        ));
    }

    /** @return array<string, int> */
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
            ?? $this->catalog->defineCategory(
                $companyEntityId,
                self::CATEGORY_CODE,
                'Migration import',
                'Skills created by a Training migration import; recategorize as the catalog matures.',
            );

        return (int) $category->getKey();
    }

    private function skillCode(string $name): string
    {
        return Str::limit(Str::slug($name, '_'), 80, '');
    }

    private function authorize(User $actor, int $companyEntityId): void
    {
        $this->tenants->requireTenantId();
        if (! $this->companies->mayActFor($actor, $companyEntityId)) {
            throw new InvalidMigrationImportException('Migration import is unavailable in the current company scope.');
        }
        $this->authorization->authorize(Actor::forUser($actor), TrainingMigrationSourceStore::MANAGE);
    }
}
