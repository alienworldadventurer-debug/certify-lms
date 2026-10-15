<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\EnrollmentNote\DestroyRequest;
use App\Http\Requests\EnrollmentNote\StoreRequest;
use App\Http\Requests\EnrollmentNote\UpdateRequest;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\UseCases\EnrollmentNote\DestroyAction;
use App\UseCases\EnrollmentNote\StoreAction;
use App\UseCases\EnrollmentNote\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class EnrollmentNoteController extends Controller
{
    public function store(
        Enrollment $enrollment,
        StoreRequest $request,
        StoreAction $action,
    ): RedirectResponse {
        $action($enrollment, $request->validated(), $request->user()->id);

        return redirect()
            ->route('enrollments.show', $enrollment)
            ->with('success', 'メモを追加しました。');
    }

    public function edit(EnrollmentNote $note): View
    {
        Gate::authorize('update', $note);

        return view('enrollment-note.edit', [
            'note' => $note,
        ]);
    }

    public function update(
        EnrollmentNote $note,
        UpdateRequest $request,
        UpdateAction $action,
    ): RedirectResponse {
        $action($note, $request->validated());

        return redirect()
            ->route('enrollments.show', $note->enrollment_id)
            ->with('success', 'メモを更新しました。');
    }

    public function destroy(
        EnrollmentNote $note,
        DestroyRequest $request,
        DestroyAction $action,
    ): RedirectResponse {
        $enrollmentId = $note->enrollment_id;
        $action($note);

        return redirect()
            ->route('enrollments.show', $enrollmentId)
            ->with('success', 'メモを削除しました。');
    }
}
