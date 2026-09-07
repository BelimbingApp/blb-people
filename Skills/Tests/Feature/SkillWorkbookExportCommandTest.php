<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceCompany;
use App\Domains\People\Provider\Data\WorkforceEmployee;
use App\Domains\People\Provider\Data\WorkforceOrganizationUnit;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Data\ProficiencyLevelDraft;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\CriticalClassification;
use App\Domains\People\Skills\Enums\SkillScope;
use App\Domains\People\Skills\Import\SkillWorkbookReader;
use App\Domains\People\Skills\Services\ProficiencyScaleStore;
use App\Domains\People\Skills\Services\SkillCatalogDefaults;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Tests\Support\CompanyIsolationFixture;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use App\Domains\People\Skills\Tests\Support\TwoCompanyTenant;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/**
 * @return array{TwoCompanyTenant, int, int} [fixture, department entity id, owner employee entity id]
 *
 * Department and owner ids are real workforce rows (the skills table carries
 * schema foreign keys to them); the directory mock only supplies their
 * display names, mirroring the provider seam contract that external ids
 * arrive as the string form of those row ids.
 */
function skillWorkbookExportFixture(): array
{
    $fixture = CompanyIsolationFixture::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);

    $department = NativeWorkforceFixture::create(
        $fixture->tenantId,
        WorkforceResourceType::OrganizationUnit,
        $fixture->alphaCompanyEntityId,
    );
    $owner = NativeWorkforceFixture::create(
        $fixture->tenantId,
        WorkforceResourceType::Employee,
        $fixture->alphaCompanyEntityId,
    );
    $departmentId = (int) $department->id;
    $ownerId = (int) $owner->id;

    $now = new DateTimeImmutable;
    $alpha = $fixture->alphaCompanyEntityId;
    $beta = $fixture->betaCompanyEntityId;
    $known = [$alpha, $beta];

    /** @var MockInterface&ReadsWorkforceDirectory $directory */
    $directory = Mockery::mock(ReadsWorkforceDirectory::class);
    $directory->shouldReceive('company')
        ->andReturnUsing(fn (string $id): ?WorkforceCompany => in_array((int) $id, $known, true)
            ? new WorkforceCompany(
                new ExternalReference(WorkforceResourceType::Company, (string) (int) $id),
                'Company '.(int) $id,
                true,
                $now,
            )
            : null);
    $directory->shouldReceive('organizationUnits')
        ->andReturnUsing(fn (string $id): array => (int) $id === $alpha
            ? [new WorkforceOrganizationUnit(
                new ExternalReference(WorkforceResourceType::OrganizationUnit, (string) $departmentId),
                new ExternalReference(WorkforceResourceType::Company, (string) $alpha),
                'Line Maintenance',
                true,
                $now,
                $now,
            )]
            : []);
    $directory->shouldReceive('employees')
        ->andReturnUsing(fn (string $id): array => (int) $id === $alpha
            ? [new WorkforceEmployee(
                new ExternalReference(WorkforceResourceType::Employee, (string) $ownerId),
                new ExternalReference(WorkforceResourceType::Company, (string) $alpha),
                'Amara Owner',
                true,
                $now,
                $now,
                organizationReference: new ExternalReference(WorkforceResourceType::OrganizationUnit, (string) $departmentId),
            )]
            : []);
    app()->instance(ReadsWorkforceDirectory::class, $directory);

    return [$fixture, $departmentId, $ownerId];
}

/** @return list<ProficiencyLevelDraft> */
function skillWorkbookExportLevels(int $count = 6): array
{
    return array_map(
        fn (int $level): ProficiencyLevelDraft => new ProficiencyLevelDraft(
            $level,
            "Export level {$level}",
            "Demonstrates export stage {$level}.",
            "May work alone at export stage {$level}.",
        ),
        range(0, $count - 1),
    );
}

