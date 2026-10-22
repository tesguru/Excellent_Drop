<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZoneTld extends Model
{
    protected $table = 'zone_tlds';

    protected $fillable = [
        'tld',
        'status',
        'expiry_at',
        'last_download_at',
        'last_modified',
        'file_size',
        'file_path',
        'rows',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'expiry_at' => 'date',
            'last_download_at' => 'datetime',
            'last_modified' => 'datetime',
            'file_size' => 'integer',
            'rows' => 'integer',
        ];
    }

    public function daysUntilExpiry(): ?int
    {
        if ($this->expiry_at === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->expiry_at, false);
    }

    public function isExpiringSoon(int $warningDays): bool
    {
        $days = $this->daysUntilExpiry();

        return $days !== null && $days <= $warningDays;
    }
}
