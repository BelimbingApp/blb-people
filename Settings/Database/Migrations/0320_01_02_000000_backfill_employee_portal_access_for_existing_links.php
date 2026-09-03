<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * NativeWorkforceBootstrapReader (Provider module) now requires an active
 * people_employee_portal_accesses row before it will project a user_reference
 * for an employee — see rule 8.3 in docs/contracts/hr-data-boundary.md and
 * BelimbingApp/blb-people#25. Without this backfill, every employee already
 * linked to a platform user through users.employee_id would silently stop
 * projecting a user_reference the moment that reader change lands, with no
 * error and no visible signal — rule 9.2 says staleness/loss must be visible,
 * never hidden, and a silent projection drop is exactly that.
 *
 * This grandfathers in every employee-user pair that already satisfies the
 * same mutual-consistency check the reader applies (the user points back at
 * the employee, and the company matches or the user is unscoped), so the new
 * gate becomes effective only for links made from this point forward. Rows
 * this migration creates are tagged in `metadata` so `down()` can remove
 * exactly them and nothing an HR administrator created deliberately.
 */
return new class extends Migration
{
    private const BACKFILL_TAG = '0320_01_02_000000';

    public function up(): void
    {
        $now = now();

        $links = DB::table('employees as e')
            ->join('users as u', function ($join): void {
                $join->on('u.employee_id', '=', 'e.id')
                    ->where(function ($query): void {
                        $query->whereNull('u.company_id')
                            ->orWhereColumn('u.company_id', 'e.company_id');
                    });
            })
            ->leftJoin('people_employee_portal_accesses as ppa', 'ppa.employee_id', '=', 'e.id')
            ->whereNull('ppa.id')
            ->select(['e.id as employee_id', 'e.employee_number', 'e.full_name', 'e.email as employee_email', 'u.id as user_id', 'u.name as user_name', 'u.email as user_email'])
            ->get();

        foreach ($links as $link) {
            DB::table('people_employee_portal_accesses')->insert([
                'employee_id' => $link->employee_id,
                'user_id' => $link->user_id,
                'login_identifier' => $link->user_email ?? $link->employee_number,
                'display_name' => $link->user_name ?? $link->full_name,
                'email' => $link->user_email ?? $link->employee_email,
                'status' => 'active',
                'activated_at' => $now,
                'metadata' => json_encode(['backfilled_by' => self::BACKFILL_TAG]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('people_employee_portal_accesses')
            ->where('metadata->backfilled_by', self::BACKFILL_TAG)
            ->delete();
    }
};
