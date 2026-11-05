<?php

namespace Database\Factories;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Payments\Models\DoctorFee;
use App\Modules\Staff\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic doctor fees for tests only (Constitution XII).
 *
 * @extends Factory<DoctorFee>
 */
class DoctorFeeFactory extends Factory
{
    protected $model = DoctorFee::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'doctor_id' => Doctor::factory(),
            'amount' => '350.00',
            'set_by' => User::factory()->administrator(),
        ];
    }
}
