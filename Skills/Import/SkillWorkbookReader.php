<?php

namespace App\Domains\People\Skills\Import;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/** Reads only the catalogue and explicit guide table mapped by plan 0006-a. */
final class SkillWorkbookReader
{
    private const string MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const string REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const array TABLES = [
        '02 Skill Catalogue' => [5, null, ['Skill ID', 'Department / Shared', 'Category', 'Skill / Competency', 'Definition / Standard', 'Safety or Quality Critical?', 'Minimum Evidence Guide', 'Default Assessment Method', 'Default Reassessment (Months)', 'Skill Owner', 'Active?']],
        '00 Guide' => [24, 30, ['Level', 'Name', 'Observable Standard', 'Independent Work', 'Train Others', 'Normal Decision']],
    ];

    public function read(string $path): SkillWorkbookResult
    {
        $local = realpath($path);
        if ($local === false || ! is_file($local) || ! is_readable($local) || filesize($local) > 16 * 1024 * 1024) {
            throw new UnreadableSkillWorkbook('A readable local workbook of at most 16 MiB is required.');
        }
        $hash = hash_file('sha256', $local);
        $zip = new ZipArchive;
        if ($zip->open($local, ZipArchive::RDONLY) !== true) {
            throw new UnreadableSkillWorkbook('The workbook is not a readable XLSX archive.');
        }

        try {
            if ($zip->numFiles > 256) {
                throw new UnreadableSkillWorkbook('The workbook contains too many archive parts.');
            }
            $relationships = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
            $parts = [];
            $strings = [];
            foreach ($relationships->query('/*[local-name()="Relationships"]/*[local-name()="Relationship"]') as $relation) {
                $type = $relation->getAttribute('Type');
                if (! in_array($type, [self::REL.'/worksheet', self::REL.'/sharedStrings'], true)) {
                    continue;
                }
                $target = $relation->getAttribute('Target');
                if ($relation->getAttribute('TargetMode') === 'External' ||
                    ! preg_match('~^/?(?:xl/)?[A-Za-z0-9_/-]+\.xml$~D', $target) ||
                    str_contains($target, '..')) {
                    throw new UnreadableSkillWorkbook('Workbook table relationships must reference internal XML parts.');
                }
                $part = str_starts_with($target, '/') ? substr($target, 1) : 'xl/'.$target;
                $id = $relation->getAttribute('Id');
                if (isset($parts[$id])) {
                    throw new UnreadableSkillWorkbook('Duplicate workbook relationship.');
                }
                $parts[$id] = $part;
                if ($type === self::REL.'/sharedStrings') {
                    foreach ($this->xml($zip, $part)->query('/s:sst/s:si') as $item) {
                        $strings[] = $this->text($item);
                    }
                }
            }
            $sheets = [];
            foreach ($this->xml($zip, 'xl/workbook.xml')->query('/s:workbook/s:sheets/s:sheet') as $sheet) {
                $name = $sheet->getAttribute('name');
                if (isset($sheets[$name])) {
                    throw new UnreadableSkillWorkbook('Duplicate workbook sheet name.');
                }
                $sheets[$name] = $parts[$sheet->getAttributeNS(self::REL, 'id')] ?? '';
            }
            $skills = $categories = $levels = $defects = [];
            foreach (self::TABLES as $name => [$header, $last, $headers]) {
                if (empty($sheets[$name])) {
                    throw new UnreadableSkillWorkbook('Missing required sheet: '.$name);
                }
                [$rows, $problems] = $this->table($this->xml($zip, $sheets[$name]), $strings, $name, $hash, $header, $last, $headers);
                array_push($defects, ...$problems);
                foreach ($rows as $number => $values) {
                    $source = new WorkbookSource($hash, $name, $number);
                    if ($name === '02 Skill Catalogue') {
                        $skills[] = new CatalogueSkillRow(...[...$values, $source]);
                        $categories[] = new CatalogueCategoryRow($values[2], $source);
                    } else {
                        $levels[] = new CatalogueLevelRow(...[...$values, $source]);
                    }
                }
            }
            clearstatcache(true, $local);
            if (hash_file('sha256', $local) !== $hash) {
                throw new UnreadableSkillWorkbook('The workbook changed while it was being read.');
            }

            return new SkillWorkbookResult($skills, $categories, $levels, $defects);
        } finally {
            $zip->close();
        }
    }

