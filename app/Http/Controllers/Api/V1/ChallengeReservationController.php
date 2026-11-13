<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ChallengeReservationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChallengeReservationController extends Controller
{
    public function __construct(private readonly ChallengeReservationService $reservations) {}

    public function index(Request $request)
    {
        return response()->json(['data' => $this->reservations->outstanding($request->user())]);
    }

    public function reserve(Request $request)
    {
        $data = $request->validate([
            'challenge_id' => 'required|integer|exists:activities,id',
            'reservation_id' => 'required|uuid',
            'allocation' => 'required|in:included,pack',
            'pack_transaction_id' => 'required_if:allocation,pack|prohibited_unless:allocation,pack|nullable|string|max:255',
        ]);
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! Str::isUuid($key)) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'A valid UUID idempotency key is required.']);
        }

        $response = $this->reservations->reserve($request->user(), $data, $key);
        $status = $response['status'] === 'reserved' ? 201 : 200;

        return response()->json($response, $status);
    }

    public function resume(Request $request, string $reservationId)
    {
        $data = $request->validate([
            'challenge_id' => 'required|integer|exists:activities,id',
        ]);

        return response()->json($this->reservations->resume($request->user(), $reservationId, (int) $data['challenge_id']));
    }

    public function complete(Request $request, string $reservationId)
    {
        $data = $request->validate(['completion_id' => 'required|uuid']);

        return response()->json($this->reservations->complete($request->user(), $reservationId, $data['completion_id']));
    }

    public function abandon(Request $request, string $reservationId)
    {
        $request->validate(['reason' => 'nullable|string|max:100']);

        return response()->json($this->reservations->abandon($request->user(), $reservationId));
    }
}
