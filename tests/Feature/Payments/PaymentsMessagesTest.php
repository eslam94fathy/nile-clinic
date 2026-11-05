<?php

namespace Tests\Feature\Payments;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\DoctorFee;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every message in English and Arabic with an identical structure; statuses, methods, and money are
 * never translated (FR-025, SC-007).
 */
class PaymentsMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        Sanctum::actingAs(User::factory()->administrator()->create());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        return ['english' => ['en'], 'arabic' => ['ar']];
    }

    #[DataProvider('locales')]
    public function test_not_found_messages(string $locale): void
    {
        $this->withHeader('Accept-Language', $locale);

        $this->getJson('/api/v1/charges/999999')
            ->assertNotFound()
            ->assertExactJson(['message' => __('payments::messages.charge_not_found', [], $locale)]);

        $this->postJson('/api/v1/visits/999999/charge')
            ->assertNotFound()
            ->assertExactJson(['message' => __('payments::messages.visit_not_found', [], $locale)]);
    }

    #[DataProvider('locales')]
    public function test_charging_refusals(string $locale): void
    {
        $this->withHeader('Accept-Language', $locale);

        $doctor = Doctor::factory()->create();
        $left = QueueEntry::factory()->number(1)->left()->create(['doctor_id' => $doctor->id]);
        $noFee = QueueEntry::factory()->number(2)->seen()->create(['doctor_id' => $doctor->id]);

        $this->postJson("/api/v1/visits/{$left->id}/charge")
            ->assertUnprocessable()
            ->assertJsonPath('errors.visit.0', __('payments::messages.visit_left', [], $locale));

        $this->postJson("/api/v1/visits/{$noFee->id}/charge")
            ->assertUnprocessable()
            ->assertJsonPath('errors.visit.0', __('payments::messages.no_fee', [], $locale));

        DoctorFee::factory()->create(['doctor_id' => $doctor->id]);
        $this->postJson("/api/v1/visits/{$noFee->id}/charge")->assertCreated();

        $this->postJson("/api/v1/visits/{$noFee->id}/charge")
            ->assertUnprocessable()
            ->assertJsonPath('errors.visit.0', __('payments::messages.already_charged', [], $locale));
    }

    #[DataProvider('locales')]
    public function test_money_refusals(string $locale): void
    {
        $this->withHeader('Accept-Language', $locale);

        $charge = Charge::factory()->fee('100.00')->partiallyPaid('10.00')->create();
        $url = "/api/v1/charges/{$charge->id}";

        $this->postJson("{$url}/payments", ['amount' => '90.01', 'method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', __('payments::messages.above_balance', [], $locale));

        $this->postJson("{$url}/refunds", ['amount' => '10.01', 'method' => 'cash', 'reason' => 'Synthetic'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', __('payments::messages.above_paid', [], $locale));

        $this->putJson("{$url}/discount", ['amount' => '90.01', 'reason' => 'Synthetic'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', __('payments::messages.discount_too_large', [], $locale));

        $this->postJson("{$url}/cancel", ['reason' => 'Synthetic'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.charge.0', __('payments::messages.money_held', [], $locale));

        $cancelled = Charge::factory()->cancelled()->create();

        $this->postJson("/api/v1/charges/{$cancelled->id}/payments", ['amount' => '1.00', 'method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.charge.0', __('payments::messages.charge_cancelled', [], $locale));
    }

    #[DataProvider('locales')]
    public function test_undoing_a_check_in_with_an_active_charge(string $locale): void
    {
        $this->withHeader('Accept-Language', $locale);

        $appointment = Appointment::factory()->at('2026-11-01', '09:20')->create(['status' => AppointmentStatus::CheckedIn]);
        $entry = QueueEntry::factory()->forAppointment($appointment)->create();
        Charge::factory()->forEntry($entry)->create();

        $this->deleteJson("/api/v1/queue/{$entry->id}")
            ->assertUnprocessable()
            ->assertJsonPath('errors.entry.0', __('queues::messages.has_active_charge', [], $locale));
    }

    #[DataProvider('locales')]
    public function test_validation_messages_use_translated_attributes(string $locale): void
    {
        $this->withHeader('Accept-Language', $locale);

        $charge = Charge::factory()->create();
        $doctor = Doctor::factory()->create();

        $errors = $this->postJson("/api/v1/charges/{$charge->id}/refunds", [])->assertUnprocessable()->json('errors');

        foreach (['amount', 'method', 'reason'] as $field) {
            $this->assertStringContainsString(__("payments::attributes.{$field}", [], $locale), $errors[$field][0]);
        }

        $fee = $this->putJson("/api/v1/doctors/{$doctor->id}/fee", [])->assertUnprocessable()->json('errors.amount.0');
        $this->assertStringContainsString(__('payments::attributes.amount', [], $locale), $fee);

        $cancel = $this->postJson("/api/v1/charges/{$charge->id}/cancel", [])->assertUnprocessable()->json('errors.reason.0');
        $this->assertStringContainsString(__('payments::attributes.reason', [], $locale), $cancel);

        $status = $this->getJson('/api/v1/visits?date=2026-11-01&charge_status=done')->assertUnprocessable()->json('errors.charge_status.0');
        $this->assertStringContainsString(__('payments::attributes.charge_status', [], $locale), $status);

        $from = $this->getJson('/api/v1/charges?to=2026-11-01')->assertUnprocessable()->json('errors.from.0');
        $this->assertStringContainsString(__('payments::attributes.from', [], $locale), $from);
    }

    #[DataProvider('locales')]
    public function test_forbidden_message(string $locale): void
    {
        Sanctum::actingAs(User::factory()->doctor()->create());

        $this->withHeader('Accept-Language', $locale)
            ->getJson('/api/v1/charges/999999')
            ->assertForbidden()
            ->assertExactJson(['message' => __('auth.forbidden', [], $locale)]);
    }

    public function test_arabic_translations_differ_from_english(): void
    {
        foreach (array_keys(require app_path('Modules/Payments/lang/en/messages.php')) as $key) {
            $this->assertNotSame(__("payments::messages.{$key}", [], 'en'), __("payments::messages.{$key}", [], 'ar'), $key);
        }

        $this->assertNotSame(__('queues::messages.has_active_charge', [], 'en'), __('queues::messages.has_active_charge', [], 'ar'));
    }

    public function test_structure_and_identifiers_are_identical_across_locales(): void
    {
        $charge = Charge::factory()->fee('300.00')->create();
        $this->postJson("/api/v1/charges/{$charge->id}/payments", ['amount' => '100.10', 'method' => 'wallet'])->assertCreated();
        $this->postJson("/api/v1/charges/{$charge->id}/refunds", ['amount' => '0.10', 'method' => 'cash', 'reason' => 'Synthetic'])->assertCreated();
        $doctorId = $charge->doctor_id;

        $urls = [
            "/api/v1/charges/{$charge->id}",
            '/api/v1/charges?from=2026-11-01&to=2026-11-01',
            '/api/v1/visits?date=2026-11-01',
            "/api/v1/doctors/{$doctorId}/fee",
            '/api/v1/fees',
        ];

        foreach ($urls as $url) {
            $english = $this->withHeader('Accept-Language', 'en')->getJson($url)->assertOk()->json();
            $arabic = $this->withHeader('Accept-Language', 'ar')->getJson($url)->assertOk()->json();

            unset($english['links'], $english['meta'], $arabic['links'], $arabic['meta']);

            $this->assertSame($english, $arabic, $url);
        }

        $this->withHeader('Accept-Language', 'ar')
            ->getJson("/api/v1/charges/{$charge->id}")
            ->assertJsonPath('data.status', 'partially_paid')
            ->assertJsonPath('data.amount_paid', '100.00')
            ->assertJsonPath('data.payments.0.method', 'wallet');
    }
}
