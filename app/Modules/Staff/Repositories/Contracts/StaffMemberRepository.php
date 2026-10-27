<?php

namespace App\Modules\Staff\Repositories\Contracts;

use App\Modules\Staff\Models\User;

interface StaffMemberRepository
{
    /**
     * Find an account by its stored (lower-cased) email.
     */
    public function findByEmail(string $email): ?User;
}
