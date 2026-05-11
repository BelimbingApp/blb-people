<?php
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use RegistersTables;

    public function up(): void
    {
        Schema::create('payroll_employer_statutory_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->char('country_iso', 2);
            $table->string('source_pack');
            $table->string('source_version');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('profile_data');
            $table->json('validation_messages')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index([
                'company_id',
                'country_iso',
                'effective_from',
                'effective_to',
            ], 'payroll_employer_statutory_profiles_effective_index');
        });
        $this->registerTable('payroll_employer_statutory_profiles');

        Schema::create('payroll_employee_statutory_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->char('country_iso', 2);
            $table->string('source_pack');
            $table->string('source_version');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('profile_data');
            $table->json('validation_messages')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index([
                'employee_id',
                'country_iso',
                'effective_from',
                'effective_to',
            ], 'payroll_employee_statutory_profiles_effective_index');
            $table->index(['company_id', 'country_iso'], 'payroll_employee_statutory_profiles_company_country_index');
        });
        $this->registerTable('payroll_employee_statutory_profiles');
    }

    public function down(): void
    {
        $this->unregisterTable('payroll_employee_statutory_profiles');
        $this->unregisterTable('payroll_employer_statutory_profiles');

        Schema::dropIfExists('payroll_employee_statutory_profiles');
        Schema::dropIfExists('payroll_employer_statutory_profiles');
    }
};
