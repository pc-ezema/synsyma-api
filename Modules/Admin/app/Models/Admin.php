<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Support\Str;

// use Modules\Admin\Database\Factories\AdminFactory;

class Admin extends Authenticatable
{
    use HasUuids, HasApiTokens, HasRoles;

    protected $guard_name = 'admin';

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
            'last_login_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
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

    public function toProfileArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'photo_url' => $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null,
            'role' => $this->getRoleNames()->first(),
            'roles' => $this->getRoleNames()->values()->all(),
            'permissions' => $this->getAllPermissions()->pluck('name')->values()->all(),
            'last_login_at' => $this->last_login_at?->toIso8601String(), // remove this line if you do not track it
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}