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
        Schema::create('people_attendance_roster_cell_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('assignment_id')->nullable();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->string('action'); // created | updated | deleted | locked
            $table->unsignedBigInteger('previous_shift_id')->nullable();
            $table->unsignedBigInteger('previous_policy_id')->nullable();
            $table->unsignedBigInteger('new_shift_id')->nullable();
            $table->unsignedBigInteger('new_policy_id')->nullable();
            $table->text('note')->nullable();
            $table->string('job', 191)->nullable();
            $table->timestamp('changed_at')->useCurrent();
            $table->timestamps();

            $table->index(
                ['company_id', 'employee_id', 'date', 'changed_at'],
                'people_att_roster_cell_log_cell_idx',
            );
            $table->index(
                ['company_id', 'assignment_id'],
                'people_att_roster_cell_log_assign_idx',
            );
        });
        $this->registerTable('people_attendance_roster_cell_log');
    }

    public function down(): void
    {
        Schema::dropIfExists('people_attendance_roster_cell_log');
        $this->unregisterTable('people_attendance_roster_cell_log');
    }
};
