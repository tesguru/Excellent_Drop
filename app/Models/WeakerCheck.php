<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeakerCheck extends Model
{
    protected $table = 'weaker_checks';

    protected $fillable = [
        'domain',
        'http_status',
        'has_contact_page',
        'title',
        'nameservers',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'has_contact_page' => 'boolean',
            'nameservers' => 'array',
            'checked_at' => 'datetime',
        ];
    }
}
