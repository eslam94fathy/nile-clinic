<?php

namespace App\Modules\Appointments\Providers;

use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Policies\AppointmentPolicy;
use App\Modules\Appointments\Repositories\Contracts\AppointmentRepository;
use App\Modules\Appointments\Repositories\EloquentAppointmentRepository;
use App\Modules\Appointments\Repositories\EloquentBookedTimeRepository;
use App\Modules\Scheduling\Repositories\Contracts\BookedTimeRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppointmentsServiceProvider extends ServiceProvider
{
    /**
     * Bind the module's repository contracts, and Scheduling's BookedTimeRepository, which this
     * module implements (research R2, approved D1).
     */
    public function register(): void
    {
        $this->app->bind(AppointmentRepository::class, EloquentAppointmentRepository::class);
        $this->app->bind(BookedTimeRepository::class, EloquentBookedTimeRepository::class);
    }

    /**
     * Load the module's versioned API routes and translations, and register its policy.
     */
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/../routes/api.php');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'appointments');

        Gate::policy(Appointment::class, AppointmentPolicy::class);
    }
}
