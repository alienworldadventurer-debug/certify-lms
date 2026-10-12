<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class EnrollmentGoalPolicy
{
    public function create(User $user, Enrollment $enrollment): bool
    {
        if ($user->role === UserRole::Student && $user->status !== UserStatus::InProgress) {
            return false;
        }

        return $user->role === UserRole::Student
            && $enrollment->user_id === $user->id
            && $enrollment->status === EnrollmentStatus::Learning;
    }

    public function update(User $user, EnrollmentGoal $goal): bool|Response
    {
        return $this->manage($user, $goal);
    }

    public function delete(User $user, EnrollmentGoal $goal): bool|Response
    {
        return $this->manage($user, $goal);
    }

    public function markAchieved(User $user, EnrollmentGoal $goal): bool|Response
    {
        return $this->manage($user, $goal);
    }

    public function unmarkAchieved(User $user, EnrollmentGoal $goal): bool|Response
    {
        return $this->manage($user, $goal);
    }

    private function manage(User $user, EnrollmentGoal $goal): bool|Response
    {
        if ($user->role === UserRole::Student && $user->status !== UserStatus::InProgress) {
            return false;
        }

        $goal->loadMissing('enrollment');

        if ($goal->enrollment === null) {
            return Response::denyAsNotFound();
        }

        return $user->role === UserRole::Student
            && $goal->enrollment->user_id === $user->id
            && $goal->enrollment->status === EnrollmentStatus::Learning;
    }
}
