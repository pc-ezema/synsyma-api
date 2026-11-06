<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Address extends Model
{
    use HasUuids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

     /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    protected $casts = ['is_default' => 'boolean'];

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

    public function oneLine(): string
    {
        return collect([$this->address_line1, $this->address_line2, $this->city, $this->region, $this->postal_code])
            ->filter()->implode(', ');
    }

    public function toArrayFlat(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'country' => $this->country,
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'city' => $this->city,
            'region' => $this->region,
            'postal_code' => $this->postal_code,
            'instructions' => $this->instructions,
            'is_default' => $this->is_default,
            'one_line' => $this->oneLine(),
        ];
    }
}