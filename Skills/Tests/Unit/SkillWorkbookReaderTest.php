<?php

use App\Domains\People\Skills\Import\SkillWorkbookReader;
use App\Domains\People\Skills\Import\UnreadableSkillWorkbook;

function skillWorkbookFixture(): string
{
    return __DIR__.'/../Fixtures/skill-catalogue.xlsx';
}

function alteredSkillWorkbook(string $entry, callable $alter): string
{
    $directory = __DIR__.'/../Fixtures/.runtime';
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    $path = tempnam($directory, 'skill-workbook-');
    copy(skillWorkbookFixture(), $path);
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = $zip->getFromName($entry);
    $next = $alter($xml);
    expect($next)->not->toBe($xml);
    $zip->addFromString($entry, $next);
    $zip->close();

    return $path;
}

it('reads catalogue labels and guide levels with exact provenance without changing the source', function () {
    $path = skillWorkbookFixture();
    $hash = hash_file('sha256', $path);
    $result = (new SkillWorkbookReader)->read($path);

    expect($result->defects)->toBe([])
        ->and(array_column($result->skills, 'code'))->toBe(['DEMO-001', 'DEMO-002'])
        ->and($result->skills[0]->department)->toBe('Shared')
        ->and($result->skills[1]->reassessmentMonths)->toBe('0')
        ->and($result->skills[1]->owner)->toBe('')
        ->and(array_column($result->categories, 'name'))->toBe(['Safety', 'Safety'])
        ->and(array_column($result->levels, 'level'))->toBe(['0', '1', '2', '3', '4', '5']);
    foreach ([...$result->skills, ...$result->categories, ...$result->levels] as $row) {
        expect($row->source->sha256)->toBe($hash);
    }
    expect($result->skills[0]->source->sheet)->toBe('02 Skill Catalogue')
        ->and($result->skills[0]->source->row)->toBe(6)
        ->and($result->categories[1]->source->row)->toBe(7)
        ->and($result->levels[0]->source->sheet)->toBe('00 Guide')
        ->and($result->levels[0]->source->row)->toBe(25)
        ->and($result->levels[5]->normalDecision)->toBe('Review stage 5')
        ->and(hash_file('sha256', $path))->toBe($hash);
});

it('reports formulas, merged cells and blank keys while excluding unsafe rows', function (string $fault, string $cell, Closure $alter) {
    $path = alteredSkillWorkbook('xl/worksheets/sheet1.xml', $alter);
    try {
        $result = (new SkillWorkbookReader)->read($path);
        $defects = array_values(array_filter($result->defects, fn ($defect) => $defect->kind === $fault));
        expect($defects)->toHaveCount(1)
            ->and($defects[0]->cell)->toBe($cell)
            ->and($defects[0]->source->row)->toBe(6)
            ->and($defects[0]->source->sha256)->toBe(hash_file('sha256', $path))
            ->and(array_column($result->skills, 'code'))->toBe(['DEMO-002']);
    } finally {
        unlink($path);
        rmdir(dirname($path));
    }
})->with([
    'error cell' => ['cell_error', 'D6', fn ($xml) => preg_replace('/<x:c\b[^>]*\br="D6"[^>]*>.*?<\/x:c>/s', '<x:c r="D6" t="e"><x:v>#REF!</x:v></x:c>', $xml)],
    'formula with cached value' => ['formula', 'D6', fn ($xml) => preg_replace('/(<(?:x:)?c\b[^>]*\br="D6"[^>]*>)/', '$1<x:f>1+1</x:f>', $xml)],
    'merged body cells' => ['merged_cells', 'D6:E6', fn ($xml) => str_replace('</x:worksheet>', '<x:mergeCells count="1"><x:mergeCell ref="D6:E6"/></x:mergeCells></x:worksheet>', $xml)],
    'blank skill key' => ['blank_key', 'A6', fn ($xml) => preg_replace('/<(?:x:)?c\b[^>]*\br="A6"[^>]*>.*?<\/(?:x:)?c>/s', '', $xml)],
    'blank category key' => ['blank_key', 'C6', fn ($xml) => preg_replace('/<(?:x:)?c\b[^>]*\br="C6"[^>]*>.*?<\/(?:x:)?c>/s', '', $xml)],
]);

it('refuses missing sheets, changed headers and external relationships instead of returning empty data', function (string $entry, Closure $alter) {
    $path = alteredSkillWorkbook($entry, $alter);
    try {
        expect(fn () => (new SkillWorkbookReader)->read($path))->toThrow(UnreadableSkillWorkbook::class);
    } finally {
        unlink($path);
        rmdir(dirname($path));
    }
})->with([
    'missing sheet' => ['xl/workbook.xml', fn ($xml) => str_replace('02 Skill Catalogue', 'Other sheet', $xml)],
    'missing header' => ['xl/worksheets/sheet1.xml', fn ($xml) => preg_replace('/<(?:x:)?c\b[^>]*\br="A5"[^>]*>.*?<\/(?:x:)?c>/s', '', $xml)],
    'external sheet' => ['xl/_rels/workbook.xml.rels', fn ($xml) => str_replace('Target="/xl/worksheets/sheet1.xml"', 'Target="https://example.invalid/sheet.xml" TargetMode="External"', $xml)],
    'DTD' => ['xl/workbook.xml', fn ($xml) => preg_replace('/(<x:workbook\b)/', '<!DOCTYPE workbook [<!ENTITY probe "unsafe">]>$1', $xml)],
]);

