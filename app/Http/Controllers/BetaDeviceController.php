<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\DeviceFingerprint;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Support\Facades\Hash;

class BetaDeviceController extends Controller
{
    public function __construct(
        protected AuthService $authService
    ) {}

    public function checkDevice(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'device_id' => 'required',
            'device_name' => 'nullable',
            'platform' => 'nullable',
        ]);

        // Find user
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Check password
        if (!Hash::check($request->password, $user->password)) {

            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }

        // Check saved device for this user
        $device = DeviceFingerprint::where('user_id', $user->id)->first();

        // Check if this device ID is already linked to a different user
        $existingDevice = DeviceFingerprint::where('device_id', $request->device_id)->first();

        if ($existingDevice && $existingDevice->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'This device is already linked to another account'
            ], 409);
        }

        // First login
        if (!$device) {

            DeviceFingerprint::create([
                'user_id' => $user->id,
                'device_id' => $request->device_id,
                'device_name' => $request->device_name,
                'platform' => $request->platform,
                'ip_address' => $request->ip(),
            ]);
        }

        // Different device for same account
        if ($device && $device->device_id !== $request->device_id) {
            return response()->json([
                'success' => false,
                'message' => 'This account is already linked to another device'
            ], 403);
        }

        $result = $this->authService->login($request->only('email', 'password'));
        $result['user']->load('subscription.tier');

        return response()->json([
            'token' => $result['token'],
            'user'  => new UserResource($result['user']),
        ]);
    }

    public function deleteAll()
    {
        DeviceFingerprint::truncate();

        return response()->json([
            'success' => true,
            'message' => 'All devices have been deleted successfully.'
        ]);
    }

    public function deleteById($id)
    {
        $device = DeviceFingerprint::find($id);
        
        if (!$device) {
            return response()->json([
                'success' => false,
                'message' => 'Device not found'
            ], 404);
        }

        $device->delete();

        return response()->json([
            'success' => true,
            'message' => 'Device deleted successfully.'
        ]);
    }

    public function getAll()
    {
        $devices = DeviceFingerprint::all();

        return response()->json([
            'success' => true,
            'data' => $devices
        ]);
    }
}
