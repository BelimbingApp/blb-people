<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people_attendance_allowance_rules', function (Blueprint $table): void {
            $table->foreignId('attendance_shift_template_id')
                ->nullable()
                ->after('attendance_policy_group_id')
                ->constrained('people_attendance_shift_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('people_attendance_allowance_rules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('attendance_shift_template_id');
        });
    }
};
