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
        Schema::create('people_attendance_roster_locks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            // Stored as Y-m-d strings; avoids datetime-format mismatch in SQLite tests
            $table->string('period_start');
            $table->string('period_end');
            $table->unsignedBigInteger('locked_by');
            $table->dateTime('locked_at');
            $table->text('unlock_reason')->nullable();
            $table->unsignedBigInteger('unlocked_by')->nullable();
            $table->dateTime('unlocked_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['company_id', 'period_start', 'period_end'],
                'people_att_roster_lock_unique_period',
            );
            $table->index(
                ['company_id', 'period_start', 'period_end', 'unlocked_at'],
                'people_att_roster_lock_active_idx',
            );
        });

        $this->registerTable('people_attendance_roster_locks');
    }

    public function down(): void
    {
        Schema::dropIfExists('people_attendance_roster_locks');
        $this->unregisterTable('people_attendance_roster_locks');
    }
};
