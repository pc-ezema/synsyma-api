<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A customer's 1 to 5 rating of a finished job. */
class Review extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** How long the customer can still change their review. */
    public const EDIT_DAYS = 7;

    protected $table = 'reviews';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    protected $casts = ['rating' => 'integer', 'is_hidden' => 'boolean', 'edited_at' => 'datetime'];

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

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(\Modules\Admin\Models\Contractor::class);
    }

    public function canEdit(): bool
    {
        return $this->created_at !== null && $this->created_at->gt(now()->subDays(self::EDIT_DAYS));
    }

    /** The admin flag exposes moderation fields. The customer only ever sees their own review. */
    public function toArrayFlat(bool $admin = false): array
    {
        $row = [
            'id' => $this->id,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'created_at' => $this->created_at?->toIso8601String(),
            'edited_at' => $this->edited_at?->toIso8601String(),
        ];
        if ($admin) {
            $row += ['is_hidden' => $this->is_hidden, 'hidden_reason' => $this->hidden_reason];
        }

        return $row;
    }
}