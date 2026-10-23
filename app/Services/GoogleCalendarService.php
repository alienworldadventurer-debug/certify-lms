<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\GoogleCredentialStatus;
use App\Exceptions\GoogleCalendar\GoogleCalendarException;
use App\Models\GoogleCredential;
use App\Models\Meeting;
use App\Models\User;
use Carbon\Carbon;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\FreeBusyRequest;
use Google\Service\Calendar\FreeBusyRequestItem;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * コーチの Google カレンダー連携を扱う Service。
 *
 * - 空き判定: 主カレンダーの busy 区間のみを取得する（予定の件名・詳細は取得しない）。
 * - 予定の登録・削除: 面談予約・キャンセルの DB コミット後に呼ばれ、失敗はログに残すのみで
 *   呼び出し元の処理や画面には影響させない。
 * - 認証エラー(401 / invalid_grant)のときだけ連携状態を error に切り替える。
 */
final class GoogleCalendarService
{
    private const TIMEOUT_SECONDS = 5;

    private const TOKEN_MARGIN_SECONDS = 60;

    private const TIMEZONE = 'Asia/Tokyo';

    private const CALENDAR_ID = 'primary';

    private const SCOPES = [
        'https://www.googleapis.com/auth/calendar.freebusy',
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/calendar.readonly',
    ];

    /**
     * HTTP ハンドラをテストから差し替えるための注入口。本番は null（Guzzle 既定）。
     */
    // 型を HandlerStack にすると、コンテナが空の HandlerStack を自動生成してしまうため object にしている
    public function __construct(private readonly ?object $httpHandler = null) {}

    public function authorizationUrl(string $state): string
    {
        $client = $this->makeClient();
        $client->setState($state);

        return $client->createAuthUrl();
    }

    /**
     * 認可コードを交換し、コーチの連携情報を active として保存する。
     *
     * @throws GoogleCalendarException
     */
    public function connect(User $coach, string $code): void
    {
        $client = $this->makeClient();

        try {
            $token = $client->fetchAccessTokenWithAuthCode($code);
        } catch (Throwable $e) {
            throw new GoogleCalendarException('Google の認可コード交換に失敗しました。', false, $e);
        }

        if (isset($token['error'])) {
            throw new GoogleCalendarException('Google の認可コード交換に失敗しました。');
        }

        if (empty($token['refresh_token'])) {
            throw new GoogleCalendarException('Google からリフレッシュトークンが取得できませんでした。');
        }

        $calendarId = $this->fetchPrimaryCalendarId($client, $token);

        GoogleCredential::updateOrCreate(
            ['user_id' => $coach->id],
            [
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'],
                'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
                'calendar_id' => $calendarId,
                'status' => GoogleCredentialStatus::Active,
                'connected_at' => now(),
            ],
        );
    }

    /**
     * 指定期間の busy 区間を返す。失敗時は空配列を返す。認証エラーのときだけ連携情報を削除する。
     *
     * @return array<int, array{start: Carbon, end: Carbon}>
     */
    public function busyRanges(GoogleCredential $credential, Carbon $start, Carbon $end): array
    {
        try {
            $service = new Calendar($this->authorizedClient($credential));

            $request = new FreeBusyRequest;
            $request->setTimeMin($start->toRfc3339String());
            $request->setTimeMax($end->toRfc3339String());
            $request->setItems([$this->calendarItem()]);

            $response = $service->freebusy->query($request);
            $calendar = $response->getCalendars()[self::CALENDAR_ID] ?? null;

            $ranges = [];
            foreach ($calendar?->getBusy() ?? [] as $period) {
                $ranges[] = [
                    'start' => Carbon::parse($period->getStart()),
                    'end' => Carbon::parse($period->getEnd()),
                ];
            }

            return $ranges;
        } catch (Throwable $e) {
            $this->handleFailure($credential, $e, 'Google busy 取得に失敗しました', []);

            return [];
        }
    }

    /**
     * 面談予約の予定を登録し、google_event_id を保存する。失敗はログのみ。
     */
    public function createMeetingEvent(Meeting $meeting): void
    {
        $credential = $this->activeCredentialFor($meeting->coach_id);
        if ($credential === null) {
            return;
        }

        try {
            $start = $meeting->scheduled_at->copy();
            $end = $start->copy()->addHour();

            $event = new Event([
                'summary' => '面談：'.$meeting->student->name,
                'description' => "資格: {$meeting->enrollment->certification->name}\n相談内容: {$meeting->topic}",
                'location' => $meeting->meeting_url_snapshot,
                'start' => new EventDateTime(['dateTime' => $start->toRfc3339String(), 'timeZone' => self::TIMEZONE]),
                'end' => new EventDateTime(['dateTime' => $end->toRfc3339String(), 'timeZone' => self::TIMEZONE]),
            ]);

            $created = (new Calendar($this->authorizedClient($credential)))
                ->events->insert(self::CALENDAR_ID, $event);

            $meeting->forceFill(['google_event_id' => $created->getId()])->save();
        } catch (Throwable $e) {
            $this->handleFailure($credential, $e, 'Google 予定の登録に失敗しました', ['meeting_id' => $meeting->id]);
        }
    }

