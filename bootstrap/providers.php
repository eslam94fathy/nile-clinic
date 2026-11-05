<?php

use App\Modules\Appointments\Providers\AppointmentsServiceProvider;
use App\Modules\Audit\Providers\AuditServiceProvider;
use App\Modules\Doctors\Providers\DoctorsServiceProvider;
use App\Modules\Encounters\Providers\EncountersServiceProvider;
use App\Modules\Patients\Providers\PatientsServiceProvider;
use App\Modules\Payments\Providers\PaymentsServiceProvider;
use App\Modules\Queues\Providers\QueuesServiceProvider;
use App\Modules\Reports\Providers\ReportsServiceProvider;
use App\Modules\Scheduling\Providers\SchedulingServiceProvider;
use App\Modules\Staff\Providers\StaffServiceProvider;
use App\Modules\System\Providers\SystemServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    SystemServiceProvider::class,
    AuditServiceProvider::class,
    StaffServiceProvider::class,
    PatientsServiceProvider::class,
    DoctorsServiceProvider::class,
    SchedulingServiceProvider::class,
    AppointmentsServiceProvider::class,
    QueuesServiceProvider::class,
    EncountersServiceProvider::class,
    PaymentsServiceProvider::class,
    ReportsServiceProvider::class,
];
