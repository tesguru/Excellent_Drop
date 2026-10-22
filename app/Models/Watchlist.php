<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Watchlist extends Model
{
    protected $table = 'watchlist';

    protected $fillable = [
        'domain',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function notesList(): HasMany
    {
        return $this->hasMany(Note::class, 'domain', 'domain');
    }
}
