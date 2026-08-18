<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\HoldStatus;
use App\Exceptions\HoldExpiredException;
use App\Exceptions\InvalidHoldStateException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Hold;
use App\Models\Slot;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SlotService
{
    public const AVAILABILITY_GENERATION_KEY = 'slots:availability:generation';

    public const AVAILABILITY_CACHE_PREFIX = 'slots:availability:v1';

    public const AVAILABILITY_LOCK_PREFIX = 'slots:availability:rebuild';

    public const AVAILABILITY_TTL_SECONDS = 10;

    /**
     * @return list<array{slot_id: int, capacity: int, remaining: int}>
     */
    public function getAvailability(): array
    {
        $generation = $this->currentGeneration();
        $cacheKey = self::cacheKeyForGeneration($generation);

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $lock = Cache::lock(self::lockKeyForGeneration($generation), 5);

        try {
            return $lock->block(3, function () use ($generation, $cacheKey): array {
                if ($this->currentGeneration() !== $generation) {
                    return $this->getAvailability();
                }

                $cached = Cache::get($cacheKey);

                if (is_array($cached)) {
                    return $cached;
                }

                $data = $this->queryAvailability();

                if ($this->currentGeneration() === $generation) {
                    Cache::put($cacheKey, $data, self::AVAILABILITY_TTL_SECONDS);
                }

                return $data;
            });
        } catch (LockTimeoutException) {
            return $this->queryAvailability();
        }
    }

    public function createHold(int $slotId, string $idempotencyKey): Hold
    {
        try {
            $hold = DB::transaction(function () use ($slotId, $idempotencyKey): Hold {
                $existing = $this->findHoldByIdempotencyKey($idempotencyKey);

                if ($existing !== null) {
                    $this->assertIdempotencyMatchesSlot($existing, $slotId);

                    return $existing;
                }

                $slot = $this->lockSlot($slotId);

                $existing = $this->findHoldByIdempotencyKey($idempotencyKey, forUpdate: true);

                if ($existing !== null) {
                    $this->assertIdempotencyMatchesSlot($existing, $slotId);

                    return $existing;
                }

                $activeHolds = Hold::query()
                    ->select('id')
                    ->where('slot_id', $slot->id)
                    ->where('status', HoldStatus::Held)
                    ->where('expires_at', '>', now())
                    ->lockForUpdate()
                    ->get()
                    ->count();

                if ($slot->remaining - $activeHolds <= 0) {
                    throw new SlotUnavailableException('Slot is fully booked.');
                }

                return Hold::query()->create([
                    'slot_id' => $slot->id,
                    'idempotency_key' => $idempotencyKey,
                    'status' => HoldStatus::Held,
                    'expires_at' => now()->addMinutes(5),
                ]);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $existing = Hold::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
            $this->assertIdempotencyMatchesSlot($existing, $slotId);

            return $existing;
        }

        if ($hold->wasRecentlyCreated) {
            $this->invalidateAvailabilityCache();
        }

        return $hold;
    }

    public function confirmHold(int $holdId): Hold
    {
        $availabilityChanged = false;

        $hold = DB::transaction(function () use ($holdId, &$availabilityChanged): Hold {
            $hold = Hold::query()->findOrFail($holdId);

            $this->lockSlot($hold->slot_id);

            $hold = Hold::query()->whereKey($holdId)->lockForUpdate()->firstOrFail();

            if ($hold->status === HoldStatus::Confirmed) {
                return $hold;
            }

            if ($hold->status === HoldStatus::Cancelled) {
                throw new InvalidHoldStateException('Hold cannot be confirmed.');
            }

            if ($hold->isExpiredHeld()) {
                $hold->status = HoldStatus::Cancelled;
                $hold->save();

                return $hold;
            }

            $affected = Slot::query()
                ->whereKey($hold->slot_id)
                ->where('remaining', '>', 0)
                ->decrement('remaining');

            if ($affected === 0) {
                throw new SlotUnavailableException('Slot is fully booked.');
            }

            $hold->status = HoldStatus::Confirmed;
            $hold->save();
            $availabilityChanged = true;

            return $hold;
        }, 3);

        if ($hold->status === HoldStatus::Cancelled) {
            throw new HoldExpiredException('Hold has expired.');
        }

        if ($availabilityChanged) {
            $this->invalidateAvailabilityCache();
        }

        return $hold;
    }

    public function cancelHold(int $holdId): Hold
    {
        $availabilityChanged = false;

        $hold = DB::transaction(function () use ($holdId, &$availabilityChanged): Hold {
            $hold = Hold::query()->findOrFail($holdId);

            $this->lockSlot($hold->slot_id);

            $hold = Hold::query()->whereKey($holdId)->lockForUpdate()->firstOrFail();

            if ($hold->status === HoldStatus::Cancelled) {
                return $hold;
            }

            if ($hold->status === HoldStatus::Confirmed) {
                Slot::query()
                    ->whereKey($hold->slot_id)
                    ->whereColumn('remaining', '<', 'capacity')
                    ->increment('remaining');
            }

            $hold->status = HoldStatus::Cancelled;
            $hold->save();
            $availabilityChanged = true;

            return $hold;
        }, 3);

        if ($availabilityChanged) {
            $this->invalidateAvailabilityCache();
        }

        return $hold;
    }

    public function invalidateAvailabilityCache(): void
    {
        Cache::add(self::AVAILABILITY_GENERATION_KEY, 1);
        Cache::increment(self::AVAILABILITY_GENERATION_KEY);
    }

    public function currentGeneration(): int
    {
        Cache::add(self::AVAILABILITY_GENERATION_KEY, 1);

        return (int) Cache::get(self::AVAILABILITY_GENERATION_KEY, 1);
    }

    public static function cacheKeyForGeneration(int $generation): string
    {
        return self::AVAILABILITY_CACHE_PREFIX.':'.$generation;
    }

    public static function lockKeyForGeneration(int $generation): string
    {
        return self::AVAILABILITY_LOCK_PREFIX.':'.$generation;
    }

    /**
     * @return list<array{slot_id: int, capacity: int, remaining: int}>
     */
    private function queryAvailability(): array
    {
        $activeHolds = Hold::query()
            ->select('slot_id')
            ->selectRaw('COUNT(*) as active_count')
            ->where('status', HoldStatus::Held)
            ->where('expires_at', '>', now())
            ->groupBy('slot_id');

        return Slot::query()
            ->leftJoinSub($activeHolds, 'active_holds', 'active_holds.slot_id', '=', 'slots.id')
            ->orderBy('slots.id')
            ->get([
                'slots.id as slot_id',
                'slots.capacity',
                DB::raw('CASE WHEN slots.remaining - COALESCE(active_holds.active_count, 0) < 0 THEN 0 ELSE slots.remaining - COALESCE(active_holds.active_count, 0) END as remaining'),
            ])
            ->map(static fn (Slot $slot): array => [
                'slot_id' => (int) $slot->slot_id,
                'capacity' => (int) $slot->capacity,
                'remaining' => (int) $slot->remaining,
            ])
            ->values()
            ->all();
    }

    private function lockSlot(int $slotId): Slot
    {
        return Slot::query()->whereKey($slotId)->lockForUpdate()->firstOrFail();
    }

    private function findHoldByIdempotencyKey(string $idempotencyKey, bool $forUpdate = false): ?Hold
    {
        $query = Hold::query()->where('idempotency_key', $idempotencyKey);

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function assertIdempotencyMatchesSlot(Hold $hold, int $slotId): void
    {
        if ($hold->slot_id !== $slotId) {
            throw new InvalidHoldStateException('Idempotency-Key already used for a different slot.');
        }
    }
}
