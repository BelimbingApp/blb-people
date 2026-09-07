<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Participant withdrawal for the training calendar (0005-g).
 *
 * Participant rows are trigger-immutable: history must never be rewritten.
 * Withdrawal therefore marks the row instead of deleting it. The guards
 * below permit exactly one mutation on this table: setting (or clearing,
 * on re-enrolment) the withdrawal marker. Every other UPDATE or any
 * DELETE still raises, on both drivers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people_training_participants', function (Blueprint $table): void {
            $table->timestamp('withdrawn_at')->nullable();
            $table->unsignedBigInteger('withdrawn_by_user_id')->nullable();
        });

        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION pt_participation_immutable() RETURNS trigger AS $$
                BEGIN
                    IF TG_TABLE_NAME = 'people_training_participants' AND TG_OP = 'UPDATE'
                        AND OLD.tenant_id IS NOT DISTINCT FROM NEW.tenant_id
                        AND OLD.company_entity_id IS NOT DISTINCT FROM NEW.company_entity_id
                        AND OLD.event_id IS NOT DISTINCT FROM NEW.event_id
                        AND OLD.provider_id IS NOT DISTINCT FROM NEW.provider_id
                        AND OLD.employee_subject_id IS NOT DISTINCT FROM NEW.employee_subject_id
                        AND OLD.workforce_observed_at IS NOT DISTINCT FROM NEW.workforce_observed_at
                        AND OLD.created_at IS NOT DISTINCT FROM NEW.created_at THEN
                        RETURN NEW;
                    END IF;
                    IF TG_TABLE_NAME <> 'people_training_participation_facts' THEN
                        RAISE EXCEPTION 'participation identity and sessions are immutable';
                    END IF;
                    IF OLD.confirmed_at IS NOT NULL THEN
                        RAISE EXCEPTION 'participation identity, sessions and confirmed facts are immutable';
                    END IF;
                    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS people_training_participants_UPDATE_guard');
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER people_training_participants_UPDATE_guard BEFORE UPDATE ON people_training_participants
                WHEN OLD.tenant_id IS NOT NEW.tenant_id
                    OR OLD.company_entity_id IS NOT NEW.company_entity_id
                    OR OLD.event_id IS NOT NEW.event_id
                    OR OLD.provider_id IS NOT NEW.provider_id
                    OR OLD.employee_subject_id IS NOT NEW.employee_subject_id
                    OR OLD.workforce_observed_at IS NOT NEW.workforce_observed_at
                    OR OLD.created_at IS NOT NEW.created_at
                BEGIN SELECT RAISE(ABORT, 'participation identity and sessions are immutable'); END;
                SQL);
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION pt_participation_immutable() RETURNS trigger AS $$
                BEGIN
                    IF TG_TABLE_NAME <> 'people_training_participation_facts' THEN
                        RAISE EXCEPTION 'participation identity and sessions are immutable';
                    END IF;
                    IF OLD.confirmed_at IS NOT NULL THEN
                        RAISE EXCEPTION 'participation identity, sessions and confirmed facts are immutable';
                    END IF;
                    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS people_training_participants_UPDATE_guard');
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER people_training_participants_UPDATE_guard BEFORE UPDATE ON people_training_participants
                BEGIN SELECT RAISE(ABORT, 'participation identity and sessions are immutable'); END;
                SQL);
        }

        Schema::table('people_training_participants', function (Blueprint $table): void {
            $table->dropColumn(['withdrawn_at', 'withdrawn_by_user_id']);
        });
    }
};
