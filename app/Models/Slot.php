<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Slot extends Model
{
    protected $fillable = [
        'capacity',
        'remaining',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'remaining' => 'integer',
        ];
    }

    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }
}
