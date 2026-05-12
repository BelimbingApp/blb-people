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
        Schema::create('payroll_pay_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('input_type')->index();
            $table->string('status')->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
        });
        $this->registerTable('payroll_pay_items');

        Schema::create('payroll_pay_item_classifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_pay_item_id')->constrained('payroll_pay_items')->cascadeOnDelete();
            $table->char('country_iso', 2)->nullable();
            $table->string('classification_key');
            $table->string('classification_value');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('source_pack')->nullable();
            $table->string('source_version')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique([
                'payroll_pay_item_id',
                'country_iso',
                'classification_key',
                'effective_from',
            ], 'payroll_pay_item_classifications_unique_effective_key');
            $table->index([
                'payroll_pay_item_id',
                'country_iso',
                'classification_key',
                'effective_from',
                'effective_to',
            ], 'payroll_pay_item_classifications_effective_index');
        });
        $this->registerTable('payroll_pay_item_classifications');
    }

    public function down(): void
    {
        $this->unregisterTable('payroll_pay_item_classifications');
        $this->unregisterTable('payroll_pay_items');

        Schema::dropIfExists('payroll_pay_item_classifications');
        Schema::dropIfExists('payroll_pay_items');
    }
};
