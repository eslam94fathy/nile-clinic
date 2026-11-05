<?php

namespace App\Modules\Audit\Support;

use App\Modules\Audit\Enums\AuditSubject;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * What an entry may say about a saved model (FR-006, research R4, R5): the names of the fields that
 * changed, and before and after values only for fields on the allow-list. Anything else, including a
 * field added later, keeps its name only, so a mistake loses detail rather than privacy. Encrypted
 * fields are on no list, so their casts never run here.
 */
final class AuditChanges
{
    /**
     * Before and after values are kept only for these fields of each record type.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_VALUES = [
        'staff_member' => ['role', 'is_active'],
        'specialty' => ['is_active'],
        'appointment' => ['status', 'date', 'start_minute', 'end_minute', 'doctor_id'],
        'visit' => ['status', 'doctor_id'],
        'encounter' => ['status'],
        'charge' => ['status', 'fee', 'discount', 'amount_paid'],
        'doctor' => ['fee'],
    ];

    /**
     * Columns that say nothing about the change. The entry already says who and when.
     *
     * @var list<string>
     */
    private const LEFT_OUT = ['id', 'name_search', 'active_flag', 'scheduled_flag', 'with_doctor_flag'];

    /**
     * @return array{fields: list<string>, values: array<string, array{from: mixed, to: mixed}>}
     */
    public static function of(Model $model, AuditSubject $subject): array
    {
        $fields = array_values(array_filter(
            array_keys($model->wasRecentlyCreated ? $model->getAttributes() : $model->getChanges()),
            fn (string $field) => self::isReported($field),
        ));

        $values = [];

        foreach (array_intersect($fields, self::ALLOWED_VALUES[$subject->value] ?? []) as $field) {
            $values[$field] = [
                'from' => $model->wasRecentlyCreated ? null : self::previous($model, $field),
                'to' => self::normalize($model->getAttribute($field)),
            ];
        }

        return ['fields' => $fields, 'values' => $values];
    }

    private static function isReported(string $field): bool
    {
        return ! in_array($field, self::LEFT_OUT, true)
            && ! str_ends_with($field, '_at')
            && ! str_ends_with($field, '_by');
    }

    /**
     * Read through the model's casts on a copy, so that both values have the same form.
     */
    private static function previous(Model $model, string $field): mixed
    {
        return self::normalize((clone $model)->setRawAttributes($model->getPrevious())->getAttribute($field));
    }

    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof CarbonInterface => $value->toDateString(),
            default => $value,
        };
    }
}
