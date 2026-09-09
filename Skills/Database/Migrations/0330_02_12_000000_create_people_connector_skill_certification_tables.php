<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    /** @var list<string> */
    private array $tables = [
        'people_connector_skill_certifications',
        'people_connector_skill_certification_skills',
    ];

    public function up(): void
    {
        Schema::create('people_connector_skill_certifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('employee_entity_id');
            $table->string('issuer', 200);
            $table->string('external_reference', 160);
            $table->date('issued_on');
            $table->date('expires_on')->nullable();
            $table->string('renewal_status', 24)->default('current');
            $table->string('evidence_link', 2048);
            $table->unsignedBigInteger('supersedes_certification_id')->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'pcs_cert_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pcs_cert_id_tenant_uq');
            $table->unique(['tenant_id', 'company_entity_id', 'external_reference'], 'pcs_cert_reference_uq');
            $table->index(['tenant_id', 'company_entity_id', 'employee_entity_id'], 'pcs_cert_employee_idx');
            $table->index(['tenant_id', 'company_entity_id', 'expires_on'], 'pcs_cert_expiry_idx');
            $table->index(['tenant_id', 'supersedes_certification_id'], 'pcs_cert_supersedes_idx');

            $table->foreign('tenant_id', 'pcs_cert_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('employee_entity_id', 'pcs_cert_employee_fk')
                ->references('id')->on('employees')->restrictOnDelete();
            $table->foreign(['supersedes_certification_id', 'tenant_id'], 'pcs_cert_supersedes_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_skill_certifications')
                ->restrictOnDelete();
        });

        Schema::create('people_connector_skill_certification_skills', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('certification_id');
            $table->unsignedBigInteger('skill_id');
            $table->timestamps();

            $table->index('tenant_id', 'pcs_cert_skill_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pcs_cert_skill_id_tenant_uq');
            $table->unique(['tenant_id', 'certification_id', 'skill_id'], 'pcs_cert_skill_pair_uq');
            $table->index(['tenant_id', 'company_entity_id', 'skill_id'], 'pcs_cert_skill_skill_idx');

            $table->foreign('tenant_id', 'pcs_cert_skill_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['certification_id', 'tenant_id'], 'pcs_cert_skill_cert_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_skill_certifications')
                ->restrictOnDelete();
            $table->foreign(['skill_id', 'tenant_id'], 'pcs_cert_skill_skill_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_skill_skills')
                ->restrictOnDelete();
        });

        $this->createImmutabilityGuards();

        foreach ($this->tables as $table) {
            $this->registerTable($table);
        }
    }

    public function down(): void
    {
        $this->dropImmutabilityGuards();

        foreach (array_reverse($this->tables) as $table) {
            $this->unregisterTable($table);
            Schema::dropIfExists($table);
        }
    }

    private function createImmutabilityGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION pcs_skill_certification_immutable() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'skill certification records are append-only';
                END;
                $$ LANGUAGE plpgsql;
                DROP TRIGGER IF EXISTS pcs_skill_certification_immutable ON people_connector_skill_certifications;
                CREATE TRIGGER pcs_skill_certification_immutable
                    BEFORE UPDATE OR DELETE ON people_connector_skill_certifications
                    FOR EACH ROW EXECUTE FUNCTION pcs_skill_certification_immutable();

                CREATE OR REPLACE FUNCTION pcs_skill_certification_skill_immutable() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'skill certification mappings are append-only';
                END;
                $$ LANGUAGE plpgsql;
                DROP TRIGGER IF EXISTS pcs_skill_certification_skill_immutable ON people_connector_skill_certification_skills;
                CREATE TRIGGER pcs_skill_certification_skill_immutable
                    BEFORE UPDATE OR DELETE ON people_connector_skill_certification_skills
                    FOR EACH ROW EXECUTE FUNCTION pcs_skill_certification_skill_immutable();
                SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER pcs_skill_certification_update_guard
                BEFORE UPDATE ON people_connector_skill_certifications
                BEGIN SELECT RAISE(ABORT, 'skill certification records are append-only'); END;

                CREATE TRIGGER pcs_skill_certification_delete_guard
                BEFORE DELETE ON people_connector_skill_certifications
                BEGIN SELECT RAISE(ABORT, 'skill certification records are append-only'); END;

                CREATE TRIGGER pcs_skill_certification_skill_update_guard
                BEFORE UPDATE ON people_connector_skill_certification_skills
                BEGIN SELECT RAISE(ABORT, 'skill certification mappings are append-only'); END;

                CREATE TRIGGER pcs_skill_certification_skill_delete_guard
                BEFORE DELETE ON people_connector_skill_certification_skills
                BEGIN SELECT RAISE(ABORT, 'skill certification mappings are append-only'); END;
                SQL);
        }
    }

    private function dropImmutabilityGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS pcs_skill_certification_immutable ON people_connector_skill_certifications;
                DROP TRIGGER IF EXISTS pcs_skill_certification_skill_immutable ON people_connector_skill_certification_skills;
                DROP FUNCTION IF EXISTS pcs_skill_certification_immutable();
                DROP FUNCTION IF EXISTS pcs_skill_certification_skill_immutable();
                SQL);
        } elseif ($driver === 'sqlite') {
            foreach ([
                'pcs_skill_certification_update_guard',
                'pcs_skill_certification_delete_guard',
                'pcs_skill_certification_skill_update_guard',
                'pcs_skill_certification_skill_delete_guard',
            ] as $trigger) {
                DB::statement('DROP TRIGGER IF EXISTS '.$trigger);
            }
        }
    }
};
