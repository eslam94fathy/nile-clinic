<?php

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Staff\Models\User;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money returned on one charge (data-model.md). Rows are only ever inserted (FR-012).
 */
class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'charge_id',
        'amount',
        'method',
        'reason',
        'refunded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'method' => PaymentMethod::class,
        ];
    }

    /**
     * @return BelongsTo<Charge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function refundedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }

    protected static function newFactory(): RefundFactory
    {
        return RefundFactory::new();
    }
}
