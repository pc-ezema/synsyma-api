<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AppVersion extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public const PLATFORMS = ['ios', 'android'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

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

    /** One row per platform, created on first use. Cached for a minute so the middleware stays cheap. */
    public static function forPlatform(string $platform): self
    {
        $platform = in_array($platform, self::PLATFORMS, true) ? $platform : 'android';

        return Cache::remember("app_version.$platform", 60, fn () => self::firstOrCreate(['platform' => $platform]));
    }

    public static function flush(): void
    {
        foreach (self::PLATFORMS as $p) {
            Cache::forget("app_version.$p");
        }
    }

    /** -1, 0 or 1. Compares 1.2.10 against 1.2.9 properly and ignores any +build or -suffix part. */
    public static function compare(string $a, string $b): int
    {
        $parse = function (string $v): array {
            $v = preg_replace('/[+-].*$/', '', trim($v));
            $p = array_map('intval', explode('.', $v));

            return array_pad($p, 3, 0);
        };

        return $parse($a) <=> $parse($b);
    }

    public function toArrayFlat(): array
    {
        return [
            'platform' => $this->platform,
            'min_version' => $this->min_version,
            'latest_version' => $this->latest_version,
            'store_url' => $this->store_url,
            'message' => $this->message,
        ];
    }
}