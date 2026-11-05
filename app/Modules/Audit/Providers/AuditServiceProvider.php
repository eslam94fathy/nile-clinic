<?php

namespace App\Modules\Audit\Providers;

use App\Modules\Audit\Repositories\Contracts\AuditEntryRepository;
use App\Modules\Audit\Repositories\EloquentAuditEntryRepository;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AuditServiceProvider extends ServiceProvider
{
    /**
     * Bind the module's repository contract.
     */
    public function register(): void
    {
        $this->app->bind(AuditEntryRepository::class, EloquentAuditEntryRepository::class);
    }

    /**
     * Load the module's versioned API routes and translations.
     */
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/../routes/api.php');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'audit');
    }
}