it('reads rich shared and inline strings without losing runs', function (string $type) {
    $path = alteredSkillWorkbook('xl/worksheets/sheet1.xml', function ($xml) use ($type) {
        $content = $type === 's' ? '<x:v>0</x:v>' : '<x:is><x:r><x:t>Practice </x:t></x:r><x:r><x:t>inspection</x:t></x:r></x:is>';

        return preg_replace('/<x:c\b[^>]*\br="D6"[^>]*>.*?<\/x:c>/s', '<x:c r="D6" t="'.$type.'">'.$content.'</x:c>', $xml);
    });
    try {
        if ($type === 's') {
            $zip = new ZipArchive;
            $zip->open($path);
            $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><r><t>Practice </t></r><r><t>inspection</t></r></si></sst>');
            $zip->close();
        }
        $result = (new SkillWorkbookReader)->read($path);
        expect($result->skills[0]->name)->toBe('Practice inspection')
            ->and($result->defects)->toBe([]);
    } finally {
        unlink($path);
        rmdir(dirname($path));
    }
})->with(['s', 'inlineStr']);

it('refuses malformed workbook structures', function (string $entry, Closure $alter) {
    $path = alteredSkillWorkbook($entry, $alter);
    try {
        expect(fn () => (new SkillWorkbookReader)->read($path))->toThrow(UnreadableSkillWorkbook::class);
    } finally {
        unlink($path);
        rmdir(dirname($path));
    }
})->with([
    'malformed XML' => ['xl/workbook.xml', fn ($xml) => '<workbook>'],
    'oversized XML' => ['xl/workbook.xml', fn ($xml) => str_replace('</x:workbook>', '<!--'.str_repeat(' ', 8 * 1024 * 1024).'--></x:workbook>', $xml)],
    'missing XML part' => ['xl/_rels/workbook.xml.rels', fn ($xml) => str_replace('/xl/worksheets/sheet1.xml', '/xl/worksheets/missing.xml', $xml)],
    'traversal relationship' => ['xl/_rels/workbook.xml.rels', fn ($xml) => str_replace('/xl/worksheets/sheet1.xml', '../sheet.xml', $xml)],
    'duplicate cell' => ['xl/worksheets/sheet1.xml', fn ($xml) => preg_replace('/(<x:c\b[^>]*\br="A6"[^>]*>.*?<\/x:c>)/s', '$1$1', $xml)],
    'duplicate sheet' => ['xl/workbook.xml', fn ($xml) => preg_replace('/(<x:sheet\b[^>]*\bname="02 Skill Catalogue"[^>]*\/>)/', '$1$1', $xml)],
    'invalid shared string' => ['xl/worksheets/sheet1.xml', fn ($xml) => preg_replace('/<x:c\b[^>]*\br="D6"[^>]*>.*?<\/x:c>/s', '<x:c r="D6" t="s"><x:v>999999</x:v></x:c>', $xml)],
    'invalid address' => ['xl/worksheets/sheet1.xml', fn ($xml) => str_replace('r="D6"', 'r="D0"', $xml)],
    'invalid merged range' => ['xl/worksheets/sheet1.xml', fn ($xml) => str_replace('</x:worksheet>', '<x:mergeCells><x:mergeCell ref="D6"/></x:mergeCells></x:worksheet>', $xml)],
]);

it('reports unsafe guide rows', function () {
    $path = alteredSkillWorkbook('xl/worksheets/sheet2.xml', fn ($xml) => preg_replace('/(<x:c\b[^>]*\br="B25"[^>]*>)/', '$1<x:f>1+1</x:f>', $xml));
    try {
        $result = (new SkillWorkbookReader)->read($path);
        expect(array_column($result->levels, 'level'))->toBe(['1', '2', '3', '4', '5'])
            ->and($result->defects[0]->kind)->toBe('formula')
            ->and($result->defects[0]->source->sheet)->toBe('00 Guide')
            ->and($result->defects[0]->source->row)->toBe(25);
    } finally {
        unlink($path);
        rmdir(dirname($path));
    }
});

it('rejects non-workbooks and missing local files', function () {
    expect(fn () => (new SkillWorkbookReader)->read(__FILE__))->toThrow(UnreadableSkillWorkbook::class)
        ->and(fn () => (new SkillWorkbookReader)->read(__DIR__.'/absent.xlsx'))->toThrow(UnreadableSkillWorkbook::class);
});

it('reports a missing guide level instead of dropping the row silently', function () {
    $path = alteredSkillWorkbook('xl/worksheets/sheet2.xml', fn ($xml) => preg_replace('/<x:row\b[^>]*\br="25"[^>]*>.*?<\/x:row>/s', '', $xml));
    try {
        $result = (new SkillWorkbookReader)->read($path);
        expect(array_column($result->levels, 'level'))->toBe(['1', '2', '3', '4', '5'])
            ->and($result->defects[0]->kind)->toBe('blank_key')
            ->and($result->defects[0]->cell)->toBe('A25');
    } finally {
        unlink($path);
        rmdir(dirname($path));
    }
});

it('ignores decorative merges and narrative outside the mapped table', function () {
    $path = alteredSkillWorkbook('xl/worksheets/sheet2.xml', fn ($xml) => str_replace(
        ['</x:sheetData>', '</x:worksheet>'],
        ['<x:row r="32"><x:c r="A32"><x:f>1+1</x:f><x:v>2</x:v></x:c></x:row></x:sheetData>', '<x:mergeCells><x:mergeCell ref="A1:F1"/></x:mergeCells></x:worksheet>'],
        $xml,
    ));
    try {
        $result = (new SkillWorkbookReader)->read($path);
        expect($result->levels)->toHaveCount(6)->and($result->defects)->toBe([]);
    } finally {
        unlink($path);
        rmdir(dirname($path));
    }
});
