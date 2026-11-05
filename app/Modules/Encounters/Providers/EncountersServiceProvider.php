<?php

namespace App\Modules\Encounters\Providers;

use App\Modules\Encounters\Models\Encounter;
use App\Modules\Encounters\Policies\EncounterPolicy;
use App\Modules\Encounters\Repositories\Contracts\EncounterRepository;
use App\Modules\Encounters\Repositories\EloquentEncounterRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class EncountersServiceProvider extends ServiceProvider
{
    /**
     * Bind the module's repository contract.
     */
    public function register(): void
    {
        $this->app->bind(EncounterRepository::class, EloquentEncounterRepository::class);
    }

    /**
     * Load the module's versioned API routes and translations, and register its policy.
     */
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/../routes/api.php');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'encounters');

        Gate::policy(Encounter::class, EncounterPolicy::class);
    }
}
