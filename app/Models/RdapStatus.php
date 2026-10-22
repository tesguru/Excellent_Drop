<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RdapStatus extends Model
{
    protected $table = 'rdap_status';

    protected $fillable = [
        'domain',
        'status',
        'expiry',
        'pending_delete_date',
        'predicted_drop_date',
        'last_checked',
        'restored',
    ];

    protected function casts(): array
    {
        return [
            'expiry' => 'date',
            'pending_delete_date' => 'date',
            'predicted_drop_date' => 'date',
            'last_checked' => 'datetime',
            'restored' => 'boolean',
        ];
    }

    /**
     * A name that is back in the zone is no longer an active candidate.
     */
    public function scopeActive($query)
    {
        return $query->where('restored', false);
    }
}
