<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people_attendance_shift_templates', function (Blueprint $table): void {
            $table->dropColumn('day_type_overrides');
        });
    }

    public function down(): void
    {
        Schema::table('people_attendance_shift_templates', function (Blueprint $table): void {
            $table->json('day_type_overrides')->nullable()->after('break_windows');
        });
    }
};
