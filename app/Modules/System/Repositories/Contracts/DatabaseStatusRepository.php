<?php

namespace App\Modules\System\Repositories\Contracts;

interface DatabaseStatusRepository
{
    /**
     * Determine whether the application database accepts queries.
     */
    public function isReachable(): bool;
}
