<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Message extends Model
{
    use HasUuids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    // Keep microseconds, otherwise polling can return the same message twice.
    protected $dateFormat = 'Y-m-d H:i:s.u';

   /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    protected $hidden = ['attachment_path'];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'attachment_size' => 'integer',
        ];
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

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}