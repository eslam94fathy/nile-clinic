<?php

namespace App\Modules\Reports\Providers;

use App\Modules\Reports\Policies\ReportPolicy;
use App\Modules\Reports\Repositories\Contracts\ReportRepository;
use App\Modules\Reports\Repositories\QueryReportRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ReportsServiceProvider extends ServiceProvider
{
    /**
     * Bind the module's repository contract.
     */
    public function register(): void
    {
        $this->app->bind(ReportRepository::class, QueryReportRepository::class);
    }

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
