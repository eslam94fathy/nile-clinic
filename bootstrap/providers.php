<?php

use App\Modules\Patients\Providers\PatientsServiceProvider;
use App\Modules\Staff\Providers\StaffServiceProvider;
use App\Modules\System\Providers\SystemServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    SystemServiceProvider::class,
    StaffServiceProvider::class,
    PatientsServiceProvider::class,
];
