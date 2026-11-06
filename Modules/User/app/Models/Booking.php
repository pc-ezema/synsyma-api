<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Admin\Models\Contractor;
use Modules\Admin\Models\Service;
use Illuminate\Support\Str;

class Booking extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public const STATUSES = [
        'pending_payment', 'confirmed', 'assigned', 'in_progress', 'completed',
        'cancelled', 'refunded', 'quote_requested', 'quote_sent',
    ];

    /** Which status can follow which. "assigned" is only reached through the assign action. */
    public const TRANSITIONS = [
        'pending_payment' => ['confirmed', 'cancelled'],
        'confirmed' => ['cancelled'],
        'assigned' => ['confirmed', 'in_progress', 'cancelled'],
        'in_progress' => ['completed', 'cancelled'],
        'quote_requested' => ['quote_sent', 'cancelled'],
        'quote_sent' => ['cancelled'],
        'completed' => [],
        'cancelled' => [],
        'refunded' => [],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime', 'assigned_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
        'base_price' => 'decimal:2', 'additional_hour_price' => 'decimal:2', 'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2', 'tax_total' => 'decimal:2', 'total' => 'decimal:2',
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

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id')->withTrashed();
    }
 
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class, 'contractor_id');
    }
 
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'user_id');
    }
 
    public function events(): HasMany
    {
        return $this->hasMany(BookingEvent::class, 'booking_id')->oldest();
    }
 
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'booking_id')->latest();
    }
 
    /** The payment worth showing: the one that went through, otherwise the latest attempt. */
    public function mainPayment(): ?Payment
    {
        $all = $this->payments;
 
        return $all->first(fn ($p) => in_array($p->status, ['succeeded', 'refunded', 'partially_refunded'], true)) ?? $all->first();
    }
 
    public function log(string $type, string $actor, ?string $from = null, ?string $to = null, ?string $note = null): void
    {
        $this->events()->create(['type' => $type, 'actor' => $actor, 'from_status' => $from, 'to_status' => $to, 'note' => $note]);
    }
 
    public static function newReference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I
        do {
            $ref = 'SY-' . collect(range(1, 6))->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])->implode('');
        } while (static::where('reference', $ref)->exists());
 
        return $ref;
    }
 
    public function money(float|string|null $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $symbol = ['USD' => '$', 'GBP' => '£'][$this->currency] ?? ($this->currency . ' ');
 
        return $symbol . number_format((float) $v, 2, '.', ',');
    }
 
    public function customerName(): string
    {
        $u = $this->user;
        if (! $u) {
            return 'Deleted user';
        }
        $name = $u->name ?? trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
 
        return $name !== '' ? $name : ($u->email ?? 'Customer');
    }
 
    /** $full adds the details only the admin screen needs. */
    public function toArrayFlat(bool $full = false, bool $admin = false): array
    {
        $local = $this->starts_at?->copy()->setTimezone($this->timezone);
 
        $row = [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'service_id' => $this->service_id,
            'service_name' => $this->service_name,
            'category_name' => $this->category_name,
            'tier' => $this->tier,
            'price_type' => $this->price_type,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'tax_total' => $this->tax_total,
            'total' => $this->total,
            'total_label' => $this->price_type === 'quote' ? 'Quote' : $this->money($this->total),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'starts_at_local' => $local?->format('Y-m-d\TH:i'),
            'timezone' => $this->timezone,
            'country' => $this->country,
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'city' => $this->city,
            'region' => $this->region,
            'postal_code' => $this->postal_code,
            'instructions' => $this->instructions,
            'notes' => $this->notes,
            'contractor_name' => $this->contractor?->name,
            'image_url' => $this->service?->imageUrl(),
            'included_hours' => $this->included_hours,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
 
        if ($admin) {
            $row += [
                'customer_name' => $this->customerName(),
                'customer_email' => $this->user?->email,
                'customer_phone' => $this->user?->phone ?? null,
                'contractor_id' => $this->contractor_id,
                'admin_notes' => $this->admin_notes,
            ];
        }
        if ($full) {
            $row += [
                'base_price' => $this->base_price,
                'additional_hour_price' => $this->additional_hour_price,
                'promo_code' => $this->promo_code,
                'assigned_at' => $this->assigned_at?->toIso8601String(),
                'completed_at' => $this->completed_at?->toIso8601String(),
                'cancelled_at' => $this->cancelled_at?->toIso8601String(),
                'payment' => $this->mainPayment()?->toArrayFlat(),
                'events' => $this->events->map->toArrayFlat()->values(),
            ];
        }
 
        return $row;
    }
}
