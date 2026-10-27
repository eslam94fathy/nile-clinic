<?php

namespace App\Modules\Staff\Repositories;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Repositories\Contracts\StaffMemberRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentStaffMemberRepository implements StaffMemberRepository
{
    public function findByEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }

    public function findById(int $id): ?User
    {
        return User::query()->find($id);
    }

    public function create(array $attributes): User
    {
        return User::query()->create($attributes);
    }

    public function update(User $user, array $attributes): User
    {
        $user->fill($attributes)->save();

        return $user;
    }

    public function paginate(?Role $role, ?bool $isActive, int $perPage): LengthAwarePaginator
    {
        return User::query()
            ->when($role !== null, fn ($query) => $query->where('role', $role))
            ->when($isActive !== null, fn ($query) => $query->where('is_active', $isActive))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function lockActiveAdministrators(): int
    {
        return User::query()
            ->where('role', Role::Administrator)
            ->where('is_active', true)
            ->lockForUpdate()
            ->pluck('id')
            ->count();
    }
}
