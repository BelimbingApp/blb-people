<?php

use App\Base\Database\Concerns\IncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A critical coverage gap in the reminder delivery ledger (0009-i).
 *
 * The gap's subject is a department and a skill, not an employee, so the row
 * carries `department_id` and stores 0 in `employee_entity_id`. The unique
 * key gains the department for the same reason `development_action_id` is in
 * it: two departments short of the same skill are two reminders, and the key
 * must tell them apart before notify() runs.
 *
 * Period key per rule. Score and action reminders keep the ISO week
 * (`2026-W37`). A coverage gap uses the ISO month (`2026-M09`): the workbook
 * reviews cover monthly and gives the HOD and HR thirty days to produce a
 * plan, so a weekly nag would arrive before anyone could have acted on the
 * last one. Both shapes are eight characters, and a rule's rows only ever
 * carry its own shape, so `retry()` matches on either key of the moment.
 *
 * Declares IncubatingSchema because it alters people_connector_skill_reminder_deliveries,
 * which 0330_02_09 creates as incubating. A stable forward onto an incubating table
 * is what IncubatingSchemaConflictException refuses: rebuilding the create alone
 * would drop this column and unique key while the ledger still claimed they were
 * applied. Joining the replay chain means they are dropped and recreated with the
 * table they belong to.
 */
return new class extends Migration
{
    use IncubatingSchema;

    public function up(): void
    {
        Schema::table('people_connector_skill_reminder_deliveries', function (Blueprint $table): void {
            // 0 for every rule but a coverage gap, and for a gap outside any
            // department: NULL in a unique key compares equal to nothing.
            $table->unsignedBigInteger('department_id')->default(0)->after('skill_id');
            $table->dropUnique('pcr_deliv_period_uq');
            $table->unique(
                ['tenant_id', 'company_entity_id', 'rule', 'employee_entity_id', 'skill_id', 'development_action_id', 'department_id', 'period_key', 'recipient_user_id'],
                'pcr_deliv_period_uq',
            );
        });
    }

    public function down(): void
    {
        Schema::table('people_connector_skill_reminder_deliveries', function (Blueprint $table): void {
            $table->dropUnique('pcr_deliv_period_uq');
            $table->unique(
                ['tenant_id', 'company_entity_id', 'rule', 'employee_entity_id', 'skill_id', 'development_action_id', 'period_key', 'recipient_user_id'],
                'pcr_deliv_period_uq',
            );
            $table->dropColumn('department_id');
        });
    }
};
