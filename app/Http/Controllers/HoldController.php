<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CreateHoldRequest;
use App\Services\SlotService;
use Illuminate\Http\JsonResponse;

class HoldController extends Controller
{
    public function __construct(private readonly SlotService $slotService) {}

    public function store(CreateHoldRequest $request, int $id): JsonResponse
    {
        $hold = $this->slotService->createHold($id, $request->idempotencyKey());

        return response()->json(
            $hold->toApiArray(),
            $hold->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function confirm(int $id): JsonResponse
    {
        $hold = $this->slotService->confirmHold($id);

        return response()->json($hold->toApiArray());
    }

    public function destroy(int $id): JsonResponse
    {
        $hold = $this->slotService->cancelHold($id);

        return response()->json($hold->toApiArray());
    }
}
