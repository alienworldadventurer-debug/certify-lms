<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\QaReply\StoreRequest as StoreReplyRequest;
use App\Http\Requests\QaReply\UpdateRequest as UpdateReplyRequest;
use App\Http\Requests\QaThread\IndexRequest;
use App\Http\Requests\QaThread\StoreRequest as StoreThreadRequest;
use App\Http\Requests\QaThread\UpdateRequest as UpdateThreadRequest;
use App\Models\QaReply;
use App\Models\QaThread;
use App\UseCases\QaReply\DestroyAction as DestroyReplyAction;
use App\UseCases\QaReply\StoreAction as StoreReplyAction;
use App\UseCases\QaReply\UpdateAction as UpdateReplyAction;
use App\UseCases\QaThread\CreateAction;
use App\UseCases\QaThread\DestroyAction;
use App\UseCases\QaThread\IndexAction;
use App\UseCases\QaThread\ResolveAction;
use App\UseCases\QaThread\ShowAction;
use App\UseCases\QaThread\StoreAction;
use App\UseCases\QaThread\UnresolveAction;
use App\UseCases\QaThread\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class QaBoardController extends Controller
{
    public function index(IndexRequest $request, IndexAction $action): View
    {
        $result = $action($request->user(), $request->filters());

        return view('qa-thread.index', $result);
    }

    public function create(CreateAction $action): View
    {
        Gate::authorize('create', QaThread::class);

        return view('qa-thread.create', [
            'certifications' => $action(),
        ]);
    }

    public function store(StoreThreadRequest $request, StoreAction $action): RedirectResponse
    {
        $thread = $action($request->user(), $request->validated());

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '質問を投稿しました。');
    }

    public function show(QaThread $thread, ShowAction $action): View
    {
        Gate::authorize('view', $thread);

        return view('qa-thread.show', [
            'thread' => $action($thread),
        ]);
    }

    public function edit(QaThread $thread): View
    {
        Gate::authorize('update', $thread);

        return view('qa-thread.edit', [
            'thread' => $thread->load(['certification', 'user']),
        ]);
    }

    public function update(QaThread $thread, UpdateThreadRequest $request, UpdateAction $action): RedirectResponse
    {
        $thread = $action($thread, $request->validated());

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '質問を更新しました。');
    }

    public function destroy(QaThread $thread, Request $request, DestroyAction $action): RedirectResponse
    {
        Gate::authorize('delete', $thread);
        $action($request->user(), $thread);

        $route = $request->routeIs('admin.*')
            ? 'admin.qa-board.index'
            : 'qa-board.index';

        return redirect()
            ->route($route)
            ->with('success', '質問を削除しました。');
    }

    public function resolve(QaThread $thread, ResolveAction $action): RedirectResponse
    {
        Gate::authorize('resolve', $thread);
        $action($thread);

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '質問を解決済みにしました。');
    }

    public function unresolve(QaThread $thread, UnresolveAction $action): RedirectResponse
    {
        Gate::authorize('unresolve', $thread);
        $action($thread);

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '質問を未解決に戻しました。');
    }

    public function storeReply(
        QaThread $thread,
        StoreReplyRequest $request,
        StoreReplyAction $action,
    ): RedirectResponse {
        $reply = $action($request->user(), $thread, $request->validated());

        return redirect()
            ->to(route('qa-board.show', $thread).'#reply-'.$reply->id)
            ->with('success', '回答を投稿しました。');
    }

    public function editReply(QaThread $thread, QaReply $reply): View
    {
        abort_if($reply->qa_thread_id !== $thread->id, 404);
        Gate::authorize('update', $reply);

        return view('qa-thread.reply-edit', [
            'thread' => $thread,
            'reply' => $reply,
        ]);
    }

    public function updateReply(
        QaThread $thread,
        QaReply $reply,
        UpdateReplyRequest $request,
        UpdateReplyAction $action,
    ): RedirectResponse {
        abort_if($reply->qa_thread_id !== $thread->id, 404);
        $reply = $action($reply, $request->validated());

        return redirect()
            ->to(route('qa-board.show', $thread).'#reply-'.$reply->id)
            ->with('success', '回答を更新しました。');
    }

    public function destroyReply(
        QaThread $thread,
        QaReply $reply,
        Request $request,
        DestroyReplyAction $action,
    ): RedirectResponse {
        abort_if($reply->qa_thread_id !== $thread->id, 404);
        Gate::authorize('delete', $reply);
        $action($reply);

        $route = $request->routeIs('admin.*')
            ? 'admin.qa-board.show'
            : 'qa-board.show';

        return redirect()
            ->route($route, $thread)
            ->with('success', '回答を削除しました。');
    }
}
