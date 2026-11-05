<?php

namespace Database\Factories;

use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\Payment;
use App\Modules\Staff\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic payments for tests only (Constitution XII). Does not update the charge's figures.
 *
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'charge_id' => Charge::factory(),
            'amount' => '100.00',
            'method' => PaymentMethod::Cash,
            'received_by' => User::factory()->receptionist(),
        ];
    }
}
