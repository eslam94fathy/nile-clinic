<?php

namespace App\Modules\Scheduling\Providers;

use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Scheduling\Models\DoctorTimeOff;
use App\Modules\Scheduling\Policies\SchedulePolicy;
use App\Modules\Scheduling\Policies\TimeOffPolicy;
use App\Modules\Scheduling\Repositories\Contracts\DoctorScheduleRepository;
use App\Modules\Scheduling\Repositories\Contracts\TimeOffRepository;
use App\Modules\Scheduling\Repositories\EloquentDoctorScheduleRepository;
use App\Modules\Scheduling\Repositories\EloquentTimeOffRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SchedulingServiceProvider extends ServiceProvider
{
    /**
     * Bind the module's repository contracts to their implementations.
     */
    public function register(): void
    {
        $this->app->bind(DoctorScheduleRepository::class, EloquentDoctorScheduleRepository::class);
        $this->app->bind(TimeOffRepository::class, EloquentTimeOffRepository::class);
    }

    /**
     * Load the module's versioned API routes and translations, and register its policies.
     */
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/../routes/api.php');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'scheduling');

        Gate::policy(DoctorSchedule::class, SchedulePolicy::class);
        Gate::policy(DoctorTimeOff::class, TimeOffPolicy::class);
    }
}
