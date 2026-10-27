<?php

namespace App\Modules\Staff\Repositories;

use App\Modules\Staff\Models\User;
use App\Modules\Staff\Repositories\Contracts\AccessTokenRepository;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class SanctumAccessTokenRepository implements AccessTokenRepository
{
    public function issueFor(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    public function deleteCurrent(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    public function deleteIdleFor(User $user, CarbonInterface $cutoff): void
    {
        $user->tokens()
            ->where(fn (Builder $query) => $query
                ->where('last_used_at', '<', $cutoff)
                ->orWhere(fn (Builder $never) => $never
                    ->whereNull('last_used_at')
                    ->where('created_at', '<', $cutoff)))
            ->delete();
    }
}
