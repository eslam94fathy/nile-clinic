<?php

namespace App\Modules\Staff\Repositories\Contracts;

use App\Modules\Staff\Models\User;
use Carbon\CarbonInterface;

interface AccessTokenRepository
{
    /**
     * Issue a new token and return its plain-text value (shown to the client once).
     */
    public function issueFor(User $user): string;

    /**
     * Delete the token the user authenticated this request with.
     */
    public function deleteCurrent(User $user): void;

    /**
     * Delete the user's tokens whose last activity (or creation, if never used) is before $cutoff.
     */
    public function deleteIdleFor(User $user, CarbonInterface $cutoff): void;

    /**
     * Delete every token of the user, ending all of their access.
     */
    public function deleteAllFor(User $user): void;
}