function skillWorkbookExportSeedCatalog(TwoCompanyTenant $fixture, int $departmentId, int $ownerId): void
{
    $catalog = app(SkillCatalogStore::class);
    $alpha = $fixture->alphaCompanyEntityId;

    $safety = $catalog->defineCategory($alpha, 'safety', 'Safety Practices');
    $process = $catalog->defineCategory($alpha, 'process', 'Process Control');

    $catalog->defineSkill($alpha, new SkillDraft(
        code: 'export.lockout',
        name: 'Lockout Tagout',
        definition: 'Isolates energy to the approved standard.',
        categoryId: (int) $safety->id,
        criticalClassification: CriticalClassification::Safety,
        evidenceGuide: 'Show a signed isolation permit.',
    ));
    $catalog->defineSkill($alpha, new SkillDraft(
        code: 'export.tower',
        name: 'Tower Watch',
        definition: 'Monitors the tower to the approved standard.',
        categoryId: (int) $process->id,
        scope: SkillScope::Department,
        departmentEntityId: $departmentId,
        defaultAssessmentMethod: AssessmentMethod::PracticalDemonstration,
        defaultReassessmentMonths: 6,
        ownerEmployeeEntityId: $ownerId,
    ));
}

function skillWorkbookExportSeedScale(TwoCompanyTenant $fixture, int $levels = 6): void
{
    $scales = app(ProficiencyScaleStore::class);
    $draft = $scales->draft(
        $fixture->alphaCompanyEntityId,
        SkillCatalogDefaults::SCALE_CODE,
        'Standard scale',
        skillWorkbookExportLevels($levels),
    );
    $scales->publish($fixture->alphaCompanyEntityId, (int) $draft->id);
}

function skillWorkbookExportSeed(TwoCompanyTenant $fixture, int $departmentId, int $ownerId): void
{
    skillWorkbookExportSeedCatalog($fixture, $departmentId, $ownerId);
    skillWorkbookExportSeedScale($fixture);
}

function skillWorkbookExportPath(): string
{
    $path = tempnam(storage_path('framework/testing'), 'skill-workbook-export-');
    assert(is_string($path));
    unlink($path);

    return $path.'.xlsx';
}

function skillWorkbookExportRowCounts(): array
{
    return [
        'categories' => DB::table('people_connector_skill_categories')->count(),
        'skills' => DB::table('people_connector_skill_skills')->count(),
        'scales' => DB::table('people_connector_skill_proficiency_scales')->count(),
        'levels' => DB::table('people_connector_skill_proficiency_scale_levels')->count(),
    ];
}

/** Read one header row back out of the exported archive, keyed by sheet name. */
function skillWorkbookExportHeaders(string $path, string $sheet, int $row): array
{
    $zip = new ZipArchive;

    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException("Could not open the exported workbook at [{$path}].");
    }

    try {
        $rawBook = $zip->getFromName('xl/workbook.xml');

        if (! is_string($rawBook)) {
            throw new RuntimeException('The exported workbook has no xl/workbook.xml part.');
        }

        $workbook = new DOMDocument;

        if ($workbook->loadXML($rawBook) === false) {
            throw new RuntimeException('The exported xl/workbook.xml part is not valid XML.');
        }

        $book = new DOMXPath($workbook);
        $book->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $nodes = $book->query(sprintf('/s:workbook/s:sheets/s:sheet[@name="%s"]', $sheet));

        if ($nodes === false || $nodes->length !== 1) {
            throw new RuntimeException("Sheet [{$sheet}] was not found in the exported workbook.");
        }

        $sheetNode = $nodes->item(0);
        assert($sheetNode instanceof DOMElement);
        $id = $sheetNode->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');

        $rawRels = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if (! is_string($rawRels)) {
            throw new RuntimeException('The exported workbook has no xl/_rels/workbook.xml.rels part.');
        }

        $rels = new DOMDocument;

        if ($rels->loadXML($rawRels) === false) {
            throw new RuntimeException('The exported workbook rels part is not valid XML.');
        }

        $links = new DOMXPath($rels);
        $targets = $links->query(sprintf('/*[local-name()="Relationships"]/*[local-name()="Relationship"][@Id="%s"]', $id));

        if ($targets === false || $targets->length !== 1) {
            throw new RuntimeException("No relationship [{$id}] in the exported workbook.");
        }

        $target = $targets->item(0);
        assert($target instanceof DOMElement);
        $rawSheet = $zip->getFromName('xl/'.$target->getAttribute('Target'));

        if (! is_string($rawSheet)) {
            throw new RuntimeException("Sheet [{$sheet}] has no readable part in the exported workbook.");
        }

        $document = new DOMDocument;

        if ($document->loadXML($rawSheet) === false) {
            throw new RuntimeException("Sheet [{$sheet}] is not valid XML in the exported workbook.");
        }

        $sheetXml = new DOMXPath($document);
        $sheetXml->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $headers = [];
        $cells = $sheetXml->query(sprintf('/s:worksheet/s:sheetData/s:row[@r="%d"]/s:c', $row));

        if ($cells === false) {
            throw new RuntimeException("Row [{$row}] could not be read from sheet [{$sheet}].");
        }

        foreach ($cells as $cell) {
            if (! $cell instanceof DOMElement) {
                throw new RuntimeException("Row [{$row}] of sheet [{$sheet}] holds a non-element cell.");
            }
            $text = '';
            foreach ($cell->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 't') as $run) {
                $text .= $run->textContent;
            }
            $headers[] = $text !== '' ? $text : $cell->textContent;
        }

        return $headers;
    } finally {
        $zip->close();
    }
}

