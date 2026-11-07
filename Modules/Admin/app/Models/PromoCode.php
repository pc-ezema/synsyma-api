<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\User\Models\Booking;

class PromoCode extends Model
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

    protected $casts = [
        'value' => 'decimal:2', 'max_discount' => 'decimal:2', 'min_subtotal' => 'decimal:2',
        'starts_on' => 'date:Y-m-d', 'expires_on' => 'date:Y-m-d',
        'max_uses' => 'integer', 'max_uses_per_user' => 'integer',
        'first_booking_only' => 'boolean', 'is_active' => 'boolean',
    ];

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

    /**
     * Bookings that count as a use of a code: anything paid or confirmed, plus unpaid ones started in the last hour
     * (so two people cannot grab the last use at once). Cancelled bookings and stale unpaid ones free the use again.
     */
    public static function usedScope(): Builder
    {
        return Booking::query()->whereNotNull('promo_code')->where(function (Builder $q) {
            $q->whereNotIn('status', ['pending_payment', 'cancelled'])
                ->orWhere(fn (Builder $p) => $p->where('status', 'pending_payment')->where('created_at', '>', now()->subHour()));
        });
    }

    /** @return array<string, array{used:int, saved:float}> keyed by code */
    public static function usageFor(array $codes): array
    {
        if (! $codes) {
            return [];
        }
        $rows = static::usedScope()->whereIn('promo_code', $codes)
            ->selectRaw('promo_code, count(*) as n, coalesce(sum(discount_total), 0) as saved')->groupBy('promo_code')->get();

        return $rows->mapWithKeys(fn ($r) => [$r->promo_code => ['used' => (int) $r->n, 'saved' => round((float) $r->saved, 2)]])->all();
    }

    public function usedCount(): int
    {
        return static::usedScope()->where('promo_code', $this->code)->count();
    }

    public function usedByUser(string $userId): int
    {
        return static::usedScope()->where('promo_code', $this->code)->where('user_id', $userId)->count();
    }

    /** Has this code ever been put on a booking (any status)? Used code strings can no longer be renamed or deleted. */
    public function everUsed(): bool
    {
        return Booking::where('promo_code', $this->code)->exists();
    }

    public function valueLabel(): string
    {
        $v = rtrim(rtrim(number_format((float) $this->value, 2, '.', ''), '0'), '.');

        return $this->type === 'percent' ? "{$v}% off" : '$' . number_format((float) $this->value, 2) . ' off';
    }

    public function toArrayFlat(?array $usage = null): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'description' => $this->description,
            'type' => $this->type,
            'value' => $this->value,
            'value_label' => $this->valueLabel(),
            'max_discount' => $this->max_discount,
            'min_subtotal' => $this->min_subtotal,
            'starts_on' => $this->starts_on?->format('Y-m-d'),
            'expires_on' => $this->expires_on?->format('Y-m-d'),
            'max_uses' => $this->max_uses,
            'max_uses_per_user' => $this->max_uses_per_user,
            'first_booking_only' => $this->first_booking_only,
            'is_active' => $this->is_active,
            'state' => $this->state(),
            'used' => $usage['used'] ?? 0,
            'saved' => $usage['saved'] ?? 0,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** active | scheduled | expired | off  (a code that has run out of uses still says active; the Used column shows it). */
    public function state(): string
    {
        $today = now()->utc()->toDateString();
        if (! $this->is_active) {
            return 'off';
        }
        if ($this->starts_on && $this->starts_on->format('Y-m-d') > $today) {
            return 'scheduled';
        }
        if ($this->expires_on && $this->expires_on->format('Y-m-d') < $today) {
            return 'expired';
        }

        return 'active';
    }
}