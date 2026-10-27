<?php

namespace App\Modules\Staff\Providers;

use App\Modules\Staff\Repositories\Contracts\AccessTokenRepository;
use App\Modules\Staff\Repositories\Contracts\StaffMemberRepository;
use App\Modules\Staff\Repositories\EloquentStaffMemberRepository;
use App\Modules\Staff\Repositories\SanctumAccessTokenRepository;
use App\Modules\Staff\Services\AuthenticationService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class StaffServiceProvider extends ServiceProvider
{
    /**
     * Bind the module's repository contracts to their implementations.
     */
    public function register(): void
    {
        $this->app->bind(StaffMemberRepository::class, EloquentStaffMemberRepository::class);
        $this->app->bind(AccessTokenRepository::class, SanctumAccessTokenRepository::class);
    }

    /**
     * Load the module's versioned API routes and translations, and enforce token validity.
     */
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/../routes/api.php');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'staff');

        // Idle expiry and deactivated accounts are checked on every token request (research R2).
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid) => $this->app
                ->make(AuthenticationService::class)
                ->isTokenUsable($token, $isValid),
        );
    }
}
