<?php

namespace App\Modules\System\Repositories;

use App\Modules\System\Repositories\Contracts\DatabaseStatusRepository;
use Illuminate\Support\Facades\DB;
use Throwable;

class MySqlDatabaseStatusRepository implements DatabaseStatusRepository
{
    public function isReachable(): bool
    {
        try {
            DB::connection()->select('select 1');

            return true;
        } catch (Throwable) {
            // Deliberately swallowed: callers only learn reachable/unreachable,
            // never connection details (FR-020).
            return false;
        }
    }
}
