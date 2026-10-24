<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RdapStatus extends Model
{
    public const PENDING_DELETE = 'pending_delete';

    public const REDEMPTION = 'redemption';

    public const PENDING_RESTORE = 'pending_restore';

    public const AUTO_RENEW = 'auto_renew';

    public const ON_HOLD = 'on_hold';

    public const EXPIRED = 'expired';

    public const RENEWED = 'renewed';

    /**
     * The dashboard hides these from the Daily and Overview candidate lists.
     */
    public const HIDDEN_STATUSES = [self::ON_HOLD, self::RENEWED];

    public const SAVEABLE = [
        self::PENDING_DELETE,
        self::REDEMPTION,
        self::PENDING_RESTORE,
        self::AUTO_RENEW,
        self::ON_HOLD,
        self::EXPIRED,
        self::RENEWED,
    ];

    protected $table = 'rdap_status';

    protected $fillable = [
        'domain',
        'status',
        'status_label',
        'expiry',
        'last_checked',
    ];

    protected function casts(): array
    {
        return [
            'expiry' => 'date:Y-m-d',
            'last_checked' => 'datetime',
            'restored' => 'boolean',
        ];
    }

    /**
     * Second level labels of the saved statuses that should not be listed.
     * Candidates are .com names, so only .com domains can hide one.
     *
     * @return array<int, string>
     */
    public static function hiddenNames(): array
    {
        return static::query()
            ->whereIn('status', self::HIDDEN_STATUSES)
            ->pluck('domain')
            ->map(function ($domain): ?string {
                $domain = strtolower(trim((string) $domain));

                return str_ends_with($domain, '.com') ? substr($domain, 0, -4) : null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
