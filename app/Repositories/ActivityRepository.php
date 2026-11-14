<?php

namespace App\Repositories;

use App\Models\Activity;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use App\Services\Membership\MembershipAccessService;
use Illuminate\Support\Facades\DB;

class ActivityRepository implements ActivityRepositoryInterface
{
    public function filter(array $filters, $user)
    {
        $query = Activity::query()->with('hobby');


        if ($user && $user->profile && $user->profile->birth_year) {

            $age = Carbon::now()->year - $user->profile->birth_year;

            $query->where(function ($q) use ($age) {
                $q->where('min_age', '<=', $age)
                    ->where(function ($q2) use ($age) {
                        $q2->where('max_age', '>=', $age)
                            ->orWhereNull('max_age');
                    });
            });
        }

        $package = $user ? app(MembershipAccessService::class)->snapshot($user)['effective_package'] : null;
        $subscriptionTierId = $package === 'premium' ? 3 : ($package === 'standard' ? 2 : 1);

        $allowedTiers = [1];
        if ($subscriptionTierId === 2) {
            $allowedTiers = [1, 2];
        } elseif ($subscriptionTierId >= 3) {
            $allowedTiers = [1, 2, 3];
        }
        $this->scopeMembershipCategories($query, $user, $package);

        if (!empty($filters['mood_match'])) {

            $moods = is_array($filters['mood_match'])
                ? $filters['mood_match']
                : [$filters['mood_match']];

            $query->where(function ($q) use ($moods) {
                foreach ($moods as $mood) {
                    $q->orWhereJsonContains('mood_match', $mood);
                }
            });
        }

        $allowedSocialTypes = ['Solo', 'Optional Group'];

        if (!empty($filters['social_type'])) {
            $requestedSocialTypes = array_values(array_filter(array_map(function ($type) {
                return is_string($type) ? trim($type) : null;
            }, (array) $filters['social_type'])));

            $socialTypeFilter = array_values(array_intersect($allowedSocialTypes, $requestedSocialTypes));

            if (empty($socialTypeFilter)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('social_type', $socialTypeFilter);
            }
        } else {
            $query->whereIn('social_type', $allowedSocialTypes);
        }

        $categoryNames = [];

        if ($user) {
            $user->loadMissing('hobbies:id,name');
            $categoryNames = $user->hobbies
                ->pluck('name')
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        if (!empty($filters['hobby_names'])) {
            $hobbyNames = array_values(array_filter(array_map(function ($name) {
                return is_string($name) ? trim($name) : null;
            }, (array) $filters['hobby_names'])));

            if (!empty($hobbyNames)) {
                $query->whereHas('hobby', function ($hobbyQuery) use ($hobbyNames) {
                    $hobbyQuery->whereIn('name', $hobbyNames);
                });
            }
        } elseif (!empty($filters['hobby_ids'])) {
            $query->whereIn('hobby_id', array_map('intval', (array) $filters['hobby_ids']));
        } elseif (!empty($categoryNames)) {
            $query->whereHas('hobby', function ($hobbyQuery) use ($categoryNames) {
                $hobbyQuery->whereIn('name', $categoryNames);
            });
        }

        if (!empty($filters['tier'])) {
            $requestedTiers = array_map('intval', (array) $filters['tier']);
            $tierFilter = array_values(array_intersect($allowedTiers, $requestedTiers));

            if (empty($tierFilter)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('tier', $tierFilter);
            }
        } else {
            $query->whereIn('tier', $allowedTiers);
        }

        $activities = $query->paginate(20)->getCollection();

        return $this->shuffleByHobby($activities);
    }

    public function all()
    {
        return Activity::with('hobby')->get();
    }

    public function groupActivities($user = null, int $perPage = 20)
    {
        $query = Activity::query()
            ->with('hobby')
            ->whereIn('social_type', ['Optional Group', 'Group 2-10']);

        $package = $user ? app(MembershipAccessService::class)->snapshot($user)['effective_package'] : null;
        $query->whereIn('tier', $package === 'premium' ? [1, 2, 3] : ($package === 'standard' ? [1, 2] : [1]));
        $this->scopeMembershipCategories($query, $user, $package);

        $activities = $query->paginate($perPage)->getCollection();

        return $activities;
    }

    public function userTierActivities($user, int $perPage = 20)
    {
        $query = Activity::query()->with('hobby');

        $package = $user ? app(MembershipAccessService::class)->snapshot($user)['effective_package'] : null;
        $subscriptionTierId = $package === 'premium' ? 3 : ($package === 'standard' ? 2 : 1);

        $allowedTiers = [1];
        if ($subscriptionTierId === 2) {
            $allowedTiers = [1, 2];
        } elseif ($subscriptionTierId >= 3) {
            $allowedTiers = [1, 2, 3];
        }

        $query->whereIn('tier', $allowedTiers);
        $this->scopeMembershipCategories($query, $user, $package);

        $activities = $query->paginate($perPage)->getCollection();

        return $activities;
    }

    public function getActivitiesPerCategoryAndTier($user, bool $excludeMicroMovement = true, array $filters = [], int $perPage = 50): LengthAwarePaginator 
    {
        $user->loadMissing('hobbies');
        $package = app(MembershipAccessService::class)->snapshot($user)['effective_package'];
        $subscriptionTierId = $package === 'premium' ? 3 : ($package === 'standard' ? 2 : 1);

        $maxTier = min(max($subscriptionTierId, 1), 3);
        $allowedTiers = range(1, $maxTier);

        $access = app(MembershipAccessService::class)->snapshot($user);
        $count = $access['limits']['category_count'];
        $selectedIds = $count === null || count($access['selected_category_ids']) === $count ? $access['selected_category_ids'] : [];
        $hobbies = $package !== null && $count === null
            ? \App\Models\Hobby::query()->get()
            : ($package !== null
                ? \App\Models\Hobby::query()->whereIn('id', $selectedIds)->get()
                : ($user->hobbies->isEmpty() ? \App\Models\Hobby::query()->get() : $user->hobbies));
        if ($excludeMicroMovement) {
            $hobbies = $hobbies->filter(function ($hobby) {
                return strtolower(trim($hobby->name)) !== 'micro-movement';
            });
        }

        $hobbyIds = $hobbies->pluck('id')->toArray();

        if (!empty($filters['category'])) {
            $categoryName = strtolower(trim($filters['category']));
            
            $selectedHobby = $hobbies->first(function ($hobby) use ($categoryName) {
                return strtolower(trim($hobby->name)) === $categoryName;
            });

            if ($selectedHobby) {
                $hobbyIds = [$selectedHobby->id];
            } else {
                return Activity::whereRaw('1 = 0')->paginate($perPage);
            }
        }

        if (empty($hobbyIds)) {
            return Activity::whereRaw('1 = 0')->paginate($perPage);
        }

        $query = Activity::with(['hobby:id,name'])
            ->whereIn('tier', $allowedTiers)
            ->whereIn('hobby_id', $hobbyIds);

        if (isset($filters['duration_minutes']) && is_numeric($filters['duration_minutes'])) {
            $maxDuration = (float) $filters['duration_minutes'];
            $query->whereRaw('CAST(duration_minutes AS NUMERIC) <= ?', [$maxDuration]);
        }

        if (!empty($filters['mood_match'])) {
            $moods = (array) $filters['mood_match'];
            $query->where(function ($q) use ($moods) {
                foreach ($moods as $mood) {
                    $q->orWhereJsonContains('mood_match', $mood);
                }
            });
        }

        $paginated = $query->inRandomOrder($user->id)->paginate($perPage);

        $paginated->through(function ($activity) {
            $activity->category = $activity->hobby->name ?? null;
            return $activity;
        });

        return $paginated;
    }

    public function create(array $data): Activity
    {
        return Activity::create($data);
    }

    public function update(Activity $activity, array $data): Activity
    {
        $activity->update($data);
        return $activity->fresh();
    }

    public function delete(Activity $activity): void
    {
        $activity->delete();
    }

    private function shuffleByHobby(Collection $activities): Collection
    {
        $grouped = $activities
            ->groupBy(fn(Activity $activity) => $activity->hobby_id ?? 0)
            ->map(fn(Collection $group) => $group->shuffle()->values());

        $hobbyKeys = $grouped->keys()->shuffle()->values();
        $shuffled = collect();

        while ($hobbyKeys->isNotEmpty()) {
            foreach ($hobbyKeys as $hobbyKey) {
                /** @var \Illuminate\Support\Collection $bucket */
                $bucket = $grouped->get($hobbyKey, collect());

                if ($bucket->isEmpty()) {
                    continue;
                }

                $shuffled->push($bucket->shift());
                $grouped->put($hobbyKey, $bucket);
            }

            $hobbyKeys = $hobbyKeys
                ->filter(fn($hobbyKey) => $grouped->get($hobbyKey)?->isNotEmpty())
                ->shuffle()
                ->values();
        }

        return $shuffled->values();
    }

    public function searchActivities(array $filters, $user = null)
    {
        $query = Activity::query()->with('hobby:id,name');

        if (!empty($filters['activity_title'])) {
            $search = trim($filters['activity_title']);
            $operator = config('database.default') === 'pgsql' ? 'ilike' : 'like';

            $query->where('activity_title', $operator, "%{$search}%");
        }

        $package = $user ? app(MembershipAccessService::class)->snapshot($user)['effective_package'] : null;
        $subscriptionTierId = $package === 'premium' ? 3 : ($package === 'standard' ? 2 : 1);

        $maxTier = min(max($subscriptionTierId, 1), 3);
        $allowedTiers = range(1, $maxTier);

        if (!empty($filters['tier'])) {
            $requestedTier = (int) $filters['tier'];
            if (in_array($requestedTier, $allowedTiers, true)) {
                $query->where('tier', (string) $requestedTier);
            } else {
                $query->whereRaw('1 = 0');
            }
        } else {
            $query->whereIn('tier', array_map('strval', $allowedTiers));
        }

        $this->scopeMembershipCategories($query, $user, $package);

        if (!empty($filters['hobby_ids'])) {
            $query->whereIn('hobby_id', array_map('intval', (array) $filters['hobby_ids']));
        }

        if (!empty($filters['social_type'])) {
            $query->where('social_type', trim($filters['social_type']));
        }

        if (!empty($filters['mood_match'])) {
            $moods = is_array($filters['mood_match']) ? $filters['mood_match'] : [$filters['mood_match']];
            $query->where(function ($q) use ($moods) {
                foreach ($moods as $mood) {
                    $q->orWhereJsonContains('mood_match', $mood);
                }
            });
        }

        $perPage = !empty($filters['per_page']) ? (int) $filters['per_page'] : 50;

        return $query->paginate($perPage);
    }

    private function scopeMembershipCategories(Builder $query, $user, ?string $package): void
    {
        if (! $user || $package === null) return;
        $access = app(MembershipAccessService::class)->snapshot($user);
        $count = $access['limits']['category_count'];
        if ($count === null) return;
        $ids = count($access['selected_category_ids']) === $count ? $access['selected_category_ids'] : [];
        $query->whereIn('hobby_id', $ids ?: [-1]);
    }
}
