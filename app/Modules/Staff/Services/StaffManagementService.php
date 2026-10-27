<?php

namespace App\Modules\Staff\Services;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Exceptions\LastAdministratorException;
use App\Modules\Staff\Exceptions\StaffMemberNotFoundException;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Repositories\Contracts\AccessTokenRepository;
use App\Modules\Staff\Repositories\Contracts\StaffMemberRepository;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Administrator workflows on staff accounts (US3, research R8, R9).
 */
class StaffManagementService
{
    public function __construct(
        private readonly StaffMemberRepository $staffMembers,
        private readonly AccessTokenRepository $tokens,
    ) {}

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function list(?Role $role, ?bool $isActive, int $perPage): LengthAwarePaginator
    {
        return $this->staffMembers->paginate($role, $isActive, $perPage);
    }

    /**
     * @throws StaffMemberNotFoundException
     */
    public function find(int $id): User
    {
        return $this->staffMembers->findById($id) ?? throw new StaffMemberNotFoundException;
    }

    /**
     * @param  array{name: string, email: string, role: string, password: string}  $attributes
     */
    public function create(array $attributes): User
    {
        return $this->guardUniqueEmail(fn () => $this->staffMembers->create([
            ...$attributes,
            'is_active' => true,
        ]));
    }

    /**
     * @param  array{name?: string, email?: string, role?: string}  $attributes
     *
     * @throws LastAdministratorException
     */
    public function update(User $user, array $attributes): User
    {
        $newRole = isset($attributes['role']) ? Role::from($attributes['role']) : null;

        if ($newRole === null || $newRole === Role::Administrator || ! $user->isAdministrator()) {
            return $this->guardUniqueEmail(fn () => $this->staffMembers->update($user, $attributes));
        }

        return DB::transaction(function () use ($user, $attributes) {
            $this->ensureNotLastActiveAdministrator($user);

            return $this->guardUniqueEmail(fn () => $this->staffMembers->update($user, $attributes));
        });
    }

    /**
     * End all of the member's access and block new sign-ins (FR-019). Idempotent.
     *
     * @throws LastAdministratorException
     */
    public function deactivate(User $user): User
    {
        if (! $user->is_active) {
            return $user;
        }

        return DB::transaction(function () use ($user) {
            if ($user->isAdministrator()) {
                $this->ensureNotLastActiveAdministrator($user);
            }

            $this->staffMembers->update($user, ['is_active' => false]);
            $this->tokens->deleteAllFor($user);

            return $user;
        });
    }

    /**
     * Allow sign-in again; earlier tokens stay deleted. Idempotent.
     */
    public function reactivate(User $user): User
    {
        return $user->is_active ? $user : $this->staffMembers->update($user, ['is_active' => true]);
    }

    /**
     * Set a new password and end all of the member's access (FR-020).
     */
    public function setPassword(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password) {
            $this->staffMembers->update($user, ['password' => $password]);
            $this->tokens->deleteAllFor($user);
        });
    }

    /**
     * Refuse a change to an active administrator when they are the only one; the locked count
     * stops two administrators demoting each other at the same moment (research R8).
     */
    private function ensureNotLastActiveAdministrator(User $user): void
    {
        if ($user->is_active && $this->staffMembers->lockActiveAdministrators() <= 1) {
            throw new LastAdministratorException;
        }
    }

    /**
     * Turn a duplicate-email race that passed validation into the same validation error (R9).
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    private function guardUniqueEmail(Closure $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => __('validation.unique', ['attribute' => 'email']),
            ]);
        }
    }
}
