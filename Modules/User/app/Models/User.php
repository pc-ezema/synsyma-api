<?php

namespace Modules\User\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    protected $hidden = ['password', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
        ];
    }

    /** The shape every endpoint returns for a user. */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => trim("{$this->first_name} {$this->last_name}"),
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'email_verified' => $this->email_verified_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
            'terms_accepted_at' => $this->terms_accepted_at?->toIso8601String(),
            'photo_url' => $this->profile_photo_path
                ? Storage::disk('public')->url($this->profile_photo_path)
                : null,
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
}
