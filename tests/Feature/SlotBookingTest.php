<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\HoldStatus;
use App\Models\Hold;
use App\Models\Slot;
use App\Services\SlotService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class SlotBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_availability_endpoint_returns_slots(): void
    {
        $slot = $this->createSlot(10, 10);

        $this->getJson('/slots/availability')
            ->assertOk()
            ->assertJson([
                [
                    'slot_id' => $slot->id,
                    'capacity' => 10,
                    'remaining' => 10,
                ],
            ]);
    }

    public function test_hold_creation_returns_held(): void
    {
        $this->freezeTime();
        $slot = $this->createSlot(10, 10);
        $key = $this->uuid();

        $this->postJson("/slots/{$slot->id}/hold", [], $this->holdHeaders($key))
            ->assertCreated()
            ->assertJsonPath('slot_id', $slot->id)
            ->assertJsonPath('status', 'held')
            ->assertJsonPath('idempotency_key', $key)
            ->assertJsonPath('expires_at', now()->addMinutes(5)->toIso8601String());
    }

    public function test_hold_expires_in_five_minutes(): void
    {
        $this->freezeTime();
        $slot = $this->createSlot(1, 1);

        $response = $this->postJson("/slots/{$slot->id}/hold", [], $this->holdHeaders());

        $response->assertCreated()
            ->assertJsonPath('expires_at', now()->addMinutes(5)->toIso8601String());

        $this->travel(5)->minutes();
        Cache::forget(SlotService::cacheKeyForGeneration(app(SlotService::class)->currentGeneration()));

        $this->getJson('/slots/availability')
            ->assertOk()
            ->assertJsonPath('0.remaining', 1);

        $this->postJson("/holds/{$response->json('id')}/confirm")
            ->assertConflict()
            ->assertJson(['message' => 'Hold has expired.']);
    }

    public function test_same_idempotency_key_does_not_create_a_duplicate(): void
    {
        $slot = $this->createSlot(10, 10);
        $key = $this->uuid();

        $first = $this->postJson("/slots/{$slot->id}/hold", [], $this->holdHeaders($key))
            ->assertCreated();

        $second = $this->postJson("/slots/{$slot->id}/hold", [], $this->holdHeaders($key))
            ->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, Hold::query()->count());
    }

    public function test_db_unique_constraint_protects_idempotency(): void
    {
        $slot = $this->createSlot(10, 10);
        $key = $this->uuid();

        Hold::query()->create([
            'slot_id' => $slot->id,
            'idempotency_key' => $key,
            'status' => HoldStatus::Held,
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        Hold::query()->create([
            'slot_id' => $slot->id,
            'idempotency_key' => $key,
            'status' => HoldStatus::Held,
            'expires_at' => now()->addMinutes(5),
        ]);
    }

    public function test_hold_creation_respects_effective_availability(): void
    {
        $slot = $this->createSlot(1, 1);

        $this->postJson("/slots/{$slot->id}/hold", [], $this->holdHeaders())
            ->assertCreated();

        $this->postJson("/slots/{$slot->id}/hold", [], $this->holdHeaders())
            ->assertConflict()
            ->assertJson(['message' => 'Slot is fully booked.']);

        $slot->refresh();
        $this->assertSame(1, $slot->remaining);
        $this->assertSame(1, Hold::query()->count());
    }

    public function test_held_reservations_affect_availability(): void
    {
        $slot = $this->createSlot(10, 10);

        $this->postJson("/slots/{$slot->id}/hold", [], $this->holdHeaders())
            ->assertCreated();

        $this->getJson('/slots/availability')
            ->assertOk()
            ->assertJsonPath('0.remaining', 9);

        $slot->refresh();
        $this->assertSame(10, $slot->remaining);
    }

    public function test_expired_held_reservations_do_not_affect_availability(): void
    {
        $this->freezeTime();
        $slot = $this->createSlot(5, 5);

        $this->postJson("/slots/{$slot->id}/hold", [], $this->holdHeaders())
            ->assertCreated();

        $this->travel(5)->minutes();
        Cache::forget(SlotService::cacheKeyForGeneration(app(SlotService::class)->currentGeneration()));

        $this->getJson('/slots/availability')
            ->assertOk()
            ->assertJsonPath('0.remaining', 5);

        $slot->refresh();
        $this->assertSame(5, $slot->remaining);
    }

    public function test_confirmation_changes_status_to_confirmed(): void
    {
        $slot = $this->createSlot(10, 10);

        $holdId = $this->postJson("/slots/{$slot->id}/hold", [], $this->holdHeaders())
            ->assertCreated()
            ->json('id');

        $this->postJson("/holds/{$holdId}/confirm")
            ->assertOk()
            ->assertJsonPath('status', 'confirmed');
    }

    public function test_confirmation_decrements_remaining_exactly_once(): void
    {
        $slot = $this->createSlot(10, 10);

        $holdId = $this->postJson("/slots/{$slot->id}/hold", [], $this->holdHeaders())
            ->assertCreated()
            ->json('id');

        $this->postJson("/holds/{$holdId}/confirm")->assertOk();

        $slot->refresh();
        $this->assertSame(9, $slot->remaining);
    }

    public function test_repeated_confirmation_does_not_decrement_again(): void
    {
        $slot = $this->createSlot(10, 10);

        $holdId = $this->postJson("/slots/{$slot->id}/hold", [], $this->holdHeaders())
            ->assertCreated()
            ->json('id');

        $this->postJson("/holds/{$holdId}/confirm")->assertOk();
        $this->postJson("/holds/{$holdId}/confirm")->assertOk();

        $slot->refresh();
        $this->assertSame(9, $slot->remaining);
        $this->assertSame(1, Hold::query()->where('status', HoldStatus::Confirmed)->count());
    }

    public function test_confirming_cancelled_hold_returns_409(): void
    {
        $slot = $this->createSlot(10, 10);

        $holdId = $this->postJson("/slots/{$slot->id}/hold", [], $this->holdHeaders())
            ->assertCreated()
            ->json('id');

        $this->deleteJson("/holds/{$holdId}")->assertOk();

        $this->postJson("/holds/{$holdId}/confirm")
            ->assertConflict()
            ->assertJson(['message' => 'Hold cannot be confirmed.']);
    }

    public function test_confirming_expired_hold_returns_409(): void
    {
        $this->freezeTime();
        $slot = $this->createSlot(10, 10);

        $holdId = $this->postJson("/slots/{$slot->id}/hold", [], $this->holdHeaders())
            ->assertCreated()
            ->json('id');

        $this->travel(5)->minutes();

        $this->postJson("/holds/{$holdId}/confirm")
            ->assertConflict()
            ->assertJson(['message' => 'Hold has expired.']);

        $this->assertSame(HoldStatus::Cancelled, Hold::query()->findOrFail($holdId)->status);
        $slot->refresh();
        $this->assertSame(10, $slot->remaining);
    }

    public function test_cancelling_held_hold_releases_reservation_without_incrementing_remaining(): void
    {
        $slot = $this->createSlot(10, 10);

        $holdId = $this->postJson("/slots/{$slot->id}/hold", [], $this->holdHeaders())
            ->assertCreated()
            ->json('id');

        $this->getJson('/slots/availability')->assertJsonPath('0.remaining', 9);

        $this->deleteJson("/holds/{$holdId}")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $slot->refresh();
        $this->assertSame(10, $slot->remaining);

        $this->getJson('/slots/availability')->assertJsonPath('0.remaining', 10);
    }

    public function test_cancelling_confirmed_hold_increments_remaining(): void
    {
        $slot = $this->createSlot(10, 10);

        $holdId = $this->postJson("/slots/{$slot->id}/hold", [], $this->holdHeaders())
            ->assertCreated()
            ->json('id');

        $this->postJson("/holds/{$holdId}/confirm")->assertOk();
        $slot->refresh();
        $this->assertSame(9, $slot->remaining);

        $this->deleteJson("/holds/{$holdId}")->assertOk();

        $slot->refresh();
        $this->assertSame(10, $slot->remaining);
    }

    public function test_repeated_cancellation_does_not_increment_twice(): void
    {
        $slot = $this->createSlot(10, 10);

        $holdId = $this->postJson("/slots/{$slot->id}/hold", [], $this->holdHeaders())
            ->assertCreated()
            ->json('id');

        $this->postJson("/holds/{$holdId}/confirm")->assertOk();
        $this->deleteJson("/holds/{$holdId}")->assertOk();
        $this->deleteJson("/holds/{$holdId}")->assertOk();

        $slot->refresh();
        $this->assertSame(10, $slot->remaining);
    }

    public function test_cache_is_invalidated_after_hold_creation(): void
    {
        $slot = $this->createSlot(10, 10);
        $generation = app(SlotService::class)->currentGeneration();
        Cache::put(SlotService::cacheKeyForGeneration($generation), [
            ['slot_id' => $slot->id, 'capacity' => 10, 'remaining' => 99],
        ], 10);

        $this->postJson("/slots/{$slot->id}/hold", [], $this->holdHeaders())
            ->assertCreated();

        $this->getJson('/slots/availability')->assertJsonPath('0.remaining', 9);
    }

    public function test_cache_is_invalidated_after_confirmation(): void
    {
        $slot = $this->createSlot(10, 10);

        $holdId = $this->postJson("/slots/{$slot->id}/hold", [], $this->holdHeaders())
            ->assertCreated()
            ->json('id');

        $generation = app(SlotService::class)->currentGeneration();
        Cache::put(SlotService::cacheKeyForGeneration($generation), [
            ['slot_id' => $slot->id, 'capacity' => 10, 'remaining' => 99],
        ], 10);

        $this->postJson("/holds/{$holdId}/confirm")->assertOk();

        $this->getJson('/slots/availability')->assertJsonPath('0.remaining', 9);
    }

    public function test_cache_is_invalidated_after_cancellation(): void
    {
        $slot = $this->createSlot(10, 10);

        $holdId = $this->postJson("/slots/{$slot->id}/hold", [], $this->holdHeaders())
            ->assertCreated()
            ->json('id');

        $generation = app(SlotService::class)->currentGeneration();
        Cache::put(SlotService::cacheKeyForGeneration($generation), [
            ['slot_id' => $slot->id, 'capacity' => 10, 'remaining' => 99],
        ], 10);

        $this->deleteJson("/holds/{$holdId}")->assertOk();

        $this->getJson('/slots/availability')->assertJsonPath('0.remaining', 10);
    }

    public function test_stale_generation_rebuild_is_not_served_after_invalidation(): void
    {
        $slot = $this->createSlot(10, 10);
        $service = app(SlotService::class);
        $oldGeneration = $service->currentGeneration();

        $this->postJson("/slots/{$slot->id}/hold", [], $this->holdHeaders())
            ->assertCreated();

        Cache::put(SlotService::cacheKeyForGeneration($oldGeneration), [
            ['slot_id' => $slot->id, 'capacity' => 10, 'remaining' => 99],
        ], 10);

        $this->getJson('/slots/availability')
            ->assertOk()
            ->assertJsonPath('0.remaining', 9);
    }

    public function test_confirmation_cannot_oversell_remaining(): void
    {
        $slot = $this->createSlot(1, 1);

        $first = Hold::query()->create([
            'slot_id' => $slot->id,
            'idempotency_key' => $this->uuid(),
            'status' => HoldStatus::Held,
            'expires_at' => now()->addMinutes(5),
        ]);

        $second = Hold::query()->create([
            'slot_id' => $slot->id,
            'idempotency_key' => $this->uuid(),
            'status' => HoldStatus::Held,
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->postJson("/holds/{$first->id}/confirm")->assertOk();
        $this->postJson("/holds/{$second->id}/confirm")->assertConflict();

        $slot->refresh();
        $this->assertSame(0, $slot->remaining);
        $this->assertSame(HoldStatus::Confirmed, $first->fresh()->status);
        $this->assertSame(HoldStatus::Held, $second->fresh()->status);
    }

    public function test_missing_idempotency_key_returns_422(): void
    {
        $slot = $this->createSlot(10, 10);

        $this->postJson("/slots/{$slot->id}/hold")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['Idempotency-Key']);
    }

    public function test_invalid_idempotency_key_returns_422(): void
    {
        $slot = $this->createSlot(10, 10);

        $this->postJson("/slots/{$slot->id}/hold", [], ['Idempotency-Key' => 'not-a-uuid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['Idempotency-Key']);
    }

    public function test_unknown_slot_and_hold_return_404(): void
    {
        $this->postJson('/slots/999/hold', [], $this->holdHeaders())->assertNotFound();
        $this->postJson('/holds/999/confirm')->assertNotFound();
        $this->deleteJson('/holds/999')->assertNotFound();
    }

    public function test_non_numeric_slot_and_hold_ids_return_404(): void
    {
        $this->postJson('/slots/abc/hold', [], $this->holdHeaders())->assertNotFound();
        $this->postJson('/holds/xyz/confirm')->assertNotFound();
        $this->deleteJson('/holds/1e2')->assertNotFound();
    }

    public function test_reused_idempotency_key_for_different_slot_returns_409(): void
    {
        $firstSlot = $this->createSlot(10, 10);
        $secondSlot = $this->createSlot(5, 5);
        $key = $this->uuid();

        $this->postJson("/slots/{$firstSlot->id}/hold", [], $this->holdHeaders($key))
            ->assertCreated();

        $this->postJson("/slots/{$secondSlot->id}/hold", [], $this->holdHeaders($key))
            ->assertConflict()
            ->assertJson(['message' => 'Idempotency-Key already used for a different slot.']);
    }

    private function createSlot(int $capacity, int $remaining): Slot
    {
        return Slot::query()->create([
            'capacity' => $capacity,
            'remaining' => $remaining,
        ]);
    }

    /**
     * @return array{Idempotency-Key: string}
     */
    private function holdHeaders(?string $key = null): array
    {
        return ['Idempotency-Key' => $key ?? $this->uuid()];
    }

    private function uuid(): string
    {
        return (string) Str::uuid();
    }
}
