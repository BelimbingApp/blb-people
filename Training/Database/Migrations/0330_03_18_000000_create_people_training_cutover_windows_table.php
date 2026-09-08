<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Declared cutover windows: which writer is authoritative for a workflow
 * between two instants (0015-f). Append-only so who declared a window, and
 * when, survives; overlapping windows for the same workflow are refused in
 * the service before insert.
 */
return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    private const TABLE = 'people_training_cutover_windows';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('company_entity_id');
            $table->timestamps();
            $table->unique(['id', 'tenant_id', 'company_entity_id'], 'ptcw_owner_uq');
            $table->foreign(['company_entity_id', 'tenant_id'], 'ptcw_company_fk')
                ->references(['id', 'tenant_id'])->on('companies')->restrictOnDelete();

            $table->string('workflow', 64);
            $table->string('writer', 16);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->unsignedBigInteger('declared_by_user_id');
            $table->timestamp('declared_at');
            $table->string('reason', 2000);

            $table->index(['tenant_id', 'company_entity_id', 'workflow', 'starts_at'], 'ptcw_lookup_idx');
        });

        $this->registerTable(self::TABLE);
        $this->guards();
    }

    public function down(): void
    {
        $this->unregisterTable(self::TABLE);
        Schema::dropIfExists(self::TABLE);

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS ptcw_cutover_append_only()');
        }
    }

    private function guards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION ptcw_cutover_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'training cutover windows are append-only';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER ptcw_append_only BEFORE UPDATE OR DELETE
                    ON people_training_cutover_windows
                    FOR EACH ROW EXECUTE FUNCTION ptcw_cutover_append_only();
                SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER people_training_cutover_windows_UPDATE_guard
                BEFORE UPDATE ON people_training_cutover_windows
                BEGIN SELECT RAISE(ABORT, 'training cutover windows are append-only'); END;
                CREATE TRIGGER people_training_cutover_windows_DELETE_guard
                BEFORE DELETE ON people_training_cutover_windows
                BEGIN SELECT RAISE(ABORT, 'training cutover windows are append-only'); END;
                SQL);
        }
    }
};
