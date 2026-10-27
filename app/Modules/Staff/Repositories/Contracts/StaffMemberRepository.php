<?php

namespace App\Modules\Staff\Repositories\Contracts;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface StaffMemberRepository
{
    /**
     * Find an account by its stored (lower-cased) email.
     */
    public function findByEmail(string $email): ?User;

    public function findById(int $id): ?User;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): User;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, array $attributes): User;

    /**
     * One page of accounts ordered by name, then id; null filters are not applied.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(?Role $role, ?bool $isActive, int $perPage): LengthAwarePaginator;

    /**
     * Lock the active administrator rows (SELECT … FOR UPDATE) and return how many there are.
     * Must be called inside a transaction (research R8).
     */
    public function lockActiveAdministrators(): int;
}
