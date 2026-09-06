<?php

use Illuminate\Support\Facades\DB;

function skillWorkbookDryRunFixture(): string
{
    return __DIR__.'/../Fixtures/skill-catalogue.xlsx';
}

function alteredSkillWorkbookForDryRun(callable $alter): string
{
    $directory = storage_path('framework/testing');
    $path = tempnam($directory, 'skill-workbook-dry-run-');
    copy(skillWorkbookDryRunFixture(), $path);

    $zip = new ZipArchive;
    $zip->open($path);
    $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $next = $alter($xml);
    expect($next)->not->toBe($xml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $next);
    $zip->close();

    return $path;
}

function skillCatalogueRowCounts(): array
{
    return [
        'categories' => DB::table('people_connector_skill_categories')->count(),
        'skills' => DB::table('people_connector_skill_skills')->count(),
        'scales' => DB::table('people_connector_skill_proficiency_scales')->count(),
        'levels' => DB::table('people_connector_skill_proficiency_scale_levels')->count(),
    ];
}

test('the workbook dry run reports candidate records with provenance and writes nothing', function (): void {
    $before = skillCatalogueRowCounts();

    $this->artisan('people:skills-workbook-dry-run', ['workbook' => skillWorkbookDryRunFixture()])
        ->expectsOutputToContain('02 Skill Catalogue: skills=2, category occurrences=2')
        ->expectsOutputToContain('00 Guide: proficiency levels=6')
        ->expectsOutputToContain('skill {"code":"DEMO-001","department":"Shared"')
        ->expectsOutputToContain('category occurrence {"name":"Safety"} | provenance sha256=')
        ->expectsOutputToContain('proficiency level {"level":"5","name":"Demo level 5"')
        ->expectsOutputToContain('Defects: 0 (blocking: 0)')
        ->expectsOutputToContain('Database writes: 0')
        ->assertSuccessful();

    expect(skillCatalogueRowCounts())->toBe($before);
});

test('a configured blocking defect makes the dry run fail', function (): void {
    config()->set('people-skills.workbook.blocking_defects', ['formula']);
    $path = alteredSkillWorkbookForDryRun(
        fn (string $xml): string => preg_replace('/(<x:c\b[^>]*\br="D6"[^>]*>)/', '$1<x:f>1+1</x:f>', $xml),
    );

    try {
        $this->artisan('people:skills-workbook-dry-run', ['workbook' => $path])
            ->expectsOutputToContain('02 Skill Catalogue: skills=1, category occurrences=1')
            ->expectsOutputToContain('[blocking] formula at 02 Skill Catalogue!D6')
            ->expectsOutputToContain('Defects: 1 (blocking: 1)')
            ->assertFailed();
    } finally {
        unlink($path);
    }
});

test('a defect not marked blocking remains visible without failing the dry run', function (): void {
    config()->set('people-skills.workbook.blocking_defects', []);
    $path = alteredSkillWorkbookForDryRun(
        fn (string $xml): string => preg_replace('/(<x:c\b[^>]*\br="D6"[^>]*>)/', '$1<x:f>1+1</x:f>', $xml),
    );

    try {
        $this->artisan('people:skills-workbook-dry-run', ['workbook' => $path])
            ->expectsOutputToContain('[warning] formula at 02 Skill Catalogue!D6')
            ->expectsOutputToContain('Defects: 1 (blocking: 0)')
            ->assertSuccessful();
    } finally {
        unlink($path);
    }
});
