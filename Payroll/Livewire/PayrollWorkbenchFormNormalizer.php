<?php

namespace App\Modules\People\Payroll\Livewire;

use Illuminate\Validation\ValidationException;

final class PayrollWorkbenchFormNormalizer
{
    /**
     * @return array<string, mixed>
     */
    public static function jsonPayload(string $payload, string $field): array
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                $field => __('Enter a valid JSON object.'),
            ]);
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function optionalJsonPayload(?string $payload, string $field): ?array
    {
        if ($payload === null || trim($payload) === '') {
            return null;
        }

        return self::jsonPayload($payload, $field);
    }

    public static function blankToNull(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
