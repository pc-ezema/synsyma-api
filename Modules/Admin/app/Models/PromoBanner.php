<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PromoBanner extends Model
{
    use HasCatalogImage, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    protected $casts = ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'sort_order' => 'integer', 'is_active' => 'boolean'];

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

    protected function imageFolder(): string
    {
        return 'banners';
    }

    /** What the app shows right now. */
    public function scopeLive(Builder $q): Builder
    {
        $today = now()->utc()->toDateString();

        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_on')->orWhere('starts_on', '<=', $today))
            ->where(fn ($w) => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->orderBy('sort_order')->orderByDesc('created_at');
    }

    public function toArrayFlat(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'image_url' => $this->imageUrl(),
            'link_type' => $this->link_type,
            'link_value' => $this->link_value,
            'promo_code' => $this->promo_code,
            'starts_on' => $this->starts_on?->format('Y-m-d'),
            'ends_on' => $this->ends_on?->format('Y-m-d'),
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
        ];
    }
}