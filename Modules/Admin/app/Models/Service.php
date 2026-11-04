<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Service extends Model
{
       use HasUuids, SoftDeletes, HasCatalogImage;

    public $incrementing = false;

    protected $keyType = 'string';

    public const TIERS = ['premium', 'regular'];
    public const PRICE_TYPES = ['hourly', 'fixed', 'quote'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    protected $casts = [
        'base_price' => 'decimal:2',
        'additional_hour_price' => 'decimal:2',
        'included_hours' => 'integer',
        'team_size' => 'integer',
        'parts_tbd' => 'boolean',
        'home_protection' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

     protected function imageFolder(): string
    {
        return 'catalog/services';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    private function money(float|string $value): string
    {
        $symbols = ['USD' => '$', 'NGN' => '₦', 'GBP' => '£', 'EUR' => '€'];
        $symbol = $symbols[$this->currency] ?? ($this->currency . ' ');
        $number = rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.');

        return $symbol . $number;
    }

    /** Just the money part, like "$200", or null when there is no price. */
    public function basePriceLabel(): ?string
    {
        return $this->base_price === null ? null : $this->money($this->base_price);
    }

    /** "$200 (1st 2 hrs)", "$255" or "Get a quote". */
    public function priceLabel(): string
    {
        if ($this->price_type === 'quote' || $this->base_price === null) {
            return 'Get a quote';
        }

        $price = $this->money($this->base_price);

        return $this->price_type === 'hourly' && $this->included_hours
            ? "{$price} (1st {$this->included_hours} hrs)"
            : $price;
    }

    /** "$85 per added hour", or null when there is no hourly add-on. */
    public function additionalLabel(): ?string
    {
        if ($this->price_type !== 'hourly' || $this->additional_hour_price === null) {
            return null;
        }

        return $this->money($this->additional_hour_price) . ' per added hour';
    }

    /** Flat shape used by the admin API (and later by the app). */
    public function toArrayFlat(): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'category_name' => $this->category?->name,
            'category_slug' => $this->category?->slug,
            'name' => $this->name,
            'slug' => $this->slug,
            'tier' => $this->tier,
            'description' => $this->description,
            'image_url' => $this->imageUrl(),
            'price_type' => $this->price_type,
            'currency' => $this->currency,
            'base_price' => $this->base_price !== null ? (float) $this->base_price : null,
            'included_hours' => $this->included_hours,
            'additional_hour_price' => $this->additional_hour_price !== null ? (float) $this->additional_hour_price : null,
            'price_label' => $this->priceLabel(),
            'additional_label' => $this->additionalLabel(),
            'parts_note' => $this->parts_tbd ? 'Fixes with parts replacement: TBD' : null,
            'parts_tbd' => $this->parts_tbd,
            'team_size' => $this->team_size,
            'home_protection' => $this->home_protection,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
