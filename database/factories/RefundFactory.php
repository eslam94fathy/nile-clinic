<?php

namespace Database\Factories;

use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\Refund;
use App\Modules\Staff\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic refunds for tests only (Constitution XII). Does not update the charge's figures.
 *
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    protected $model = Refund::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'charge_id' => Charge::factory(),
            'amount' => '50.00',
            'method' => PaymentMethod::Cash,
            'reason' => 'Synthetic refund',
            'refunded_by' => User::factory()->administrator(),
        ];
    }
}
