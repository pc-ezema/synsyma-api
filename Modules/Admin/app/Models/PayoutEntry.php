<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\User\Models\Booking;
use Illuminate\Support\Str;

class PayoutEntry extends Model
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

    protected $casts = ['amount' => 'decimal:2', 'gross' => 'decimal:2', 'commission_percent' => 'decimal:2', 'payable_at' => 'datetime'];

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

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class, 'contractor_id');
    }

    /** paid | payable | hold */
    public function state(): string
    {
        if ($this->payout_id) {
            return 'paid';
        }

        return $this->payable_at->isFuture() ? 'hold' : 'payable';
    }

    public function toArrayFlat(): array
    {
        $b = $this->booking;

        return [
            'id' => $this->id,
            'type' => $this->type,
            'state' => $this->state(),
            'amount' => (float) $this->amount,
            'gross' => $this->gross !== null ? (float) $this->gross : null,
            'commission_percent' => $this->commission_percent !== null ? (float) $this->commission_percent : null,
            'currency' => $this->currency,
            'note' => $this->note,
            'booking_id' => $this->booking_id,
            'reference' => $b?->reference,
            'service_name' => $b?->service_name,
            'payable_at' => $this->payable_at?->toIso8601String(),
            'payout_id' => $this->payout_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}