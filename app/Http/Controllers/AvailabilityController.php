<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SlotService;
use Illuminate\Http\JsonResponse;

class AvailabilityController extends Controller
{
    public function __construct(private readonly SlotService $slotService) {}

    public function index(): JsonResponse
    {
        return response()->json($this->slotService->getAvailability());
    }
}
