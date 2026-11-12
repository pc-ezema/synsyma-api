<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BookingPolicy extends Model
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
        'free_cancel_hours' => 'integer', 'late_cancel_hours' => 'integer', 'late_cancel_fee_percent' => 'integer', 'call_out_fee_percent' => 'integer',
        'auto_complete_hours' => 'integer',
        'free_reschedules' => 'integer', 'max_extra_hours' => 'integer',
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
        return static::query()->first() ?? static::create([]);
    }

    public function toArrayFlat(): array
    {
        return $this->only(['free_cancel_hours', 'late_cancel_hours', 'late_cancel_fee_percent', 'call_out_fee_percent', 'auto_complete_hours', 'free_reschedules', 'max_extra_hours']);
    }
}