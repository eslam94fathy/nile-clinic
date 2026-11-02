<?php

/**
 * Child process for ConcurrentBookingTest (feature 006, research R9): boots the application, waits
 * for a shared start time, books once through BookingService, and prints one JSON line.
 *
 * Usage: php tests/Support/book-appointment.php actorId patientId doctorId date start startAt
 *
 * Refuses (exit 2) to run against any database but clinic_testing.
 */

use App\Modules\Appointments\Exceptions\AppointmentConflictException;
use App\Modules\Appointments\Services\BookingService;
use App\Modules\Staff\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'clinic_testing') {
    fwrite(STDERR, "Refusing to run against any database but clinic_testing.\n");
    exit(2);
}

[, $actorId, $patientId, $doctorId, $date, $start, $startAt] = $argv;

$actor = User::query()->findOrFail((int) $actorId);
$booking = $app->make(BookingService::class);

while (microtime(true) < (float) $startAt) {
    usleep(1000);
}

try {
    $appointment = $booking->book($actor, [
        'patient_id' => (int) $patientId,
        'doctor_id' => (int) $doctorId,
        'date' => $date,
        'start' => $start,
    ]);
    $result = ['result' => 'booked', 'id' => $appointment->id];
} catch (AppointmentConflictException $e) {
    $result = ['result' => 'conflict', 'reason' => $e->reason];
} catch (Throwable $e) {
    $result = ['result' => 'error', 'class' => $e::class, 'message' => $e->getMessage()];
}

echo json_encode($result).PHP_EOL;
exit(0);
