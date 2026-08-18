<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HoldStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Hold extends Model
{
    protected $fillable = [
        'slot_id',
        'idempotency_key',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => HoldStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class);
    }

    public function isExpiredHeld(): bool
    {
        return $this->status === HoldStatus::Held && $this->expires_at->lte(now());
    }

    /**
     * @return array{
     *     id: int,
     *     slot_id: int,
     *     idempotency_key: string,
     *     status: string,
     *     expires_at: string,
     *     created_at: string|null,
     *     updated_at: string|null
     * }
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'slot_id' => $this->slot_id,
            'idempotency_key' => $this->idempotency_key,
            'status' => $this->status->value,
            'expires_at' => $this->expires_at->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
