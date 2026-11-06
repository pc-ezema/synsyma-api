<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BookingEvent extends Model
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

    public function toArrayFlat(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'actor' => $this->actor,
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}