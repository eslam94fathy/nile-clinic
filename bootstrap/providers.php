<?php

use App\Modules\Appointments\Providers\AppointmentsServiceProvider;
use App\Modules\Doctors\Providers\DoctorsServiceProvider;
use App\Modules\Encounters\Providers\EncountersServiceProvider;
use App\Modules\Patients\Providers\PatientsServiceProvider;
use App\Modules\Queues\Providers\QueuesServiceProvider;
use App\Modules\Scheduling\Providers\SchedulingServiceProvider;
use App\Modules\Staff\Providers\StaffServiceProvider;
use App\Modules\System\Providers\SystemServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    SystemServiceProvider::class,
    StaffServiceProvider::class,
    PatientsServiceProvider::class,
    DoctorsServiceProvider::class,
    SchedulingServiceProvider::class,
    AppointmentsServiceProvider::class,
    QueuesServiceProvider::class,
    EncountersServiceProvider::class,
];
