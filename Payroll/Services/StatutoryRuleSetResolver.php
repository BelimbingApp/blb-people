<?php

namespace App\Domains\People\Payroll\Services;

use App\Domains\People\Payroll\Models\PayrollStatutoryRuleSet;
use Illuminate\Support\Carbon;

class StatutoryRuleSetResolver
{
    public function resolve(string $countryIso, string $ruleKey, Carbon|string $onDate): ?PayrollStatutoryRuleSet
    {
        $date = $onDate instanceof Carbon ? $onDate->toDateString() : $onDate;

        return PayrollStatutoryRuleSet::query()
            ->with('rows')
            ->where('country_iso', strtoupper($countryIso))
            ->where('rule_key', $ruleKey)
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->first();
    }
}
