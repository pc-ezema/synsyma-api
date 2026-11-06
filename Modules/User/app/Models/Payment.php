<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Payment extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2', 'refunded_total' => 'decimal:2', 'paid_at' => 'datetime'];

    // Automatically generate when creating a new record
    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    public function toArrayFlat(): array
    {
        return [
            'id' => $this->id,
            'purpose' => $this->purpose,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'card_brand' => $this->card_brand,
            'card_last4' => $this->card_last4,
            'refunded_total' => $this->refunded_total,
            'failure_message' => $this->failure_message,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'stripe_payment_intent_id' => $this->stripe_payment_intent_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}