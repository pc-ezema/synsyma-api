<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ContractorDocument extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public const KINDS = ['id', 'insurance', 'licence', 'other'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    protected $casts = ['verified' => 'boolean', 'size' => 'integer'];


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
            'kind' => $this->kind,
            'original_name' => $this->original_name,
            'mime' => $this->mime,
            'size' => $this->size,
            'verified' => $this->verified,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    public function contractor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Contractor::class, 'contractor_id');
    }
}
