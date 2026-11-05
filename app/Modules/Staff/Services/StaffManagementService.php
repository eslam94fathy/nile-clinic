<?php

namespace App\Modules\Staff\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Enums\AuditSubject;
use App\Modules\Audit\Services\AuditTrail;
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
        private readonly AuditTrail $audit,
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
        return $this->guardUniqueEmail(fn () => DB::transaction(function () use ($attributes) {
            $user = $this->staffMembers->create([...$attributes, 'is_active' => true]);

            // With nobody signed in, as in staff:create-admin, the entry names the system (research R6).
            $this->audit->recordChanges(AuditAction::StaffCreated, $user, AuditSubject::StaffMember);

            return $user;
        }));
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
            return $this->guardUniqueEmail(fn () => DB::transaction(fn () => $this->applyUpdate($user, $attributes)));
        }

        return DB::transaction(function () use ($user, $attributes) {
            $this->ensureNotLastActiveAdministrator($user);

            return $this->guardUniqueEmail(fn () => $this->applyUpdate($user, $attributes));
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

            $this->audit->recordChanges(AuditAction::StaffDeactivated, $user, AuditSubject::StaffMember);

            $this->tokens->deleteAllFor($user);

            return $user;
        });
    }

    /**
     * Allow sign-in again; earlier tokens stay deleted. Idempotent.
     */
    public function reactivate(User $user): User
    {
        if ($user->is_active) {
            return $user;
        }

        return DB::transaction(function () use ($user) {
            $this->staffMembers->update($user, ['is_active' => true]);

            $this->audit->recordChanges(AuditAction::StaffReactivated, $user, AuditSubject::StaffMember);

            return $user;
        });
    }

    /**
     * Set a new password and end all of the member's access (FR-020).
     */
    public function setPassword(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password) {
            $this->staffMembers->update($user, ['password' => $password]);
            $this->tokens->deleteAllFor($user);

            $this->audit->record(AuditAction::StaffPasswordReset, AuditSubject::StaffMember, $user->id, details: ['fields' => ['password']]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applyUpdate(User $user, array $attributes): User
    {
        $this->staffMembers->update($user, $attributes);

        $this->audit->recordChanges(AuditAction::StaffUpdated, $user, AuditSubject::StaffMember);

        return $user;
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
