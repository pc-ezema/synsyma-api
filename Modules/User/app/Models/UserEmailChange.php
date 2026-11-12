<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Admin\Models\Admin;

class UserEmailChange extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';
 
    protected $table = 'user_email_changes';
 
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];
 
    protected $casts = ['expires_at' => 'datetime', 'confirmed_at' => 'datetime'];

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
 
    /** pending turns into "expired" by itself once its time is up. */
    public function effectiveStatus(): string
    {
        return $this->status === 'pending' && $this->expires_at->isPast() ? 'expired' : $this->status;
    }
 
    public function isOpen(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture();
    }
 
    /** The change history shown on the user's page, newest first. */
    public static function historyFor(string $userId, int $limit = 10): array
    {
        $rows = static::where('user_id', $userId)->latest()->limit($limit)->get();
        $admins = Admin::whereIn('id', $rows->pluck('admin_id')->filter()->all())->pluck('name', 'id');
 
        return $rows->map(fn (self $c) => [
            'id' => $c->id,
            'old_email' => $c->old_email,
            'new_email' => $c->new_email,
            'reason' => $c->reason,
            'status' => $c->effectiveStatus(),
            'requested_by' => $admins[$c->admin_id] ?? null,
            'created_at' => $c->created_at?->toIso8601String(),
            'expires_at' => $c->expires_at?->toIso8601String(),
            'confirmed_at' => $c->confirmed_at?->toIso8601String(),
        ])->values()->all();
    }
}

