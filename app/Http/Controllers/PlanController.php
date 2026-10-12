<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlanStatus;
use App\Http\Requests\Plan\IndexRequest;
use App\Http\Requests\Plan\StoreRequest;
use App\Http\Requests\Plan\UpdateRequest;
use App\Models\Plan;
use App\UseCases\Plan\ChangeStatusAction;
use App\UseCases\Plan\DestroyAction;
use App\UseCases\Plan\IndexAction;
use App\UseCases\Plan\ShowAction;
use App\UseCases\Plan\StoreAction;
use App\UseCases\Plan\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PlanController extends Controller
{
    public function index(IndexRequest $request, IndexAction $action): View|RedirectResponse
    {
        $keyword = $request->validated('keyword');
        $rawStatus = $request->query('status');
        $status = is_string($rawStatus) && $rawStatus !== ''
            ? PlanStatus::tryFrom($rawStatus)
            : null;

        if ($rawStatus !== null && $rawStatus !== '' && (! is_string($rawStatus) || $status === null)) {
            return redirect()
                ->route('admin.plans.index', array_filter(
                    ['keyword' => $keyword],
                    fn ($value) => $value !== null && $value !== '',
                ))
                ->with('warning', '指定されたステータスは無効です。ステータス条件を適用せずに表示しています。');
        }

        return view('plan.management.index', [
            'plans' => $action($keyword, $status),
            'keyword' => $keyword ?? '',
            'status' => $status?->value ?? '',
        ]);
    }

    public function show(Plan $plan, ShowAction $action): View
    {
        $this->authorize('view', $plan);

        return view('plan.management.show', [
            'plan' => $action($plan),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Plan::class);

        return view('plan.management.create');
    }

    public function store(StoreRequest $request, StoreAction $action): RedirectResponse
    {
        $plan = $action($request->user(), $request->validated());

        return redirect()
            ->route('admin.plans.show', $plan)
            ->with('success', 'プランを作成しました。');
    }

    public function edit(Plan $plan): View
    {
        $this->authorize('update', $plan);

        return view('plan.management.edit', [
            'plan' => $plan,
        ]);
    }

    public function update(Plan $plan, UpdateRequest $request, UpdateAction $action): RedirectResponse
    {
        $action($plan, $request->user(), $request->validated());

        return redirect()
            ->route('admin.plans.show', $plan)
            ->with('success', 'プランを更新しました。');
    }

    public function destroy(Plan $plan, DestroyAction $action): RedirectResponse
    {
        $this->authorize('delete', $plan);

        if (! $action($plan)) {
            return redirect()
                ->route('admin.plans.show', $plan)
                ->with('error', 'このプランは削除できません。下書きで、受講者およびプラン履歴から参照されていないことを確認してください。');
        }

        return redirect()
            ->route('admin.plans.index')
            ->with('success', 'プランを削除しました。');
    }

    public function publish(Plan $plan, ChangeStatusAction $action): RedirectResponse
    {
        $this->authorize('publish', $plan);

        return $this->changeStatus($plan, PlanStatus::Published, $action);
    }

    public function archive(Plan $plan, ChangeStatusAction $action): RedirectResponse
    {
        $this->authorize('archive', $plan);

        return $this->changeStatus($plan, PlanStatus::Archived, $action);
    }

    public function unarchive(Plan $plan, ChangeStatusAction $action): RedirectResponse
    {
        $this->authorize('unarchive', $plan);

        return $this->changeStatus($plan, PlanStatus::Draft, $action);
    }

    private function changeStatus(
        Plan $plan,
        PlanStatus $targetStatus,
        ChangeStatusAction $action,
    ): RedirectResponse {
        $result = $action($plan, request()->user(), $targetStatus);

        return redirect()
            ->route('admin.plans.show', $plan)
            ->with($result['changed'] ? 'success' : 'error', $result['message']);
    }
}
