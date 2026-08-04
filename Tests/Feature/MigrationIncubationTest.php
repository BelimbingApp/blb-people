<?php

use App\Base\Database\Services\IncubatingMigrationFiles;

it('keeps cleanup migrations in their incubating schema chains', function (): void {
    $migrationFiles = app(IncubatingMigrationFiles::class);

    foreach ([
        '0320_02_05_000001_drop_day_type_overrides_from_attendance_shift_templates.php',
        '0320_03_01_000005_drop_people_payroll_pdf_artifacts_disk_path_unique.php',
        '0320_03_01_000008_drop_payroll_pay_item_code_from_attendance_allowance_rules.php',
        '0320_03_01_000010_drop_payroll_pay_item_code_from_leave_types.php',
        '0320_03_01_000012_drop_payroll_pay_item_code_from_claim_types.php',
    ] as $file) {
        expect($migrationFiles->fileIsIncubating($file))->toBeTrue();
    }
});
