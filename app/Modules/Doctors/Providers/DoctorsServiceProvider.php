<?php

namespace App\Modules\Doctors\Providers;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Doctors\Policies\DoctorPolicy;
use App\Modules\Doctors\Policies\SpecialtyPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class DoctorsServiceProvider extends ServiceProvider
{
    /**
     * Bind the module's repository contracts to their implementations.
     */
    public function register(): void
    {
        //
    }

    /**
     * Load the module's versioned API routes and translations, and register its policies.
     */
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/../routes/api.php');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'doctors');

        Gate::policy(Specialty::class, SpecialtyPolicy::class);
        Gate::policy(Doctor::class, DoctorPolicy::class);
    }
}
