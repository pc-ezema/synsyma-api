<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PayoutSetting extends Model
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

    protected $casts = ['default_commission_percent' => 'decimal:2', 'hold_days' => 'integer'];

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

    public static function current(): self
    {
        return static::query()->first() ?? static::create([]);
    }

    public function toArrayFlat(): array
    {
        return ['default_commission_percent' => (float) $this->default_commission_percent, 'hold_days' => $this->hold_days];
    }
}