<?php

namespace App\Modules\Audit\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Enums\AuditActorType;
use App\Modules\Audit\Enums\AuditOutcome;
use App\Modules\Audit\Enums\AuditSubject;
use App\Modules\Audit\Repositories\Contracts\AuditEntryRepository;
use App\Modules\Audit\Support\AuditChanges;
use App\Modules\Staff\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Writes the audit trail. Services call it inside their own transactions, so a change and its entry
 * stand or fall together (FR-007, research R2). It never opens a transaction and never runs a read
 * query, so it cannot fix a transaction's read view before its locks. Callers pass only names,
 * allow-listed values, and internal identifiers: never request text or models (FR-008).
 */
class AuditTrail
{
    public function __construct(
        private readonly AuditEntryRepository $entries,
    ) {}

    /**
     * Always writes. A failure propagates, so that the action does not go ahead (FR-007).
     *
     * @param  array<string, mixed>  $details
     */
    public function record(
        AuditAction $action,
        ?AuditSubject $subject = null,
        ?int $subjectId = null,
        ?int $patientId = null,
        array $details = [],
        ?User $actor = null,
    ): void {
        $this->write($action, AuditOutcome::Succeeded, AuditActorType::System, $subject, $subjectId, $patientId, $details, $actor);
    }

    /**
     * Writes only if the saved model changed, or if extra fields changed that the model cannot show.
     *
     * @param  list<string>  $extraFields
     */
    public function recordChanges(
        AuditAction $action,
        Model $model,
        AuditSubject $subject,
        ?int $patientId = null,
        array $extraFields = [],
        ?int $subjectId = null,
    ): void {
        $changes = AuditChanges::of($model, $subject);
        $changes['fields'] = array_values(array_unique([...$changes['fields'], ...$extraFields]));

        if ($changes['fields'] === []) {
            return;
        }

        $this->record($action, $subject, $subjectId ?? $model->getKey(), $patientId, $changes);
    }

    /**
     * A refused attempt. A failure here is only reported, so that the refusal's response stays exactly
     * what features 002 and 008 require, and nothing is being disclosed (FR-005, research R7).
     */
    public function recordRefusal(
        AuditAction $action,
        ?AuditSubject $subject = null,
        ?int $subjectId = null,
        ?int $patientId = null,
        ?User $actor = null,
    ): void {
        try {
            $this->write($action, AuditOutcome::Refused, AuditActorType::Unknown, $subject, $subjectId, $patientId, [], $actor);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function write(
        AuditAction $action,
        AuditOutcome $outcome,
        AuditActorType $withoutActor,
        ?AuditSubject $subject,
        ?int $subjectId,
        ?int $patientId,
        array $details,
        ?User $actor,
    ): void {
        $actor ??= Auth::user();

        $this->entries->append([
            'action' => $action,
            'outcome' => $outcome,
            'actor_type' => $actor !== null ? AuditActorType::Staff : $withoutActor,
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role->value,
            'subject_type' => $subject,
            'subject_id' => $subjectId,
            'patient_id' => $patientId,
            'network_address' => $actor === null && $withoutActor === AuditActorType::System ? null : request()->ip(),
            'details' => $details === [] ? null : $details,
        ]);
    }
}
