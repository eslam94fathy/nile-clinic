<?php

namespace App\Modules\Patients\Providers;

use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Policies\PatientPolicy;
use App\Modules\Patients\Repositories\Contracts\PatientRepository;
use App\Modules\Patients\Repositories\EloquentPatientRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PatientsServiceProvider extends ServiceProvider
{
    /**
     * Bind the module's repository contracts to their implementations.
     */
    public function register(): void
    {
        $this->app->bind(PatientRepository::class, EloquentPatientRepository::class);
    }

    /**
     * Load the module's versioned API routes and translations, and register its policy.
     */
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/../routes/api.php');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'patients');

        Gate::policy(Patient::class, PatientPolicy::class);
    }
}