    /**
     * キャンセルされた面談の予定を削除する。404/410 は削除済みとみなして成功扱い。失敗はログのみ。
     */
    public function deleteMeetingEvent(Meeting $meeting): void
    {
        if ($meeting->google_event_id === null) {
            return;
        }

        $credential = $this->activeCredentialFor($meeting->coach_id);
        if ($credential === null) {
            return;
        }

        try {
            (new Calendar($this->authorizedClient($credential)))
                ->events->delete(self::CALENDAR_ID, $meeting->google_event_id);
        } catch (GoogleServiceException $e) {
            if (in_array($e->getCode(), [404, 410], true)) {
                return;
            }

            $this->handleFailure($credential, $e, 'Google 予定の削除に失敗しました', ['meeting_id' => $meeting->id]);
        } catch (Throwable $e) {
            $this->handleFailure($credential, $e, 'Google 予定の削除に失敗しました', ['meeting_id' => $meeting->id]);
        }
    }

    /**
     * 連携を解除する。Google 側の revoke は best effort。予定は削除しない。
     */
    public function disconnect(User $coach): void
    {
        $credential = GoogleCredential::query()->where('user_id', $coach->id)->first();
        if ($credential === null) {
            return;
        }

        try {
            $this->makeClient()->revokeToken($credential->refresh_token);
        } catch (Throwable $e) {
            Log::warning('Google トークンの revoke に失敗しました', ['credential_id' => $credential->id, 'message' => $e->getMessage()]);
        }

        $credential->delete();
    }

    /**
     * @param array<string, mixed> $token
     *
     * @throws GoogleCalendarException
     */
    private function fetchPrimaryCalendarId(Client $client, array $token): string
    {
        try {
            $client->setAccessToken($token);
            $calendarId = (new Calendar($client))->calendars->get(self::CALENDAR_ID)->getId();
        } catch (Throwable $e) {
            throw new GoogleCalendarException('Google のカレンダー情報の取得に失敗しました。', false, $e);
        }

        if (! is_string($calendarId) || $calendarId === '') {
            throw new GoogleCalendarException('Google のカレンダー ID が取得できませんでした。');
        }

        return $calendarId;
    }

    private function activeCredentialFor(string $coachId): ?GoogleCredential
    {
        return GoogleCredential::query()
            ->where('user_id', $coachId)
            ->where('status', GoogleCredentialStatus::Active)
            ->first();
    }

    /**
     * 必要ならアクセストークンを更新し、更新後の値を保存したうえで認可済み Client を返す。
     *
     * @throws GoogleCalendarException
     */
    private function authorizedClient(GoogleCredential $credential): Client
    {
        $client = $this->makeClient();

        if ($credential->expires_at === null || $credential->expires_at->lte(now()->addSeconds(self::TOKEN_MARGIN_SECONDS))) {
            $this->refreshAccessToken($credential, $client);
        } else {
            $client->setAccessToken([
                'access_token' => $credential->access_token,
                'created' => time(),
                'expires_in' => $credential->expires_at->diffInSeconds(now()),
            ]);
        }

        return $client;
    }

    /**
     * @throws GoogleCalendarException
     */
    private function refreshAccessToken(GoogleCredential $credential, Client $client): void
    {
        try {
            $token = $client->fetchAccessTokenWithRefreshToken($credential->refresh_token);
        } catch (Throwable $e) {
            $isAuthError = str_contains($e->getMessage(), 'invalid_grant') || $e->getCode() === 401;

            throw new GoogleCalendarException('アクセストークンの更新に失敗しました。', $isAuthError, $e);
        }

        if (isset($token['error'])) {
            $isAuthError = in_array($token['error'], ['invalid_grant', 'unauthorized_client'], true);

            throw new GoogleCalendarException('アクセストークンの更新に失敗しました。', $isAuthError);
        }

        $credential->forceFill([
            'access_token' => $token['access_token'],
            'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
        ])->save();
    }

    /**
     * 認証エラーなら連携情報を削除して未連携に戻し、すべての失敗をログに残す。
     */
    private function handleFailure(GoogleCredential $credential, Throwable $e, string $message, array $context): void
    {
        $isAuthError = $e instanceof GoogleCalendarException
            ? $e->isAuthError()
            : ($e instanceof GoogleServiceException && $e->getCode() === 401);

        Log::warning($message, $context + ['credential_id' => $credential->id, 'auth_error' => $isAuthError, 'message' => $e->getMessage()]);

        if ($isAuthError) {
            $credential->delete();
        }
    }

    private function makeClient(): Client
    {
        $client = new Client;
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setRedirectUri(config('services.google.redirect_uri'));
        $client->setScopes(self::SCOPES);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setHttpClient(new GuzzleClient([
            'timeout' => self::TIMEOUT_SECONDS,
            'connect_timeout' => self::TIMEOUT_SECONDS,
            'handler' => $this->httpHandler,
        ]));

        return $client;
    }

    private function calendarItem(): FreeBusyRequestItem
    {
        $item = new FreeBusyRequestItem;
        $item->setId(self::CALENDAR_ID);

        return $item;
    }
}
