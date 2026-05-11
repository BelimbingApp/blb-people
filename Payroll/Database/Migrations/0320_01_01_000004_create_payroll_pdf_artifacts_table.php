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
        Schema::create('payroll_pdf_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('payroll_run_participant_id')->nullable()->constrained('payroll_run_participants')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('report_type')->index();
            $table->string('disk');
            $table->string('path');
            $table->string('template_version');
            $table->string('data_version');
            $table->unsignedBigInteger('bytes');
            $table->string('sha256', 64);
            $table->foreignId('produced_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('produced_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['payroll_run_id', 'report_type']);
            $table->index(['payroll_run_participant_id', 'report_type']);
            $table->unique(['disk', 'path']);
        });
        $this->registerTable('payroll_pdf_artifacts');
    }

    public function down(): void
    {
        $this->unregisterTable('payroll_pdf_artifacts');

        Schema::dropIfExists('payroll_pdf_artifacts');
    }
};
