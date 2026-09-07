<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Link an approved training request to a scheduled event (0010-d).
 *
 * The link is guarded twice below the store: the event must belong to the
 * same tenant and company (a composite foreign key on PostgreSQL, a
 * trigger on SQLite, where a composite key cannot be added to an existing
 * table), and a row may carry an event only while its status is
 * `approved` (a trigger on both drivers), so a raw update cannot link an
 * unapproved request either.
 *
 * Declares IncubatingSchema because it alters people_training_requests, which
 * 0330_03_05 creates as incubating. A stable forward onto an incubating table
 * is what IncubatingSchemaConflictException refuses: rebuilding the create
 * alone would drop this column and its guards while the ledger still claimed
 * they were applied. Joining the replay chain means they are dropped and
 * recreated with the table they belong to.
 */
return new class extends Migration
{
    use IncubatingSchema, RegistersTables;

    public function up(): void
    {
        Schema::table('people_training_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('training_event_id')->nullable()->after('status');
            $table->unsignedBigInteger('linked_by_user_id')->nullable()->after('training_event_id');
            $table->timestamp('linked_at')->nullable()->after('linked_by_user_id');
            $table->index(['tenant_id', 'company_entity_id', 'training_event_id'], 'ptr_request_event_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            Schema::table('people_training_requests', function (Blueprint $table): void {
                $table->foreign(['training_event_id', 'tenant_id', 'company_entity_id'], 'ptr_request_event_fk')
                    ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_connector_training_events')->restrictOnDelete();
            });
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION ptr_request_link_guard() RETURNS trigger AS $$
                BEGIN
                    IF NEW.training_event_id IS NOT NULL AND NEW.status <> 'approved' THEN
                        RAISE EXCEPTION 'only an approved training request can be linked to an event';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER ptr_request_link_guard BEFORE INSERT OR UPDATE ON people_training_requests
                FOR EACH ROW EXECUTE FUNCTION ptr_request_link_guard();
                SQL);
        } elseif (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER ptr_request_link_status_insert_guard BEFORE INSERT ON people_training_requests
                WHEN NEW.training_event_id IS NOT NULL AND NEW.status <> 'approved'
                BEGIN SELECT RAISE(ABORT, 'only an approved training request can be linked to an event'); END;
                CREATE TRIGGER ptr_request_link_status_update_guard BEFORE UPDATE ON people_training_requests
                WHEN NEW.training_event_id IS NOT NULL AND NEW.status <> 'approved'
                BEGIN SELECT RAISE(ABORT, 'only an approved training request can be linked to an event'); END;
                CREATE TRIGGER ptr_request_link_owner_insert_guard BEFORE INSERT ON people_training_requests
                WHEN NEW.training_event_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM people_connector_training_events e
                    WHERE e.id = NEW.training_event_id AND e.tenant_id = NEW.tenant_id AND e.company_entity_id = NEW.company_entity_id)
                BEGIN SELECT RAISE(ABORT, 'a training request links only an event of its own tenant and company'); END;
                CREATE TRIGGER ptr_request_link_owner_update_guard BEFORE UPDATE ON people_training_requests
                WHEN NEW.training_event_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM people_connector_training_events e
                    WHERE e.id = NEW.training_event_id AND e.tenant_id = NEW.tenant_id AND e.company_entity_id = NEW.company_entity_id)
                BEGIN SELECT RAISE(ABORT, 'a training request links only an event of its own tenant and company'); END;
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS ptr_request_link_guard ON people_training_requests');
            DB::unprepared('DROP FUNCTION IF EXISTS ptr_request_link_guard()');
            Schema::table('people_training_requests', function (Blueprint $table): void {
                $table->dropForeign('ptr_request_event_fk');
            });
        } elseif (DB::connection()->getDriverName() === 'sqlite') {
            foreach (['status_insert', 'status_update', 'owner_insert', 'owner_update'] as $guard) {
                DB::unprepared("DROP TRIGGER IF EXISTS ptr_request_link_{$guard}_guard");
            }
        }
        Schema::table('people_training_requests', function (Blueprint $table): void {
            $table->dropIndex('ptr_request_event_idx');
            $table->dropColumn(['training_event_id', 'linked_by_user_id', 'linked_at']);
        });
    }
};
