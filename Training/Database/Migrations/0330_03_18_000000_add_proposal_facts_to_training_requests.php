<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people_training_requests', function (Blueprint $table): void {
            $table->string('proposed_delivery_method', 160)->nullable();
            $table->string('proposed_provider', 160)->nullable();
            $table->date('proposed_start_date')->nullable();
            $table->date('proposed_end_date')->nullable();
            $table->decimal('approved_budget', 19, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('people_training_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'proposed_delivery_method',
                'proposed_provider',
                'proposed_start_date',
                'proposed_end_date',
                'approved_budget',
            ]);
        });
    }
};
