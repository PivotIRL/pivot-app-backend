<?php

namespace App\Services;

use App\Models\Activity;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use App\Support\InstructionFormatter;

class ActivityService
{
    public function __construct(
        protected ActivityRepositoryInterface $repository
    ) {}

    public function list(array $filters = [], $user = null)
    {
        return $this->repository->filter($filters, $user);
    }

    public function store(array $data): Activity
    {
        if (array_key_exists('instruction', $data)) {
            $data['instruction'] = InstructionFormatter::normalize($data['instruction']);
        }

        return $this->repository->create($data);
    }

    public function update(Activity $activity, array $data): Activity
    {
        if (array_key_exists('instruction', $data)) {
            $data['instruction'] = InstructionFormatter::normalize($data['instruction']);
        }

        return $this->repository->update($activity, $data);
    }

    public function delete(Activity $activity): void
    {
        $this->repository->delete($activity);
    }

    public function groupActivities($user = null)
    {
        return $this->repository->groupActivities($user);
    }

    public function userTierActivities($user)
    {
        return $this->repository->userTierActivities($user);
    }

    public function getActivitiesPerCategoryAndTier($user, bool $excludeMicroMovement = true, array $filters = [])
    {
        return $this->repository->getActivitiesPerCategoryAndTier($user, $excludeMicroMovement, $filters);
    }

    public function searchActivities(array $filters, $user = null)
    {
        return $this->repository->searchActivities($filters, $user);
    }
}
