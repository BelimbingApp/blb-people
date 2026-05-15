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
        Schema::create('people_attendance_adjustment_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('attendance_day_id')->nullable()->constrained('people_attendance_days')->nullOnDelete();
            $table->foreignId('corrects_clock_event_id')->nullable()->constrained('people_attendance_clock_events')->nullOnDelete();
            $table->foreignId('applied_clock_event_id')->nullable()->constrained('people_attendance_clock_events')->nullOnDelete();
            $table->string('request_mode'); // 'missing_punch' | 'correct_existing'
            $table->string('target_event_type'); // matches AttendanceClockEvent TYPE_*
            $table->string('status')->default('draft')->index();
            $table->dateTime('proposed_occurred_at');
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('submitted_by_user_id')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'status']);
            $table->index(['attendance_day_id', 'status']);
        });
        $this->registerTable('people_attendance_adjustment_requests');
    }

    public function down(): void
    {
        Schema::dropIfExists('people_attendance_adjustment_requests');
        $this->unregisterTable('people_attendance_adjustment_requests');
    }
};