    private function xml(ZipArchive $zip, string $part): DOMXPath
    {
        $stat = $zip->statName($part);
        if ($stat === false || $stat['size'] > 8 * 1024 * 1024) {
            throw new UnreadableSkillWorkbook('Missing or oversized workbook XML part.');
        }
        $xml = $zip->getFromName($part);
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if ($xml === false || ! $document->loadXML($xml, LIBXML_NONET) || $document->doctype !== null) {
                throw new UnreadableSkillWorkbook('Invalid workbook XML; document types are forbidden.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', self::MAIN);

        return $xpath;
    }

    private function text(DOMElement $element): string
    {
        $text = '';
        foreach ($element->getElementsByTagNameNS(self::MAIN, 't') as $run) {
            $text .= $run->textContent;
        }

        return $text;
    }

    /** @return array{array<int, list<string>>, list<WorkbookDefect>} */
    private function table(DOMXPath $sheet, array $strings, string $name, string $hash, int $header, ?int $last, array $headers): array
    {
        $rows = $defects = $unsafe = [];
        $width = count($headers);
        foreach ($sheet->query('/s:worksheet/s:sheetData/s:row/s:c') as $cell) {
            $address = $cell->getAttribute('r');
            if (! preg_match('/^([A-Z]{1,3})([1-9][0-9]{0,6})$/D', $address, $match)) {
                throw new UnreadableSkillWorkbook('Invalid workbook cell address.');
            }
            $row = (int) $match[2];
            $column = $this->column($match[1]);
            if ($row < $header || ($last !== null && $row > $last) || $column > $width) {
                continue;
            }
            if (isset($rows[$row][$column])) {
                throw new UnreadableSkillWorkbook('Duplicate workbook cell address.');
            }
            $value = $cell->getElementsByTagNameNS(self::MAIN, 'v')->item(0)?->textContent ?? '';
            if ($cell->getElementsByTagNameNS(self::MAIN, 'f')->length > 0 || $cell->getAttribute('t') === 'e') {
                $defects[] = new WorkbookDefect($cell->getAttribute('t') === 'e' ? 'cell_error' : 'formula', $address, new WorkbookSource($hash, $name, $row));
                $unsafe[$row] = true;
                $value = '';
            } elseif ($cell->getAttribute('t') === 's') {
                if (! ctype_digit($value) || ! array_key_exists((int) $value, $strings)) {
                    throw new UnreadableSkillWorkbook('Invalid shared string reference.');
                }
                $value = $strings[(int) $value];
            } elseif ($cell->getAttribute('t') === 'inlineStr') {
                $value = $this->text($cell);
            }
            $rows[$row][$column] = $value;
        }
        foreach ($sheet->query('/s:worksheet/s:mergeCells/s:mergeCell') as $merge) {
            $range = $merge->getAttribute('ref');
            if (! preg_match('/^([A-Z]{1,3})([1-9][0-9]{0,6}):([A-Z]{1,3})([1-9][0-9]{0,6})$/D', $range, $match)) {
                throw new UnreadableSkillWorkbook('Invalid merged cell range.');
            }
            $first = max($header, (int) $match[2]);
            $end = min($last ?? max([$header, ...array_keys($rows)]), (int) $match[4]);
            if ($this->column($match[1]) > $width || $end < $first) {
                continue;
            }
            $defects[] = new WorkbookDefect('merged_cells', $range, new WorkbookSource($hash, $name, $first));
            foreach (array_keys($rows) as $number) {
                if ($number >= $first && $number <= $end) {
                    $unsafe[$number] = true;
                }
            }
        }
        if (isset($unsafe[$header]) || $this->values($rows[$header] ?? [], $width) !== $headers) {
            throw new UnreadableSkillWorkbook('Unexpected table header in '.$name.'.');
        }
        unset($rows[$header]);
        if ($last !== null) {
            foreach (range($header + 1, $last) as $number) {
                $rows[$number] ??= [];
            }
        }
        ksort($rows);
        $result = [];
        foreach ($rows as $number => $cells) {
            $values = $this->values($cells, $width);
            if ($last === null && count(array_filter($values, fn ($value) => trim($value) !== '')) === 0 && ! isset($unsafe[$number])) {
                continue;
            }
            foreach ($last === null ? [1, 3] : [1] as $key) {
                if (trim($values[$key - 1]) === '' && ! isset($unsafe[$number])) {
                    $defects[] = new WorkbookDefect('blank_key', chr(64 + $key).$number, new WorkbookSource($hash, $name, $number));
                    $unsafe[$number] = true;
                }
            }
            if (! isset($unsafe[$number])) {
                $result[$number] = $values;
            }
        }

        return [$result, $defects];
    }

    private function values(array $cells, int $width): array
    {
        return array_map(fn ($column) => $cells[$column] ?? '', range(1, $width));
    }

    private function column(string $letters): int
    {
        $value = 0;
        foreach (str_split($letters) as $letter) {
            $value = $value * 26 + ord($letter) - 64;
        }

        return $value;
    }
}
