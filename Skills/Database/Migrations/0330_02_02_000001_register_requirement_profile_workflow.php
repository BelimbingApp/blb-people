<?php

use App\Base\Database\Concerns\RegistersSeeders;
use App\Domains\People\Skills\Database\Seeders\RequirementProfileWorkflowSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    use RegistersSeeders;

    public function up(): void
    {
        $this->registerSeeder(RequirementProfileWorkflowSeeder::class);
    }

    public function down(): void
    {
        $this->unregisterSeeder(RequirementProfileWorkflowSeeder::class);
    }
};