function exportWorkbookFor(TestCase $test, TwoCompanyTenant $fixture, string $path, int|string|null $company = null): object
{
    return $test->artisan('people:skills-workbook-export', array_filter([
        'output' => $path,
        '--tenant' => $fixture->tenantId,
        '--company' => $company ?? $fixture->alphaCompanyEntityId,
    ]));
}

test('the export round-trips through the reader with zero defects', function (): void {
    [$fixture, $departmentId, $ownerId] = skillWorkbookExportFixture();
    skillWorkbookExportSeed($fixture, $departmentId, $ownerId);
    $path = skillWorkbookExportPath();

    try {
        exportWorkbookFor($this, $fixture, $path)
            ->expectsOutputToContain('02 Skill Catalogue: skills=2')
            ->expectsOutputToContain('00 Guide: proficiency levels=6')
            ->expectsOutputToContain('Workbook SHA-256: ')
            ->assertSuccessful();

        $result = app(SkillWorkbookReader::class)->read($path);

        expect($result->defects)->toBe([])
            ->and(count($result->skills))->toBe(2);

        $byCode = [];
        foreach ($result->skills as $skill) {
            $byCode[$skill->code] = $skill;
        }

        expect($byCode['export.lockout']->name)->toBe('Lockout Tagout')
            ->and($byCode['export.lockout']->definition)->toBe('Isolates energy to the approved standard.')
            ->and($byCode['export.lockout']->department)->toBe('Shared')
            ->and($byCode['export.lockout']->active)->toBe('Yes')
            ->and($byCode['export.tower']->department)->toBe('Line Maintenance')
            ->and($byCode['export.tower']->owner)->toBe('Amara Owner')
            ->and($byCode['export.tower']->reassessmentMonths)->toBe('6');

        expect(array_map(
            fn ($level): array => [
                (int) $level->level,
                $level->name,
                $level->observableStandard,
                $level->independentWork,
                $level->trainOthers,
                $level->normalDecision,
            ],
            $result->levels,
        ))->toBe(array_map(
            fn (int $level): array => [
                $level,
                "Export level {$level}",
                "Demonstrates export stage {$level}.",
                "May work alone at export stage {$level}.",
                "May work alone at export stage {$level}.",
                "May work alone at export stage {$level}.",
            ],
            range(0, 5),
        ));

        expect(skillWorkbookExportHeaders($path, '02 Skill Catalogue', 5))
            ->toBe(SkillWorkbookReader::TABLES['02 Skill Catalogue'][2])
            ->and(skillWorkbookExportHeaders($path, '00 Guide', 24))
            ->toBe(SkillWorkbookReader::TABLES['00 Guide'][2]);
    } finally {
        is_file($path) and unlink($path);
    }
});

test('the dry run agrees with the export and neither command writes to the database', function (): void {
    [$fixture, $departmentId, $ownerId] = skillWorkbookExportFixture();
    skillWorkbookExportSeed($fixture, $departmentId, $ownerId);
    $path = skillWorkbookExportPath();
    $before = skillWorkbookExportRowCounts();

    try {
        exportWorkbookFor($this, $fixture, $path)->assertSuccessful();

        $this->artisan('people:skills-workbook-dry-run', ['workbook' => $path])
            ->expectsOutputToContain('02 Skill Catalogue: skills=2, category occurrences=2')
            ->expectsOutputToContain('00 Guide: proficiency levels=6')
            ->expectsOutputToContain('Defects: 0 (blocking: 0)')
            ->expectsOutputToContain('Database writes: 0')
            ->expectsOutputToContain('provenance sha256='.hash_file('sha256', $path))
            ->assertSuccessful();

        expect(skillWorkbookExportRowCounts())->toBe($before);
    } finally {
        is_file($path) and unlink($path);
    }
});

