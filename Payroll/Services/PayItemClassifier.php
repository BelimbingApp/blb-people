<?php
namespace App\Modules\People\Payroll\Services;

use App\Modules\People\Payroll\Models\PayrollPayItem;
use Illuminate\Support\Carbon;

class PayItemClassifier
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function classificationsFor(PayrollPayItem $payItem, ?string $countryIso, Carbon|string $onDate): array
    {
        $date = $onDate instanceof Carbon ? $onDate->toDateString() : $onDate;

        return $payItem->classifications()
            ->where(function ($query) use ($countryIso): void {
                $query->whereNull('country_iso');

                if ($countryIso !== null) {
                    $query->orWhere('country_iso', strtoupper($countryIso));
                }
            })
            ->where('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date);
            })
            ->orderByRaw('country_iso is null')
            ->orderByDesc('effective_from')
            ->get()
            ->unique('classification_key')
            ->mapWithKeys(fn ($classification): array => [
                $classification->classification_key => [
                    'value' => $classification->classification_value,
                    'country_iso' => $classification->country_iso,
                    'effective_from' => $classification->effective_from->toDateString(),
                    'effective_to' => $classification->effective_to?->toDateString(),
                    'source_pack' => $classification->source_pack,
                    'source_version' => $classification->source_version,
                    'metadata' => $classification->metadata ?? [],
                ],
            ])
            ->all();
    }
}
