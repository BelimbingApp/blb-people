<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The governed 30/60/90-day checkpoint offsets, per company, over time.
 *
 * Append-only: an offset change is prospective and the policy in force when an
 * event ended is the one that dates that event's checkpoints forever. Editing
 * a row in place would silently re-date checkpoints that have already opened,
 * been asked about and been answered, so the database refuses it rather than
 * relying on the service to remember.
 */
return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    private const TABLE = 'people_training_effectiveness_policies';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('company_entity_id');
            $table->timestamps();
            $table->unique(['id', 'tenant_id', 'company_entity_id'], 'ptep_owner_uq');
            $table->foreign(['company_entity_id', 'tenant_id'], 'ptep_company_fk')
                ->references(['id', 'tenant_id'])->on('companies')->restrictOnDelete();

            // Smallint: an offset is a handful of days to a couple of years,
            // and the service refuses anything below one day or out of order.
            $table->unsignedSmallInteger('day_30_offset');
            $table->unsignedSmallInteger('day_60_offset');
            $table->unsignedSmallInteger('day_90_offset');
            // A date, not a timestamp: the policy applies to an event that
            // ended on that day, and a time of day would make "on or before
            // the event end" depend on the hour the row was written.
            $table->date('effective_from');
            $table->unsignedBigInteger('set_by_user_id');
            $table->string('reason', 2000);

            // One policy per company per effective date; a correction on the
            // same day is a new date, not an overwrite.
            $table->unique(['tenant_id', 'company_entity_id', 'effective_from'], 'ptep_effective_uq');
        });

        $this->registerTable(self::TABLE);
        $this->guards();
    }

    public function down(): void
    {
        $this->unregisterTable(self::TABLE);
        Schema::dropIfExists(self::TABLE);

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS ptep_policy_append_only()');
        }
    }

    private function guards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION ptep_policy_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'training effectiveness policy rows are append-only';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER ptep_append_only BEFORE UPDATE OR DELETE
                    ON people_training_effectiveness_policies
                    FOR EACH ROW EXECUTE FUNCTION ptep_policy_append_only();
                SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER people_training_effectiveness_policies_UPDATE_guard
                BEFORE UPDATE ON people_training_effectiveness_policies
                BEGIN SELECT RAISE(ABORT, 'training effectiveness policy rows are append-only'); END;
                CREATE TRIGGER people_training_effectiveness_policies_DELETE_guard
                BEFORE DELETE ON people_training_effectiveness_policies
                BEGIN SELECT RAISE(ABORT, 'training effectiveness policy rows are append-only'); END;
                SQL);
        }
    }
};
