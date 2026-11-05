<?php

/**
 * Child process for ConcurrentEncounterTest (feature 008, research R11): boots the application, waits
 * for a shared start time, runs one record action, and prints one JSON line. Errors print the class
 * only, so no clinical text can reach the output.
 *
 * Usage: php tests/Support/encounter-action.php action actorId targetId startAt [text]
 *   start     the target is the visit id
 *   save      the target is the encounter id; complete content, with text as the chief complaint
 *   finalize  the target is the encounter id
 *
 * Refuses (exit 2) to run against any database but clinic_testing.
 */

use App\Modules\Encounters\Services\EncounterService;
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
$text = $argv[5] ?? '';

$actor = User::query()->findOrFail((int) $actorId);
$encounters = $app->make(EncounterService::class);

$run = match ($action) {
    'start' => fn () => $encounters->start($actor, (int) $targetId),
    'save' => fn () => $encounters->save((int) $targetId, [
        'chief_complaint' => $text,
        'examination_findings' => null,
        'diagnosis' => 'Synthetic diagnosis',
        'treatment_plan' => null,
        'prescriptions' => [],
    ]),
    'finalize' => fn () => $encounters->finalize((int) $targetId),
    default => throw new InvalidArgumentException("Unknown action: {$action}"),
};

while (microtime(true) < (float) $startAt) {
    usleep(1000);
}

try {
    $result = ['result' => 'ok', 'id' => $run()->id];
} catch (ValidationException $e) {
    $result = ['result' => 'refused', 'errors' => $e->errors()];
} catch (Throwable $e) {
    $result = ['result' => 'error', 'class' => $e::class];
}

echo json_encode($result).PHP_EOL;
exit(0);