test('a deactivated skill exports as inactive while the sibling company stays out', function (): void {
    [$fixture, $departmentId, $ownerId] = skillWorkbookExportFixture();
    skillWorkbookExportSeed($fixture, $departmentId, $ownerId);

    $catalog = app(SkillCatalogStore::class);
    $alpha = $fixture->alphaCompanyEntityId;
    $beta = $fixture->betaCompanyEntityId;

    $locked = DB::table('people_connector_skill_skills')
        ->where('company_entity_id', $alpha)->where('code', 'export.lockout')->value('id');
    $catalog->deactivateSkill($alpha, (int) $locked);

    $betaCategory = $catalog->defineCategory($beta, 'secret', 'Beta Secret');
    $catalog->defineSkill($beta, new SkillDraft(
        code: 'beta.secret.process',
        name: 'Beta Secret Process',
        definition: 'Never leaves Beta.',
        categoryId: (int) $betaCategory->id,
    ));

    $path = skillWorkbookExportPath();

    try {
        exportWorkbookFor($this, $fixture, $path)->assertSuccessful();

        $result = app(SkillWorkbookReader::class)->read($path);
        $byCode = [];
        foreach ($result->skills as $skill) {
            $byCode[$skill->code] = $skill;
        }

        expect($result->defects)->toBe([])
            ->and(array_keys($byCode))->toBe(['export.lockout', 'export.tower'])
            ->and($byCode['export.lockout']->active)->toBe('No');
    } finally {
        is_file($path) and unlink($path);
    }
});

test('a company id from another tenant is refused without leaking rows', function (): void {
    [$fixture, $departmentId, $ownerId] = skillWorkbookExportFixture();
    skillWorkbookExportSeed($fixture, $departmentId, $ownerId);

    $other = CompanyIsolationFixture::twoCompaniesInOneTenant('Gamma Ltd', 'Delta Co');
    app(TenantContext::class)->set($fixture->tenantId);

    $path = skillWorkbookExportPath();

    try {
        exportWorkbookFor($this, $fixture, $path, $other->alphaCompanyEntityId)
            ->expectsOutputToContain('company workforce entity')
            ->assertFailed();

        expect(is_file($path))->toBeFalse();
    } finally {
        is_file($path) and unlink($path);
    }
});

test('an export without a published scale fails before writing a file', function (): void {
    [$fixture, $departmentId, $ownerId] = skillWorkbookExportFixture();
    skillWorkbookExportSeedCatalog($fixture, $departmentId, $ownerId);
    $path = skillWorkbookExportPath();

    try {
        exportWorkbookFor($this, $fixture, $path)
            ->expectsOutputToContain('published')
            ->assertFailed();

        expect(is_file($path))->toBeFalse();
    } finally {
        is_file($path) and unlink($path);
    }
});

test('more guide levels than the table holds are refused instead of silently dropped', function (): void {
    [$fixture, $departmentId, $ownerId] = skillWorkbookExportFixture();
    skillWorkbookExportSeedCatalog($fixture, $departmentId, $ownerId);
    skillWorkbookExportSeedScale($fixture, 7);
    $path = skillWorkbookExportPath();

    try {
        exportWorkbookFor($this, $fixture, $path)
            ->expectsOutputToContain('six levels')
            ->assertFailed();

        expect(is_file($path))->toBeFalse();
    } finally {
        is_file($path) and unlink($path);
    }
});

test('a company outside the workforce directory is refused before any query', function (): void {
    [$fixture, $departmentId, $ownerId] = skillWorkbookExportFixture();
    skillWorkbookExportSeed($fixture, $departmentId, $ownerId);
    $before = skillWorkbookExportRowCounts();
    $path = skillWorkbookExportPath();

    try {
        exportWorkbookFor($this, $fixture, $path, 424242)
            ->expectsOutputToContain('company workforce entity')
            ->assertFailed();

        expect(is_file($path))->toBeFalse()
            ->and(skillWorkbookExportRowCounts())->toBe($before);
    } finally {
        is_file($path) and unlink($path);
    }
});
