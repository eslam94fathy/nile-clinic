<?php

namespace App\Modules\Payments\Providers;

use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\DoctorFee;
use App\Modules\Payments\Policies\ChargePolicy;
use App\Modules\Payments\Policies\DoctorFeePolicy;
use App\Modules\Payments\Repositories\Contracts\DoctorFeeRepository;
use App\Modules\Payments\Repositories\EloquentDoctorFeeRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PaymentsServiceProvider extends ServiceProvider
{
    /**
     * Bind the module's repository contracts.
     */
    public function register(): void
    {
        $this->app->bind(DoctorFeeRepository::class, EloquentDoctorFeeRepository::class);
    }

    /**
     * Load the module's versioned API routes and translations, and register its policies.
     */
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/../routes/api.php');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'payments');

        Gate::policy(DoctorFee::class, DoctorFeePolicy::class);
        Gate::policy(Charge::class, ChargePolicy::class);
    }
}
