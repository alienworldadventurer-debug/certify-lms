<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MeetingPackStatus;
use App\Http\Requests\MeetingPack\IndexRequest;
use App\Http\Requests\MeetingPack\StoreRequest;
use App\Http\Requests\MeetingPack\UpdateRequest;
use App\Models\MeetingPack;
use App\UseCases\MeetingPack\ChangeStatusAction;
use App\UseCases\MeetingPack\DestroyAction;
use App\UseCases\MeetingPack\IndexAction;
use App\UseCases\MeetingPack\ShowAction;
use App\UseCases\MeetingPack\StoreAction;
use App\UseCases\MeetingPack\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MeetingPackController extends Controller
{
    public function index(IndexRequest $request, IndexAction $action): View|RedirectResponse
    {
        $keyword = $request->validated('keyword');
        $rawStatus = $request->query('status');
        $status = is_string($rawStatus) && $rawStatus !== ''
            ? MeetingPackStatus::tryFrom($rawStatus)
            : null;

        if ($rawStatus !== null && $rawStatus !== '' && (! is_string($rawStatus) || $status === null)) {
            return redirect()
                ->route('admin.meeting-packs.index', array_filter(
                    ['keyword' => $keyword],
                    fn ($value) => $value !== null && $value !== '',
                ))
                ->with('warning', '指定されたステータスは無効です。ステータス条件を適用せずに表示しています。');
        }

        return view('meeting-pack.management.index', [
            'plans' => $action($keyword, $status),
            'keyword' => $keyword ?? '',
            'status' => $status?->value ?? '',
        ]);
    }

    public function show(MeetingPack $plan, ShowAction $action): View
    {
        $this->authorize('view', $plan);

        return view('meeting-pack.management.show', [
            'plan' => $action($plan),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', MeetingPack::class);

        return view('meeting-pack.management.create');
    }

    public function store(StoreRequest $request, StoreAction $action): RedirectResponse
    {
        $plan = $action($request->user(), $request->validated());

        return redirect()
            ->route('admin.meeting-packs.show', $plan)
            ->with('success', '面談パックを作成しました。');
    }

    public function edit(MeetingPack $plan): View
    {
        $this->authorize('update', $plan);

        return view('meeting-pack.management.edit', [
            'plan' => $plan,
        ]);
    }

    public function update(MeetingPack $plan, UpdateRequest $request, UpdateAction $action): RedirectResponse
    {
        $action($plan, $request->user(), $request->validated());

        return redirect()
            ->route('admin.meeting-packs.show', $plan)
            ->with('success', '面談パックを更新しました。');
    }

    public function destroy(MeetingPack $plan, DestroyAction $action): RedirectResponse
    {
        $this->authorize('delete', $plan);

        if (! $action($plan)) {
            return redirect()
                ->route('admin.meeting-packs.show', $plan)
                ->with('error', '公開中の面談パックは削除できません。');
        }

        return redirect()
            ->route('admin.meeting-packs.index')
            ->with('success', '面談パックを削除しました。');
    }

    public function publish(MeetingPack $plan, ChangeStatusAction $action): RedirectResponse
    {
        $this->authorize('publish', $plan);

        return $this->changeStatus($plan, MeetingPackStatus::Published, $action);
    }

    public function archive(MeetingPack $plan, ChangeStatusAction $action): RedirectResponse
    {
        $this->authorize('archive', $plan);

        return $this->changeStatus($plan, MeetingPackStatus::Archived, $action);
    }

    public function unarchive(MeetingPack $plan, ChangeStatusAction $action): RedirectResponse
    {
        $this->authorize('unarchive', $plan);

        return $this->changeStatus($plan, MeetingPackStatus::Draft, $action);
    }

    private function changeStatus(
        MeetingPack $plan,
        MeetingPackStatus $targetStatus,
        ChangeStatusAction $action,
    ): RedirectResponse {
        $result = $action($plan, request()->user(), $targetStatus);

        return redirect()
            ->route('admin.meeting-packs.show', $plan)
            ->with($result['changed'] ? 'success' : 'error', $result['message']);
    }
}
