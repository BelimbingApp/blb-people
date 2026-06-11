<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    public function up(): void
    {
        Schema::create('people_payroll_employer_statutory_profiles', function (Blueprint $table): void {
            $this->addCommonStatutoryProfileColumns($table);
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->index([
                'company_id',
                'country_iso',
                'effective_from',
                'effective_to',
            ], 'people_payroll_employer_statutory_profiles_effective_index');
        });
        $this->registerTable('people_payroll_employer_statutory_profiles');

        Schema::create('people_payroll_employee_statutory_profiles', function (Blueprint $table): void {
            $this->addCommonStatutoryProfileColumns($table);
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->index([
                'employee_id',
                'country_iso',
                'effective_from',
                'effective_to',
            ], 'people_payroll_employee_statutory_profiles_effective_index');
            $table->index(['company_id', 'country_iso'], 'people_payroll_employee_stat_profiles_company_country_index');
        });
        $this->registerTable('people_payroll_employee_statutory_profiles');
    }

    public function down(): void
    {
        foreach (['people_payroll_employee_statutory_profiles', 'people_payroll_employer_statutory_profiles'] as $tableName) {
            $this->unregisterTable($tableName);
            Schema::dropIfExists($tableName);
        }
    }

    private function addCommonStatutoryProfileColumns(Blueprint $table): void
    {
        $table->id();
        $table->char('country_iso', 2);
        $table->string('source_pack');
        $table->string('source_version');
        $table->date('effective_from');
        $table->date('effective_to')->nullable();
        $table->json('profile_data');
        $table->json('validation_messages')->nullable();
        $table->json('metadata')->nullable();
        $table->timestamps();
    }
};
