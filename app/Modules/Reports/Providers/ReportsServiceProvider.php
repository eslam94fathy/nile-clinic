<?php

namespace App\Modules\Reports\Providers;

use App\Modules\Reports\Policies\ReportPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ReportsServiceProvider extends ServiceProvider
{
    /**
     * Load the module's versioned API routes and translations, and register its gate. Reports have no
     * model, so the policy is registered by name (research R9).
     */
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/../routes/api.php');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'reports');

        Gate::define('view-reports', [ReportPolicy::class, 'view']);
    }
}
