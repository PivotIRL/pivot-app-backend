<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\ChallengePackService;

class ChallengePackController extends Controller
{
    private ChallengePackService $challengePackService;

    public function __construct(ChallengePackService $challengePackService)
    {
        $this->challengePackService = $challengePackService;
    }

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id; 

        $request->validate([
            'transaction_id' => 'nullable|string',
        ]);

        $transactionId = $request->input('transaction_id');

        $result = $this->challengePackService->index($userId, $transactionId);

        if ($request->filled('transaction_id')) {
            if (!$result) {
                return response()->json([
                    'message' => 'Challenge pack not found or already used for the given criteria.'
                ], 404);
            }

            return response()->json([
                'message' => 'Challenge pack details retrieved successfully.',
                'data'    => $result
            ], 200);
        }

        return response()->json([
            'message' => 'Unused challenge packs retrieved successfully.',
            'data'    => $result
        ], 200);
    }

    public function decrementRemaining(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'sometimes|integer',
            'transaction_id' => 'required|string',
            'usage_count' => 'required|integer|min:1'
        ]);

        $userId = (int) $request->user()->id;
        if (isset($data['user_id']) && (int) $data['user_id'] !== $userId) {
            return response()->json(['message' => 'The requested user does not match the authenticated account.'], 403);
        }

        $details = $this->challengePackService->update(
            $userId,
            $data['transaction_id'],
            (int) $data['usage_count']
        );

        if (!$details) {
            return response()->json([
                'message' => 'Challenge pack not found or remaining is already zero.'
            ], 404);
        }

        return response()->json([
            'message' => 'Challenge pack remaining decremented successfully.',
            'data' => $details
        ], 200);
    }
}


