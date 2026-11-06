<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A company or an individual who does the work. Only an admin manages them.
 */
class Contractor extends Model
{
    use HasUuids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    public const TYPES = ['company', 'individual'];
    public const STATUSES = ['pending', 'approved', 'suspended', 'rejected'];
    public const COUNTRIES = ['CA' => 'Canada', 'US' => 'United States', 'GB' => 'United Kingdom'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    protected $casts = ['commission_percent' => 'decimal:2', 'approved_at' => 'datetime'];

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

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(ContractorCampaign::class, 'campaign_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ServiceCategory::class, 'contractor_categories', 'contractor_id', 'category_id');
    }

    public function areas(): HasMany
    {
        return $this->hasMany(ContractorArea::class, 'contractor_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ContractorDocument::class, 'contractor_id');
    }

    /** List rows are light. Pass $full for the detail view. */
    public function toArrayFlat(bool $full = false): array
    {
        $row = [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'contact_name' => $this->contact_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'country' => $this->country,
            'city' => $this->city,
            'region' => $this->region,
            'status' => $this->status,
            'campaign_name' => $this->campaign?->name,
            'source' => $this->source,
            'categories_count' => $this->categories_count ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        if (! $full) {
            return $row;
        }

        return $row + [
            'address_line1' => $this->address_line1,
            'postal_code' => $this->postal_code,
            'tax_number' => $this->tax_number,
            'about' => $this->about,
            'status_reason' => $this->status_reason,
            'commission_percent' => $this->commission_percent !== null ? (float) $this->commission_percent : null,
            'admin_notes' => $this->admin_notes,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'categories' => $this->categories->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
            'areas' => $this->areas->map(fn ($a) => ['id' => $a->id, 'country' => $a->country, 'region' => $a->region, 'city' => $a->city])->values(),
            'documents' => $this->documents->map->toArrayFlat()->values(),
        ];
    }
}
