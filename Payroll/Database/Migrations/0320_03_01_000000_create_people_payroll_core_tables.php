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
        $this->createRegisteredTable('people_payroll_calendars', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->string('code');
            $table->string('name');
            $table->char('country_iso', 2);
            $table->char('currency', 3);
            $table->string('frequency');
            $table->string('status')->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
        });

        $this->createRegisteredTable('people_payroll_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_calendar_id')->constrained('people_payroll_calendars')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->date('pay_date');
            $table->string('status')->default('open')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['payroll_calendar_id', 'code']);
            $table->index(['payroll_calendar_id', 'starts_on', 'ends_on']);
        });

        $this->createRegisteredTable('people_payroll_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('payroll_calendar_id')->constrained('people_payroll_calendars');
            $table->foreignId('payroll_period_id')->constrained('people_payroll_periods');
            $table->string('code');
            $table->string('name');
            $table->string('status')->default('draft')->index();
            $table->char('currency', 3);
            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
            $table->index(['payroll_period_id', 'status']);
        });

        $this->createRegisteredTable('people_payroll_run_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('people_payroll_runs')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('employee_id')->constrained('employees');
            $table->string('status')->default('included')->index();
            $table->decimal('gross_pay', 19, 4)->default(0);
            $table->decimal('total_deductions', 19, 4)->default(0);
            $table->decimal('total_reimbursements', 19, 4)->default(0);
            $table->decimal('net_pay', 19, 4)->default(0);
            $table->char('currency', 3);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
            $table->index(['company_id', 'employee_id']);
        });

        $this->createRegisteredTable('people_payroll_inputs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('people_payroll_runs')->cascadeOnDelete();
            $table->foreignId('payroll_run_participant_id')->constrained('people_payroll_run_participants')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('pay_item_code')->index();
            $table->string('label');
            $table->string('input_type')->index();
            $table->decimal('quantity', 19, 4)->nullable();
            $table->decimal('rate', 19, 4)->nullable();
            $table->decimal('amount', 19, 4);
            $table->char('currency', 3);
            $table->date('occurred_on')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['payroll_run_id', 'input_type']);
            $table->index(['source_type', 'source_id']);
        });

        $this->createRegisteredTable('people_payroll_result_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('people_payroll_runs')->cascadeOnDelete();
            $table->foreignId('payroll_run_participant_id')->constrained('people_payroll_run_participants')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees');
            $table->foreignId('payroll_input_id')->nullable()->constrained('people_payroll_inputs')->nullOnDelete();
            $table->string('line_type')->index();
            $table->string('code')->index();
            $table->string('label');
            $table->decimal('amount', 19, 4)->default(0);
            $table->char('currency', 3);
            $table->string('source_rule')->nullable();
            $table->string('source_version')->nullable();
            $table->json('explanation')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['payroll_run_id', 'line_type']);
            $table->index(['payroll_run_participant_id', 'line_type']);
        });

        $this->createRegisteredTable('people_payroll_run_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('people_payroll_runs')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action')->index();
            $table->string('message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['payroll_run_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        foreach ([
            'people_payroll_run_audit_events',
            'people_payroll_result_lines',
            'people_payroll_inputs',
            'people_payroll_run_participants',
            'people_payroll_runs',
            'people_payroll_periods',
            'people_payroll_calendars',
        ] as $tableName) {
            $this->unregisterTable($tableName);
            Schema::dropIfExists($tableName);
        }
    }

    /**
     * @param  callable(Blueprint):void  $blueprint
     */
    private function createRegisteredTable(string $tableName, callable $blueprint): void
    {
        Schema::create($tableName, $blueprint);
        $this->registerTable($tableName);
    }
};
