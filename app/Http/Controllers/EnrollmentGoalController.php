<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\EnrollmentGoal\DestroyRequest;
use App\Http\Requests\EnrollmentGoal\MarkAchievedRequest;
use App\Http\Requests\EnrollmentGoal\StoreRequest;
use App\Http\Requests\EnrollmentGoal\UnmarkAchievedRequest;
use App\Http\Requests\EnrollmentGoal\UpdateRequest;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\UseCases\EnrollmentGoal\DestroyAction;
use App\UseCases\EnrollmentGoal\EditAction;
use App\UseCases\EnrollmentGoal\MarkAchievedAction;
use App\UseCases\EnrollmentGoal\StoreAction;
use App\UseCases\EnrollmentGoal\UnmarkAchievedAction;
use App\UseCases\EnrollmentGoal\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class EnrollmentGoalController extends Controller
{
    public function store(
        Enrollment $enrollment,
        StoreRequest $request,
        StoreAction $action,
    ): RedirectResponse {
        $action($enrollment, $request->validated());

        return redirect()
            ->route('enrollments.show', $enrollment)
            ->with('success', '目標を追加しました。');
    }

    public function edit(EnrollmentGoal $goal, EditAction $action): View
    {
        Gate::authorize('update', $goal);

        return view('enrollment-goal.edit', [
            'goal' => $action($goal),
        ]);
    }

    public function update(
        EnrollmentGoal $goal,
        UpdateRequest $request,
        UpdateAction $action,
    ): RedirectResponse {
        $action($goal, $request->validated());

        return redirect()
            ->route('enrollments.show', $goal->enrollment_id)
            ->with('success', '目標を更新しました。');
    }

    public function destroy(
        EnrollmentGoal $goal,
        DestroyRequest $request,
        DestroyAction $action,
    ): RedirectResponse {
        $enrollmentId = $goal->enrollment_id;
        $action($goal);

        return redirect()
            ->route('enrollments.show', $enrollmentId)
            ->with('success', '目標を削除しました。');
    }

    public function markAchieved(
        EnrollmentGoal $goal,
        MarkAchievedRequest $request,
        MarkAchievedAction $action,
    ): RedirectResponse {
        $action($goal);

        return redirect()
            ->route('enrollments.show', $goal->enrollment_id)
            ->with('success', '目標を達成済みにしました。');
    }

    public function unmarkAchieved(
        EnrollmentGoal $goal,
        UnmarkAchievedRequest $request,
        UnmarkAchievedAction $action,
    ): RedirectResponse {
        $action($goal);

        return redirect()
            ->route('enrollments.show', $goal->enrollment_id)
            ->with('success', '目標を未達成に戻しました。');
    }
}
