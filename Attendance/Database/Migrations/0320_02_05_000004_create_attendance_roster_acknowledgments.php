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
        Schema::create('people_attendance_roster_acknowledgments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->dateTime('acknowledged_at');
            $table->timestamps();

            $table->unique(
                ['company_id', 'employee_id', 'period_start', 'period_end'],
                'people_att_roster_ack_unique_period',
            );
            $table->index(
                ['company_id', 'period_start', 'period_end'],
                'people_att_roster_ack_company_period_index',
            );
        });
        $this->registerTable('people_attendance_roster_acknowledgments');
    }

    public function down(): void
    {
        Schema::dropIfExists('people_attendance_roster_acknowledgments');
        $this->unregisterTable('people_attendance_roster_acknowledgments');
    }
};
