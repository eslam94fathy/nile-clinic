<?php

/**
 * Child process for ConcurrentQueueTest (feature 007, research R9): boots the application, waits
 * for a shared start time, runs one queue action, and prints one JSON line.
 *
 * Usage: php tests/Support/queue-action.php action actorId targetId startAt
 *   check-in   the target is the appointment id
 *   call-next  the actor is the doctor's user; the target is unused
 *
 * Refuses (exit 2) to run against any database but clinic_testing.
 */

use App\Modules\Queues\Exceptions\QueueConflictException;
use App\Modules\Queues\Services\DoctorQueueService;
use App\Modules\Queues\Services\QueueService;
use App\Modules\Staff\Models\User;
use Illuminate\Contracts\Console\Kernel;
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

$actor = User::query()->findOrFail((int) $actorId);

$run = match ($action) {
    'check-in' => fn () => $app->make(QueueService::class)->checkIn($actor, (int) $targetId),
    'call-next' => fn () => $app->make(DoctorQueueService::class)->callNext($actor),
    default => throw new InvalidArgumentException("Unknown action: {$action}"),
};

while (microtime(true) < (float) $startAt) {
    usleep(1000);
}

try {
    $entry = $run();
    $result = ['result' => 'ok', 'id' => $entry->id, 'queue_number' => $entry->queue_number];
} catch (ValidationException $e) {
    $result = ['result' => 'refused', 'errors' => $e->errors()];
} catch (QueueConflictException $e) {
    $result = ['result' => 'conflict', 'reason' => $e->reason];
} catch (Throwable $e) {
    $result = ['result' => 'error', 'class' => $e::class, 'message' => $e->getMessage()];
}

echo json_encode($result).PHP_EOL;
exit(0);
