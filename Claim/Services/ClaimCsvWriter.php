<?php

namespace App\Modules\People\Claim\Services;

final class ClaimCsvWriter
{
    /**
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $rows
     */
    public static function write(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                static fn (string $header): string => (string) ($row[$header] ?? ''),
                $headers,
            ));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }
}
