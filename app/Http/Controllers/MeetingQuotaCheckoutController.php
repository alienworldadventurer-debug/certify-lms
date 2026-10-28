<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\MeetingQuota\CheckoutRequest;
use App\Models\MeetingPack;
use App\Services\StripeCheckoutService;
use App\UseCases\MeetingQuota\CheckoutAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 受講生の追加面談購入(Stripe Checkout)の画面表示・決済開始・完了画面を担当する。
 * 残数の加算は Webhook 側で行うため、ここでは状態を変更しない。
 */
class MeetingQuotaCheckoutController extends Controller
{
    public function show(Request $request): View
    {
        if ($request->boolean('canceled')) {
            session()->flash('info', 'お支払いをキャンセルしました。');
        }

        $plans = MeetingPack::query()
            ->published()
            ->ordered()
            ->get();

        return view('meeting-quota.checkout-select', ['plans' => $plans]);
    }

    public function store(CheckoutRequest $request, CheckoutAction $checkout): RedirectResponse
    {
        $url = $checkout($request->user(), $request->validated('meeting_pack_id'));

        if ($url === null) {
            return back()->with('error', '決済画面の作成に失敗しました。');
        }

        return redirect()->away($url);
    }

    public function success(Request $request, StripeCheckoutService $service): View
    {
        $payment = $service->findOwnPayment($request->user(), (string) $request->query('session_id', ''));

        return view('meeting-quota.success', ['payment' => $payment]);
    }
}
