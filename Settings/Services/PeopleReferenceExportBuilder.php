<?php

namespace App\Modules\People\Settings\Services;

use App\Modules\People\Settings\Models\PeopleReferenceEntry;

class PeopleReferenceExportBuilder
{
    /**
     * @return array{filename: string, format: string, content_type: string, content: string}
     */
    public function csv(?int $companyId, string $type): array
    {
        $rows = PeopleReferenceEntry::query()
            ->where('company_id', $companyId)
            ->where('type', $type)
            ->orderBy('code')
            ->get()
            ->map(fn (PeopleReferenceEntry $entry): array => [
                'type' => $entry->type,
                'code' => $entry->code,
                'name' => $entry->name,
                'level' => $entry->level,
                'status' => $entry->status,
                'source_system' => $entry->source_system,
                'source_label' => $entry->source_label,
                'source_code' => $entry->source_code,
            ])
            ->all();

        return [
            'filename' => 'people-reference-'.$type.'.csv',
            'format' => 'csv',
            'content_type' => 'text/csv; charset=utf-8',
            'content' => $this->renderCsv([
                'type',
                'code',
                'name',
                'level',
                'status',
                'source_system',
                'source_label',
                'source_code',
            ], $rows),
        ];
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $rows
     */
    private function renderCsv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $header): string => (string) ($row[$header] ?? ''), $headers));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }
}
