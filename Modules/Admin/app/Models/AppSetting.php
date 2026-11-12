<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** One row of platform-wide settings. Read with AppSetting::current(). */
class AppSetting extends Model
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
        'min_notice_hours' => 'integer', 'max_days_ahead' => 'integer', 'quote_valid_days' => 'integer', 'review_edit_hours' => 'integer',
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

    public static function current(): self
    {
        return static::query()->oldest()->first() ?? static::create([]);
    }

    public function toArrayFlat(): array
    {
        return $this->only([
            'business_name', 'support_email', 'support_phone', 'business_address',
            'min_notice_hours', 'max_days_ahead', 'quote_valid_days', 'review_edit_hours',
        ]);
    }
}