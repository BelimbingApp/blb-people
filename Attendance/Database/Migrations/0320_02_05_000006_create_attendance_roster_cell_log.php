<?php

use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use RegistersTables;

    public function up(): void
    {
        Schema::dropIfExists('people_attendance_roster_cell_log');
        $this->unregisterTable('people_attendance_roster_cell_log');
    }

    public function down(): void
    {
        Schema::dropIfExists('people_attendance_roster_cell_log');
        $this->unregisterTable('people_attendance_roster_cell_log');
    }
};
