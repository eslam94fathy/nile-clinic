<?php

/**
 * Child process for ConcurrentAuditTest (feature 011, research R10): boots the application, signs the
 * actor in, waits for a shared start time, runs one action, and prints one JSON line. Unlike the other
 * child scripts it sets the authenticated user, so an entry's actor_id is real and its foreign key
 * takes the shared lock on the user's row.
 *
 * Usage: php tests/Support/audit-action.php action actorId targetId startAt [extra]
 *   discount    the target is the charge id
 *   deactivate  the target is the staff member id
 *   save        the target is the encounter id
 *   reschedule  the target is the appointment id; extra is date,start
 *   call-next   the actor is the doctor's user; the target is unused
 *   cancel      the target is the appointment id
 *   check-in    the target is the appointment id
 *
 * Refuses (exit 2) to run against any database but clinic_testing.
 */

use App\Modules\Appointments\Services\AppointmentService;
use App\Modules\Appointments\Services\BookingService;
use App\Modules\Encounters\Services\EncounterService;
use App\Modules\Payments\Services\ChargeService;
use App\Modules\Queues\Services\DoctorQueueService;
use App\Modules\Queues\Services\QueueService;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Services\StaffManagementService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'clinic_testing') {
    fwrite(STDERR, "Refusing to run against any database but clinic_testing.\n");
    exit(2);
}

[, $action, $actorId, $targetId, $startAt] = $argv;
$extra = $argv[5] ?? '';

$actor = User::query()->findOrFail((int) $actorId);
Auth::setUser($actor);

$run = match ($action) {
    'discount' => fn () => $app->make(ChargeService::class)->discount($actor, (int) $targetId, '10.00', 'Synthetic reason')->id,
    'deactivate' => fn () => $app->make(StaffManagementService::class)->deactivate(User::query()->findOrFail((int) $targetId))->id,
    'save' => fn () => $app->make(EncounterService::class)->save((int) $targetId, ['diagnosis' => 'Synthetic '.uniqid()])->id,
    'reschedule' => fn () => $app->make(BookingService::class)->reschedule($actor, (int) $targetId, ...explode(',', $extra))->id,
    'call-next' => fn () => $app->make(DoctorQueueService::class)->callNext($actor)->id,
    'cancel' => fn () => $app->make(AppointmentService::class)->cancel($actor, (int) $targetId, null)->id,
    'check-in' => fn () => $app->make(QueueService::class)->checkIn($actor, (int) $targetId)->id,
    default => throw new InvalidArgumentException("Unknown action: {$action}"),
};

while (microtime(true) < (float) $startAt) {
    usleep(1000);
}

try {
    $result = ['result' => 'ok', 'action' => $action, 'id' => $run()];
} catch (ValidationException $e) {
    $result = ['result' => 'refused', 'action' => $action];
} catch (Throwable $e) {
    // Only the class and SQL state: an exception's message can hold patient details (Constitution XII).
    $result = ['result' => 'error', 'action' => $action, 'class' => $e::class, 'sqlstate' => $e->errorInfo[0] ?? null];
}

echo json_encode($result).PHP_EOL;
exit(0);
