<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProfileRequest;
use App\Http\Requests\UpdateActivitiesRequest;
use App\Http\Resources\UserResource;
use App\Http\Resources\UserProfileResource;
use App\Models\Country;
use App\Models\Hobby;
use App\Models\UserProfile;
use App\Models\Tier;
use App\Services\Membership\MembershipAccessService;
use App\Services\ProfileService;
use App\Services\UserHobbyService;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;


class ProfileController extends Controller
{
    public function __construct(
        protected ProfileService $profileService,
        protected UserHobbyService $userHobbyService
    ) {}

    public function store(ProfileRequest $request)
    {
        $validated = $request->validated();

        if (! empty($validated['user_id'])) {
            $existingProfile = UserProfile::query()->where('user_id', $validated['user_id'])->first();
            if ($existingProfile) {
                return $this->update($request, $existingProfile);
            }
        }

        $profile = $this->profileService->store($validated);

        if (! empty($validated['hobby_ids'])) {
            $profile->loadMissing('user');
            if ($profile->user) {
                $this->userHobbyService->selectHobbies(
                    $profile->user,
                    $validated['hobby_ids']
                );
            }
        }

        $profile->load('hobbies');

        return (new UserProfileResource($profile))
            ->response()
            ->setStatusCode(201);
    }

    public function index()
    {
        $profiles = $this->profileService->list();

        return UserProfileResource::collection($profiles);
    }

    public function countries()
    {
        $options = Country::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['name'])
            ->map(fn($row) => [
                'value' => $row->name,
                'label' => $row->name,
            ])
            ->values();

        return response()->json([
            'countries' => $options,
        ]);
    }

    public function show(UserProfile $profile)
    {
        return new UserProfileResource($profile);
    }

    public function me(Request $request)
    {
        $user = $request->user()->loadMissing('subscription.tier');
        $membership = app(MembershipAccessService::class)->snapshot($user);
        $tierName = match ($membership['effective_package']) {
            'standard' => '2',
            'premium' => '3',
            default => '1',
        };
        $userTier = [
            'id' => (int) (Tier::query()->where('name', $tierName)->value('id') ?? $tierName),
            'name' => $tierName,
        ];

        try {
            $profile = $this->profileService->getByUserId($user->id);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'user' => new UserResource($user),
                'user_tier' => $userTier,
            ]);
        }

        return response()->json([
            'user' => new UserResource($user),
            'user_tier' => $userTier,
            'profile' => new UserProfileResource($profile),
        ]);
    }

    public function update(ProfileRequest $request, UserProfile $profile)
    {
        $validated = $request->validated();
        $hasHobbySelection = array_key_exists('hobby_ids', $validated);
        $hobbyIds = $validated['hobby_ids'] ?? [];

        $userData = [];
        if (array_key_exists('name', $validated)) {
            $userData['name'] = $validated['name'];
        }
        if (array_key_exists('email', $validated)) {
            $userData['email'] = $validated['email'];
        }

        if (! empty($userData)) {
            $profile->user()->update($userData);
        }

        unset(
            $validated['name'],
            $validated['user_name'],
            $validated['email'],
            $validated['country_name'],
            $validated['hobby_ids']
        );

        if (! empty($validated)) {
            $this->profileService->update(
                $profile,
                $validated
            );
        }

        if ($hasHobbySelection) {
            $profile->loadMissing('user');
            $user = $profile->user;

            if (! $user) {
                return response()->json([
                    'message' => 'User not found for this profile',
                ], 404);
            }

            $this->userHobbyService->selectHobbies(
                $user,
                $hobbyIds
            );
        }

        return response()->json([
            'message' => "data updated successfully"
        ]);
    }

    public function updateActivities(UpdateActivitiesRequest $request)
    {
        $validated = $request->validated();
        $profile = $this->profileService->getByUserId((int) $validated['user_id']);
        $profile->loadMissing('user');
        $user = $profile->user;

        if (! $user) {
            return response()->json([
                'message' => 'User not found for this profile',
            ], 404);
        }

        $hobbyIds = $validated['category_ids'] ?? Hobby::query()
            ->whereIn('name', $validated['activities'])
            ->pluck('id')
            ->all();

        $this->userHobbyService->selectHobbies($user, $hobbyIds);

        return response()->json([
            'message' => 'Activities updated successfully',
        ]);
    }

    public function destroy(UserProfile $profile)
    {
        $this->profileService->delete($profile);

        return response()->json([
            'message' => 'Profile deleted successfully'
        ]);
    }
}
