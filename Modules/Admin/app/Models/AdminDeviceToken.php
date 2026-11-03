<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AdminDeviceToken extends Model
{
    use HasUuids, MassPrunable;

    public $incrementing = false;

    protected $keyType = 'string';

     /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

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

    public function prunable(): Builder
    {
        return static::where('last_seen_at', '<', now()->subDays(90));
    }
}