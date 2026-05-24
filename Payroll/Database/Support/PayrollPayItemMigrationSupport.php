<?php

namespace App\Modules\People\Payroll\Database\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait PayrollPayItemMigrationSupport
{
    private function createPayrollPayItemMappingTable(
        string $tableName,
        string $ownerColumn,
        string $ownerTable,
        ?string $foreignIndexName,
        string $uniqueIndexName,
        string $companyIndexName,
        bool $companyNullable = false,
    ): void {
        Schema::create($tableName, function (Blueprint $table) use (
            $ownerColumn,
            $ownerTable,
            $foreignIndexName,
            $uniqueIndexName,
            $companyIndexName,
            $companyNullable,
        ): void {
            $table->id();

            $company = $table->foreignId('company_id');
            if ($companyNullable) {
                $company->nullable();
            }
            $company->constrained('companies')->cascadeOnDelete();

            $owner = $table->foreignId($ownerColumn);
            if ($foreignIndexName === null) {
                $owner->constrained($ownerTable)->cascadeOnDelete();
            } else {
                $owner->constrained($ownerTable, indexName: $foreignIndexName)->cascadeOnDelete();
            }
            $table->string('payroll_pay_item_code');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique([$ownerColumn, 'effective_from'], $uniqueIndexName);
            $table->index(['company_id', $ownerColumn], $companyIndexName);
        });

        $this->registerTable($tableName);
    }

    private function copyLegacyPayItemCodes(
        string $sourceTable,
        string $mappingTable,
        string $mappingOwnerColumn,
        string $migratedFrom,
        ?string $effectiveFromColumn = null,
        string $defaultEffectiveFrom = '2026-01-01',
    ): void {
        if (! Schema::hasColumn($sourceTable, 'payroll_pay_item_code')) {
            return;
        }

        $now = now();
        $columns = ['id', 'company_id', 'payroll_pay_item_code'];
        if ($effectiveFromColumn !== null) {
            $columns[] = $effectiveFromColumn;
        }

        $records = DB::table($sourceTable)
            ->whereNotNull('payroll_pay_item_code')
            ->where('payroll_pay_item_code', '!=', '')
            ->get($columns);

        foreach ($records as $record) {
            DB::table($mappingTable)->insert([
                'company_id' => $record->company_id,
                $mappingOwnerColumn => $record->id,
                'payroll_pay_item_code' => $record->payroll_pay_item_code,
                'effective_from' => $effectiveFromColumn !== null
                    ? $record->{$effectiveFromColumn}
                    : $defaultEffectiveFrom,
                'effective_to' => null,
                'metadata' => json_encode(['migrated_from' => $migratedFrom]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function dropLegacyPayItemCodeColumn(string $tableName, ?callable $beforeDrop = null): void
    {
        if (! Schema::hasColumn($tableName, 'payroll_pay_item_code')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($beforeDrop): void {
            if ($beforeDrop !== null) {
                $beforeDrop($table);
            }

            $table->dropColumn('payroll_pay_item_code');
        });
    }

    private function restoreLegacyPayItemCodeColumn(string $tableName, string $afterColumn, ?callable $afterRestore = null): void
    {
        if (Schema::hasColumn($tableName, 'payroll_pay_item_code')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($afterColumn, $afterRestore): void {
            $table->string('payroll_pay_item_code')->nullable()->after($afterColumn);

            if ($afterRestore !== null) {
                $afterRestore($table);
            }
        });
    }
}
