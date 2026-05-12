<?php

namespace App\Modules\People\Settings\Services;

use App\Modules\People\Settings\Models\PeopleImportJob;
use App\Modules\People\Settings\Models\PeopleReferenceAlias;
use App\Modules\People\Settings\Models\PeopleReferenceEntry;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class PeopleReferenceImportService
{
    /**
     * @return list<array<string, string>>
     */
    public function rowsFromContent(string $contents, string $filename): array
    {
        return Str::endsWith(Str::lower($filename), '.xlsx')
            ? $this->rowsFromXlsx($contents)
            : $this->rowsFromCsv($contents);
    }

    /**
     * @return list<array<string, string>>
     */
    public function rowsFromCsv(string $contents): array
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('Unable to allocate temporary CSV stream.');
        }

        fwrite($handle, $contents);
        rewind($handle);

        $headers = null;
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                $headers = array_map(fn (?string $header): string => trim((string) $header), $row);

                continue;
            }

            if ($row === [null] || $row === []) {
                continue;
            }

            $rows[] = $this->combineRow($headers, $row);
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function import(
        ?int $companyId,
        string $targetType,
        array $rows,
        bool $dryRun = true,
        string $sourceSystem = 'ipayroll',
        ?string $sourceLabel = null,
        ?int $createdByUserId = null,
    ): PeopleImportJob {
        $sourceLabel ??= $targetType;
        $rowResults = [];
        $seenCodes = [];
        $seenNames = [];
        $accepted = 0;
        $warnings = 0;
        $errors = 0;

        foreach ($rows as $index => $row) {
            $result = $this->validateRow($companyId, $targetType, $row, $seenCodes, $seenNames);
            $rowResults[] = ['row' => $index + 1, ...$result];

            if ($result['status'] === 'error') {
                $errors++;

                continue;
            }

            if ($result['warnings'] !== []) {
                $warnings++;
            }

            $accepted++;
            $seenCodes[$result['code']] = true;
            $seenNames[$this->nameKey($result['name'])] = $result['code'];

            if (! $dryRun) {
                $entry = PeopleReferenceEntry::query()->updateOrCreate(
                    [
                        'company_id' => $companyId,
                        'type' => $targetType,
                        'code' => $result['code'],
                    ],
                    [
                        'name' => $result['name'],
                        'level' => $this->nullableString($row['level'] ?? null),
                        'description' => $this->nullableString($row['description'] ?? $row['Description'] ?? null),
                        'status' => $this->statusFromRow($row),
                        'source_system' => $sourceSystem,
                        'source_label' => $sourceLabel,
                        'source_code' => $result['source_code'],
                        'metadata' => $this->metadataFromRow($row),
                    ],
                );

                PeopleReferenceAlias::query()->updateOrCreate(
                    [
                        'company_id' => $companyId,
                        'source_system' => $sourceSystem,
                        'source_type' => $sourceLabel,
                        'source_code' => $result['source_code'],
                    ],
                    [
                        'people_reference_entry_id' => $entry->id,
                        'source_label' => $result['source_label'],
                        'status' => PeopleReferenceEntry::STATUS_ACTIVE,
                        'metadata' => ['imported_target_type' => $targetType],
                    ],
                );
            }
        }

        return PeopleImportJob::query()->create([
            'company_id' => $companyId,
            'created_by_user_id' => $createdByUserId,
            'source_system' => $sourceSystem,
            'source_label' => $sourceLabel,
            'target_type' => $targetType,
            'dry_run' => $dryRun,
            'status' => $errors > 0 ? PeopleImportJob::STATUS_FAILED : ($dryRun ? PeopleImportJob::STATUS_VALIDATED : PeopleImportJob::STATUS_IMPORTED),
            'summary' => [
                'total_rows' => count($rows),
                'accepted_rows' => $accepted,
                'warning_rows' => $warnings,
                'error_rows' => $errors,
            ],
            'row_results' => $rowResults,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, bool>  $seenCodes
     * @param  array<string, string>  $seenNames
     * @return array<string, mixed>
     */
    private function validateRow(?int $companyId, string $targetType, array $row, array $seenCodes, array $seenNames): array
    {
        $sourceCode = $this->stringValue($row['source_code'] ?? $row['Code'] ?? $row['code'] ?? null);
        $code = Str::upper($this->stringValue($row['target_code'] ?? $row['code'] ?? $row['Code'] ?? $sourceCode));
        $name = $this->stringValue($row['name'] ?? $row['Description'] ?? $row['description'] ?? $row['Desc'] ?? null);
        $warnings = [];
        $errors = [];

        if ($code === '') {
            $errors[] = 'missing_code';
        }

        if ($name === '') {
            $errors[] = 'missing_name';
        }

        if (isset($seenCodes[$code])) {
            $errors[] = 'duplicate_code_in_import';
        }

        $nameKey = $this->nameKey($name);

        if ($nameKey !== '' && isset($seenNames[$nameKey]) && $seenNames[$nameKey] !== $code) {
            $warnings[] = 'duplicate_name_in_import';
        }

        $existing = PeopleReferenceEntry::query()
            ->where('company_id', $companyId)
            ->where('type', $targetType)
            ->where('code', '!=', $code)
            ->get(['code', 'name'])
            ->first(fn (PeopleReferenceEntry $entry): bool => $this->nameKey($entry->name) === $nameKey);

        if ($existing !== null) {
            $warnings[] = 'similar_name_exists:'.$existing->code;
        }

        return [
            'status' => $errors === [] ? 'accepted' : 'error',
            'code' => $code,
            'name' => $name,
            'source_code' => $sourceCode !== '' ? $sourceCode : $code,
            'source_label' => $this->stringValue($row['source_label'] ?? $row['Description'] ?? $row['description'] ?? $name),
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function metadataFromRow(array $row): array
    {
        $metadata = $row;
        unset(
            $metadata['code'],
            $metadata['Code'],
            $metadata['target_code'],
            $metadata['source_code'],
            $metadata['name'],
            $metadata['Description'],
            $metadata['description'],
            $metadata['Desc'],
            $metadata['level'],
        );

        return ['source_row' => $metadata];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function statusFromRow(array $row): string
    {
        $active = $row['active'] ?? $row['Active'] ?? null;

        if ($active === null || $active === '') {
            return PeopleReferenceEntry::STATUS_ACTIVE;
        }

        return filter_var($active, FILTER_VALIDATE_BOOLEAN) ? PeopleReferenceEntry::STATUS_ACTIVE : PeopleReferenceEntry::STATUS_INACTIVE;
    }

    private function nameKey(string $name): string
    {
        return (string) Str::of($name)->lower()->replaceMatches('/[^a-z0-9]+/', '')->trim();
    }

    private function stringValue(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = $this->stringValue($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<array<string, string>>
     */
    private function rowsFromXlsx(string $contents): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('XLSX imports require the PHP zip extension.');
        }

        $path = tempnam(sys_get_temp_dir(), 'blb-people-import-');

        if ($path === false) {
            throw new RuntimeException('Unable to create temporary XLSX file.');
        }

        file_put_contents($path, $contents);

        try {
            $zip = new ZipArchive;

            if ($zip->open($path) !== true) {
                throw new RuntimeException('Unable to open XLSX import.');
            }

            try {
                $strings = $this->sharedStrings($zip);
                $sheetPath = $this->firstSheetPath($zip);
                $sheetXml = $zip->getFromName($sheetPath);

                if ($sheetXml === false) {
                    throw new RuntimeException('XLSX import has no readable first sheet.');
                }

                return $this->rowsFromSheetXml($sheetXml, $strings);
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $root = new SimpleXMLElement($xml);
        $root->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $strings = [];

        foreach ($root->xpath('//m:si') ?: [] as $node) {
            $node->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $texts = [];

            foreach ($node->xpath('.//m:t') ?: [] as $text) {
                $texts[] = (string) $text;
            }

            $strings[] = implode('', $texts);
        }

        return $strings;
    }

    private function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook === false || $rels === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $workbookXml = new SimpleXMLElement($workbook);
        $workbookXml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbookXml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $sheet = ($workbookXml->xpath('//m:sheets/m:sheet') ?: [])[0] ?? null;

        if ($sheet === null) {
            return 'xl/worksheets/sheet1.xml';
        }

        $attributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $relationshipId = (string) ($attributes['id'] ?? '');
        $relsXml = new SimpleXMLElement($rels);

        foreach ($relsXml->Relationship as $relationship) {
            if ((string) $relationship['Id'] !== $relationshipId) {
                continue;
            }

            $target = (string) $relationship['Target'];

            return str_starts_with($target, 'xl/') ? $target : 'xl/'.ltrim($target, '/');
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return list<array<string, string>>
     */
    private function rowsFromSheetXml(string $xml, array $sharedStrings): array
    {
        $root = new SimpleXMLElement($xml);
        $root->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rawRows = [];

        foreach ($root->xpath('//m:sheetData/m:row') ?: [] as $rowNode) {
            $rowNode->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $row = [];

            foreach ($rowNode->xpath('m:c') ?: [] as $cell) {
                $cellRef = (string) ($cell['r'] ?? 'A1');
                $index = $this->columnIndex($cellRef);
                $row[$index] = $this->cellValue($cell, $sharedStrings);
            }

            if ($row !== []) {
                ksort($row);
                $rawRows[] = $row;
            }
        }

        if ($rawRows === []) {
            return [];
        }

        $headers = array_map(fn (mixed $value): string => trim((string) $value), $rawRows[0]);
        $rows = [];

        foreach (array_slice($rawRows, 1) as $row) {
            $rows[] = $this->combineRow($headers, $row);
        }

        return $rows;
    }

    /**
     * @param  list<string>  $headers
     * @param  array<int, mixed>  $row
     * @return array<string, string>
     */
    private function combineRow(array $headers, array $row): array
    {
        $combined = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $combined[$header] = trim((string) ($row[$index] ?? ''));
        }

        return $combined;
    }

    /**
     * @param  list<string>  $sharedStrings
     */
    private function cellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $cell->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $type = (string) ($cell['t'] ?? '');

        if ($type === 'inlineStr') {
            $texts = [];

            foreach ($cell->xpath('.//m:t') ?: [] as $text) {
                $texts[] = (string) $text;
            }

            return implode('', $texts);
        }

        $value = (string) (($cell->xpath('m:v') ?: [])[0] ?? '');

        if ($type === 's') {
            return $sharedStrings[(int) $value] ?? '';
        }

        return $value;
    }

    private function columnIndex(string $cellRef): int
    {
        preg_match('/^[A-Z]+/i', $cellRef, $matches);
        $letters = strtoupper($matches[0] ?? 'A');
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index - 1;
    }
}
