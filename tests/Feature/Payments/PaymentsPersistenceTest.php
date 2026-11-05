<?php

namespace Tests\Feature\Payments;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\DoctorFee;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\Refund;
use App\Modules\Queues\Models\QueueEntry;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The database itself refuses overpayment and duplicate charges, and keeps records from losing their
 * references (research R3, R4, Constitution XI).
 */
class PaymentsPersistenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function badFigures(): array
    {
        return [
            'paid above the fee' => [['amount_paid' => '300.01']],
            'discount plus paid above the fee' => [['amount_paid' => '200.00', 'discount' => '100.01']],
            'negative paid' => [['amount_paid' => '-1.00']],
            'negative discount' => [['discount' => '-1.00']],
            'zero fee' => [['fee' => '0.00']],
        ];
    }

    /**
     * @param  array<string, string>  $figures
     */
    #[DataProvider('badFigures')]
    public function test_the_database_refuses_bad_figures(array $figures): void
    {
        $charge = Charge::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('charges')->where('id', $charge->id)->update($figures);
    }

    public function test_figures_exactly_at_the_limit_are_allowed(): void
    {
        $charge = Charge::factory()->create();

        DB::table('charges')->where('id', $charge->id)->update(['amount_paid' => '200.00', 'discount' => '100.00']);

        $this->assertSame('200.00', $charge->fresh()->amount_paid);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badAmounts(): array
    {
        return ['zero' => ['0.00'], 'negative' => ['-1.00']];
    }

    #[DataProvider('badAmounts')]
    public function test_a_payment_must_be_above_zero(string $amount): void
    {
        $this->expectException(QueryException::class);

        Payment::factory()->create(['amount' => $amount]);
    }

    #[DataProvider('badAmounts')]
    public function test_a_refund_must_be_above_zero(string $amount): void
    {
        $this->expectException(QueryException::class);

        Refund::factory()->create(['amount' => $amount]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badFees(): array
    {
        return ['zero' => ['0.00'], 'above the limit' => ['100000.01']];
    }

    #[DataProvider('badFees')]
    public function test_a_doctor_fee_must_be_in_range(string $amount): void
    {
        $this->expectException(QueryException::class);

        DoctorFee::factory()->create(['amount' => $amount]);
    }

    public function test_a_doctor_has_one_fee_row(): void
    {
        $doctor = Doctor::factory()->create();
        DoctorFee::factory()->create(['doctor_id' => $doctor->id]);

        $this->expectException(UniqueConstraintViolationException::class);

        DoctorFee::factory()->create(['doctor_id' => $doctor->id]);
    }

    public function test_a_visit_cannot_have_two_active_charges(): void
    {
        $entry = QueueEntry::factory()->seen()->create();
        Charge::factory()->forEntry($entry)->create();

        $this->expectException(UniqueConstraintViolationException::class);

        Charge::factory()->forEntry($entry)->paid()->create();
    }

    public function test_cancelled_charges_do_not_count_as_active(): void
    {
        $entry = QueueEntry::factory()->seen()->create();
        Charge::factory()->forEntry($entry)->cancelled()->create();
        Charge::factory()->forEntry($entry)->cancelled()->create();
        Charge::factory()->forEntry($entry)->create();

        $this->assertSame(3, Charge::query()->where('queue_entry_id', $entry->id)->count());
    }

    public function test_a_cancelled_charge_outlives_its_visit(): void
    {
        $charge = Charge::factory()->cancelled()->create();
        $patientId = $charge->patient_id;
        $doctorId = $charge->doctor_id;
        $date = $charge->date->toDateString();

        $charge->queueEntry->delete();

        $fresh = $charge->fresh();
        $this->assertNull($fresh->queue_entry_id);
        $this->assertSame($patientId, $fresh->patient_id);
        $this->assertSame($doctorId, $fresh->doctor_id);
        $this->assertSame($date, $fresh->date->toDateString());
    }

    public function test_a_charge_with_a_payment_cannot_be_deleted(): void
    {
        $payment = Payment::factory()->create();

        $this->expectException(QueryException::class);

        $payment->charge->delete();
    }

    public function test_a_charge_with_a_refund_cannot_be_deleted(): void
    {
        $refund = Refund::factory()->create();

        $this->expectException(QueryException::class);

        $refund->charge->delete();
    }

    public function test_a_doctor_with_a_charge_cannot_be_deleted(): void
    {
        $charge = Charge::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('doctors')->where('id', $charge->doctor_id)->delete();
    }

    public function test_a_patient_with_a_charge_cannot_be_deleted(): void
    {
        $charge = Charge::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('patients')->where('id', $charge->patient_id)->delete();
    }

    public function test_the_staff_member_on_a_charge_cannot_be_deleted(): void
    {
        $charge = Charge::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('users')->where('id', $charge->charged_by)->delete();
    }

    public function test_amounts_round_trip_exactly_as_strings(): void
    {
        foreach (['99999.99', '0.07', '100000.00'] as $amount) {
            $charge = Charge::factory()->fee($amount)->create();

            $this->assertSame($amount, $charge->fresh()->fee);
        }
    }

    public function test_the_active_flag_follows_the_status(): void
    {
        $active = Charge::factory()->create();
        $cancelled = Charge::factory()->cancelled()->create();

        $this->assertSame(1, (int) DB::table('charges')->where('id', $active->id)->value('active_flag'));
        $this->assertNull(DB::table('charges')->where('id', $cancelled->id)->value('active_flag'));
    }
}
