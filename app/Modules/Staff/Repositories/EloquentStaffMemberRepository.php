<?php

namespace App\Modules\Staff\Repositories;

use App\Modules\Staff\Models\User;
use App\Modules\Staff\Repositories\Contracts\StaffMemberRepository;

class EloquentStaffMemberRepository implements StaffMemberRepository
{
    public function findByEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }
}
