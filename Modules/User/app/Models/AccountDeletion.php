<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AccountDeletion extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public const REASONS = [
        'not_using' => 'I no longer use the app',
        'found_alternative' => 'I found another service',
        'privacy' => 'Privacy concerns',
        'bad_experience' => 'I had a bad experience',
        'other' => 'Other',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    protected $casts = ['account_created_at' => 'datetime'];

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
            'user_id' => $this->user_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'reason' => $this->reason,
            'reason_label' => self::REASONS[$this->reason] ?? null,
            'comment' => $this->comment,
            'platform' => $this->platform,
            'ip_address' => $this->ip_address,
            'account_created_at' => $this->account_created_at?->toIso8601String(),
            'deleted_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

