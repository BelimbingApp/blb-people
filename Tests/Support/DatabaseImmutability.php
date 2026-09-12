<?php

namespace App\Domains\People\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class DatabaseImmutability
{
    /**
     * Prove that event-bypassing Eloquent builder writes are rejected and
     * that PostgreSQL remains usable after each deliberately failed write.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function assertUpdateAndDeleteAreRefused(Model $row, array $changes): void
    {
        $table = $row->getTable();
        $key = $row->getKey();
        $before = (array) DB::table($table)->where($row->getKeyName(), $key)->first();

        expect(fn () => DB::transaction(
            fn () => $row->newQuery()
                ->forCompany((int) $row->tenant_id, (int) $row->company_entity_id)
                ->whereKey($key)
                ->update($changes),
        ))->toThrow(QueryException::class);

        expect((array) DB::table($table)->where($row->getKeyName(), $key)->first())->toBe($before);

        expect(fn () => DB::transaction(
            fn () => $row->newQuery()
                ->forCompany((int) $row->tenant_id, (int) $row->company_entity_id)
                ->whereKey($key)
                ->delete(),
        ))->toThrow(QueryException::class);

        expect((array) DB::table($table)->where($row->getKeyName(), $key)->first())->toBe($before);
    }
}
