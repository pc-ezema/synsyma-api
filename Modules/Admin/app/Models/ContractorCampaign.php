<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ContractorCampaign extends Model
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

    protected $casts = ['is_active' => 'boolean', 'scans' => 'integer'];

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

    public function contractors(): HasMany
    {
        return $this->hasMany(Contractor::class, 'campaign_id');
    }

    /** Needs ->withCount('contractors') to include signups. */
    public function toArrayFlat(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'scans' => $this->scans,
            'signups' => $this->contractors_count ?? 0,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
