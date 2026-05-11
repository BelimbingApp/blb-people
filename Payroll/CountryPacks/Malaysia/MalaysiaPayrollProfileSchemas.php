<?php

namespace App\Modules\People\Payroll\CountryPacks\Malaysia;

use App\Modules\People\Payroll\Contracts\ProvidesPayrollProfileSchemas;
use App\Modules\People\Payroll\Data\ProfileSchema;

class MalaysiaPayrollProfileSchemas implements ProvidesPayrollProfileSchemas
{
    public function employerSchema(): ProfileSchema
    {
        return new ProfileSchema(
            countryIso: MalaysiaPayrollCountryPack::COUNTRY_ISO,
            profileType: 'employer',
            sourcePack: MalaysiaPayrollCountryPack::PACK_IDENTIFIER,
            sourceVersion: MalaysiaPayrollCountryPack::PACK_VERSION,
            fields: [
                ['key' => 'epf_employer_number', 'label' => 'EPF employer number', 'required' => true],
                ['key' => 'socso_employer_number', 'label' => 'SOCSO employer number', 'required' => true],
                ['key' => 'lhdn_employer_number', 'label' => 'LHDN employer number', 'required' => true],
                ['key' => 'hrd_levy_applicable', 'label' => 'HRD levy applicable', 'required' => true, 'type' => 'boolean'],
                ['key' => 'zakat_authority', 'label' => 'Zakat authority', 'required' => false],
            ],
        );
    }

    public function employeeSchema(): ProfileSchema
    {
        return new ProfileSchema(
            countryIso: MalaysiaPayrollCountryPack::COUNTRY_ISO,
            profileType: 'employee',
            sourcePack: MalaysiaPayrollCountryPack::PACK_IDENTIFIER,
            sourceVersion: MalaysiaPayrollCountryPack::PACK_VERSION,
            fields: [
                ['key' => 'citizenship_status', 'label' => 'Citizenship status', 'required' => true],
                ['key' => 'tax_residency', 'label' => 'Tax residency', 'required' => true],
                ['key' => 'epf_number', 'label' => 'EPF number', 'required' => false],
                ['key' => 'socso_number', 'label' => 'SOCSO number', 'required' => false],
                ['key' => 'tax_number', 'label' => 'LHDN tax number', 'required' => false],
                ['key' => 'zakat_salary_deduction_authorized', 'label' => 'Zakat salary deduction authorized', 'required' => false, 'type' => 'boolean'],
            ],
        );
    }
}
