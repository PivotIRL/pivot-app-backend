<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Http\Requests\ActivityRequest;
use App\Http\Requests\ActivityFilterRequest;
use App\Http\Resources\ActivityResource;
use App\Services\ActivityService;
use App\Http\Requests\ActivitySearchRequest;
use Illuminate\Http\Request;
use App\Services\Membership\MembershipAccessService;

class ActivityController extends Controller
{
    public function __construct(
        protected ActivityService $activityService
    ) {}

    public function index(ActivityFilterRequest $request)
    {

        $user = $request->user();

        $activities = $this->activityService->list(
            $request->validated(),
            $user
        );

        return response()->json([
            ActivityResource::collection($activities)
        ]);
    }

    public function show(Request $request, Activity $activity, MembershipAccessService $membership)
    {
        $snapshot = $membership->snapshot($request->user());
        $maxTier = $snapshot['effective_package'] === 'premium' ? 3 : ($snapshot['effective_package'] === 'standard' ? 2 : 1);
        $allowedCategories = $snapshot['effective_package'] !== null && $snapshot['limits']['category_count'] !== null
            ? (count($snapshot['selected_category_ids']) === $snapshot['limits']['category_count'] ? $snapshot['selected_category_ids'] : [])
            : null;
        if ((int) $activity->tier > $maxTier || ($allowedCategories !== null && ! in_array((string) $activity->hobby_id, $allowedCategories, true))) {
            abort(404);
        }
        return response()->json([
            new ActivityResource($activity)
        ]);
    }

    public function store(ActivityRequest $request)
    {
        $activity = $this->activityService->store(
            $request->validated()
        );

        return response()->json([
            new ActivityResource($activity)
        ], 201);
    }

    public function update(ActivityRequest $request, Activity $activity)
    {
        $updatedActivity = $this->activityService->update(
            $activity,
            $request->validated()
        );

        return response()->json([
            new ActivityResource($updatedActivity)
        ]);
    }

    public function destroy(Activity $activity)
    {
        $this->activityService->delete($activity);

        return response()->json([
            'message' => 'Activity deleted successfully'
        ]);
    }

    public function groupActivities(Request $request)
    {
        $activities = $this->activityService->groupActivities($request->user());

        return ActivityResource::collection($activities);
    }

    public function userTierActivities(\Illuminate\Http\Request $request)
    {
        $user = $request->user();
        
        $activities = $this->activityService->userTierActivities($user);

        return response()->json([
            ActivityResource::collection($activities)
        ]);
    }

    public function userActivitiesFiltered(\Illuminate\Http\Request $request)
    {
        $user = $request->user();
        
        $activities = $this->activityService->getActivitiesPerCategoryAndTier($user, true, $request->all());

        return response()->json([
            ActivityResource::collection($activities)
        ]);
    }

    public function userActivitiesAll(\Illuminate\Http\Request $request)
    {
        $user = $request->user();
        
        $activities = $this->activityService->getActivitiesPerCategoryAndTier($user, false, $request->all());

        return response()->json([
            ActivityResource::collection($activities)
        ]);
    }

    public function searchActivities(ActivitySearchRequest $request)
    {
        $user = $request->user();
        $activities = $this->activityService->searchActivities(
            $request->validated(),
            $user
        );

        return response()->json([
            ActivityResource::collection($activities)
        ]);
    }
}
