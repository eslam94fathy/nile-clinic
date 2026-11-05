<?php

namespace App\Modules\Audit\Http\Requests;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Enums\AuditOutcome;
use App\Modules\Audit\Enums\AuditSubject;
use App\Modules\Audit\Models\AuditEntry;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\Rule;
use Throwable;

class SearchAuditEntriesRequest extends FormRequest
{
    private const FILTERS = ['from', 'to', 'staff_id', 'patient_id', 'subject_type', 'subject_id', 'action', 'outcome'];

    private const FIELDS = [...self::FILTERS, 'per_page', 'cursor'];

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', AuditEntry::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'staff_id' => ['nullable', 'integer', 'min:1'],
            'patient_id' => ['nullable', 'integer', 'min:1'],
            'subject_type' => ['nullable', 'required_with:subject_id', Rule::enum(AuditSubject::class)],
            'subject_id' => ['nullable', 'required_with:subject_type', 'integer', 'min:1'],
            'action' => ['nullable', Rule::enum(AuditAction::class)],
            'outcome' => ['nullable', Rule::enum(AuditOutcome::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:1000', $this->validCursor(...)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_combine(
            self::FIELDS,
            array_map(fn (string $key) => __("audit::attributes.{$key}"), self::FIELDS),
        );
    }

    /**
     * The filters that were sent, typed. Internal identifiers and fixed values only, so they are safe
     * to record with the search (FR-011).
     *
     * @return array{from?: string, to?: string, staff_id?: int, patient_id?: int, subject_type?: string, subject_id?: int, action?: string, outcome?: string}
     */
    public function filters(): array
    {
        $filters = array_filter(
            $this->safe()->only(self::FILTERS),
            fn (mixed $value) => $value !== null && $value !== '',
        );

        foreach (['staff_id', 'patient_id', 'subject_id'] as $key) {
            if (isset($filters[$key])) {
                $filters[$key] = (int) $filters[$key];
            }
        }

        return $filters;
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }

    /**
     * A cursor must decode to a position. The paginator otherwise fails with a server error on one that
     * does not (research R9).
     */
    private function validCursor(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $position = Cursor::fromEncoded($value)?->toArray()['id'] ?? null;
        } catch (Throwable) {
            $position = null;
        }

        if (! is_int($position)) {
            $fail(__('audit::messages.invalid_cursor'));
        }
    }
}
