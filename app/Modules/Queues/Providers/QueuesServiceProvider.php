<?php

namespace App\Modules\Queues\Providers;

use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Queues\Policies\QueueEntryPolicy;
use App\Modules\Queues\Repositories\Contracts\QueueRepository;
use App\Modules\Queues\Repositories\EloquentQueueRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class QueuesServiceProvider extends ServiceProvider
{
    /**
     * Bind the module's repository contract.
     */
    public function register(): void
    {
        $this->app->bind(QueueRepository::class, EloquentQueueRepository::class);
    }

    /**
     * Load the module's versioned API routes and translations, and register its policy.
     */
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/../routes/api.php');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'queues');

        Gate::policy(QueueEntry::class, QueueEntryPolicy::class);
    }
}
