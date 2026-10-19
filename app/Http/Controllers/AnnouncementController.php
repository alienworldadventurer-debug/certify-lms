<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Announcement\StoreRequest;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use App\UseCases\Announcement\StoreAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Throwable;

class AnnouncementController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Announcement::class);

        return view('announcement.management.index', [
            'announcements' => Announcement::query()
                ->with(['targetCertification', 'targetUser', 'createdBy'])
                ->orderByDesc('dispatched_at')
                ->orderByDesc('id')
                ->paginate(20),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Announcement::class);

        return view('announcement.management.create', [
            'certifications' => Certification::query()->orderBy('name')->get(),
            'students' => User::query()
                ->announcementRecipients()
                ->orderBy('name')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function store(StoreRequest $request, StoreAction $action): RedirectResponse
    {
        try {
            $action($request->user(), $request->validated());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.announcements.create')
                ->withInput()
                ->with('error', 'お知らせの配信に失敗しました。');
        }

        return redirect()
            ->route('admin.announcements.index')
            ->with('success', 'お知らせを配信しました。');
    }

    public function show(Announcement $announcement): View
    {
        Gate::authorize('view', $announcement);

        return view('announcement.management.show', [
            'announcement' => $announcement->load(['targetCertification', 'targetUser', 'createdBy']),
        ]);
    }
}
