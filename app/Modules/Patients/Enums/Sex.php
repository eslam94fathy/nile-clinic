<?php

namespace App\Modules\Patients\Enums;

/**
 * A patient's sex as recorded at registration (FR-001).
 */
enum Sex: string
{
    case Male = 'male';
    case Female = 'female';
}
