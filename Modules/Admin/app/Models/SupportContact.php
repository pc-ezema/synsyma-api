<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class SupportContact extends Model
{
    use HasUuids;

    public const TYPES = ['phone', 'email', 'whatsapp'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

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

    /** Flat shape used by both the admin API and the app. */
    public function toArrayFlat(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'label' => $this->label,
            'value' => $this->value,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

