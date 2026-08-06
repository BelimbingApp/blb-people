<?php

namespace App\Domains\People\Payroll\Data;

use App\Domains\People\Payroll\Models\PayrollEmployeeStatutoryProfile;
use App\Domains\People\Payroll\Models\PayrollEmployerStatutoryProfile;
use App\Domains\People\Payroll\Models\PayrollInput;
use App\Domains\People\Payroll\Models\PayrollRun;
use App\Domains\People\Payroll\Models\PayrollRunParticipant;
use Illuminate\Support\Collection;

class PayrollCalculationContext
{
    /**
     * @param  Collection<int, PayrollInput>  $inputs
     * @param  array<string, array<string, mixed>>  $classifications
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly PayrollRun $run,
        public readonly PayrollRunParticipant $participant,
        public readonly Collection $inputs,
        public readonly ?PayrollEmployerStatutoryProfile $employerProfile,
        public readonly ?PayrollEmployeeStatutoryProfile $employeeProfile,
        public readonly array $classifications = [],
        public readonly array $metadata = [],
    ) {}

    public function countryIso(): ?string
    {
        return $this->run->calendar?->country_iso;
    }

    public function payDate(): ?string
    {
        return $this->run->period?->pay_date?->toDateString();
    }
}
