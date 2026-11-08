<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Where to send a contractor's money. The sensitive part lives in one encrypted column.
 */
class ContractorPayoutDetail extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'contractor_payout_details';

    public const METHODS = [
        'bank_transfer' => 'Bank transfer',
        'etransfer' => 'Interac e-Transfer',
        'paypal' => 'PayPal',
        'other' => 'Other',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    protected $casts = ['details' => 'encrypted:array'];

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

    /** Shorten a secret to its last 4 characters: ••••4821. */
    public static function mask(?string $v): string
    {
        $v = (string) $v;
        if ($v === '') {
            return '';
        }
        if (str_contains($v, '@')) {
            [$name, $domain] = explode('@', $v, 2);
            return mb_substr($name, 0, 1) . '•••@' . $domain;
        }
        return mb_strlen($v) <= 4 ? '••••' : '••••' . mb_substr($v, -4);
    }

    /** One short line for lists and the pay dialog. Never contains a full number. */
    public function summary(): string
    {
        $d = $this->details ?? [];
        $label = self::METHODS[$this->method] ?? $this->method;
        $tail = match ($this->method) {
            'bank_transfer' => ($this->bank_name ? $this->bank_name . ' · ' : '') . self::mask($d['account_number'] ?? ''),
            'etransfer', 'paypal' => self::mask($d['email'] ?? ''),
            default => '',
        };
        return trim($label . ($tail !== '' ? ' · ' . $tail : ''));
    }

    /** $reveal true gives the real values (payouts.manage only). Otherwise every secret is masked. */
    public function toArrayFlat(bool $reveal): array
    {
        $details = [];
        foreach (($this->details ?? []) as $k => $v) {
            // "other" instructions and the US account type are not secret; everything else is.
            $details[$k] = ($reveal || in_array($k, ['account_type'], true)) ? $v : ($k === 'instructions' ? self::mask($v) : self::mask((string) $v));
        }

        return [
            'method' => $this->method,
            'method_label' => self::METHODS[$this->method] ?? $this->method,
            'country' => $this->country,
            'holder_name' => $this->holder_name,
            'bank_name' => $this->bank_name,
            'details' => $details,
            'summary' => $this->summary(),
            'revealed' => $reveal,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}