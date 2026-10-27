<?php

namespace App\Modules\System\Providers;

use App\Modules\System\Repositories\Contracts\DatabaseStatusRepository;
use App\Modules\System\Repositories\MySqlDatabaseStatusRepository;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SystemServiceProvider extends ServiceProvider
{
    /**
     * Bind the module's repository contracts to their implementations.
     */
    public function register(): void
    {
        $this->app->bind(DatabaseStatusRepository::class, MySqlDatabaseStatusRepository::class);
    }

    /**
     * Load the module's versioned API routes.
     */
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/../routes/api.php');
    }
}
