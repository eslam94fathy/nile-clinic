<?php

/**
 * Child process for ConcurrentPaymentTest (feature 009, research R11): boots the application, waits for
 * a shared start time, runs one action, and prints one JSON line.
 *
 * Usage: php tests/Support/payment-action.php action actorId targetId startAt [amount]
 *   charge    the target is the visit id
 *   pay       the target is the charge id; cash
 *   refund    the target is the charge id; cash, a synthetic reason
 *   undo      the target is the queue entry id
 *
 * A charge of a visit that an undo has removed prints {"result":"not_found"}.
 *
 * Refuses (exit 2) to run against any database but clinic_testing.
 */

use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Exceptions\VisitNotFoundException;
use App\Modules\Payments\Services\ChargeService;
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
$amount = $argv[5] ?? '';

$actor = User::query()->findOrFail((int) $actorId);
$charges = $app->make(ChargeService::class);

$run = match ($action) {
    'charge' => fn () => $charges->charge($actor, (int) $targetId)->id,
    'pay' => fn () => $charges->pay($actor, (int) $targetId, $amount, PaymentMethod::Cash)->id,
    'refund' => fn () => $charges->refund($actor, (int) $targetId, $amount, PaymentMethod::Cash, 'Synthetic refund')->id,
    'undo' => function () use ($app, $targetId) {
        $app->make(QueueService::class)->undo((int) $targetId);

        return (int) $targetId;
    },
    default => throw new InvalidArgumentException("Unknown action: {$action}"),
};

while (microtime(true) < (float) $startAt) {
    usleep(1000);
}

try {
    $result = ['result' => 'ok', 'id' => $run()];
} catch (ValidationException $e) {
    $result = ['result' => 'refused', 'errors' => $e->errors()];
} catch (VisitNotFoundException) {
    $result = ['result' => 'not_found'];
} catch (Throwable $e) {
    $result = ['result' => 'error', 'class' => $e::class];
}

echo json_encode($result).PHP_EOL;
exit(0);
