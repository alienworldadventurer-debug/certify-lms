<?php

declare(strict_types=1);

namespace Tests\Feature\Services\GoogleCalendar;

use App\Enums\GoogleCredentialStatus;
use App\Exceptions\GoogleCalendar\GoogleCalendarException;
use App\Models\GoogleCredential;
use App\Models\Meeting;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Carbon\Carbon;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

#[Group('external')]
class GoogleCalendarServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    /**
     * @param array<int, Response|\Throwable> $responses
     */
    private function serviceWith(array $responses): GoogleCalendarService
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new GoogleCalendarService($stack);
    }

    private function jsonResponse(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function googleErrorResponse(int $status, string $message): Response
    {
        return $this->jsonResponse($status, ['error' => ['code' => $status, 'message' => $message]]);
    }

    private function meetingFor(User $coach, array $attributes = []): Meeting
    {
        return Meeting::factory()->reserved()->forCoach($coach)->create($attributes + [
            'scheduled_at' => Carbon::parse('2026-10-30 19:00:00', 'Asia/Tokyo'),
        ]);
    }

    public function test_busy_ranges_returns_busy_periods_from_free_busy_response(): void
    {
        $coach = User::factory()->coach()->create();
        $credential = GoogleCredential::factory()->forCoach($coach)->create();
        $service = $this->serviceWith([
            $this->jsonResponse(200, ['calendars' => ['primary' => ['busy' => [
                ['start' => '2026-10-30T10:00:00+09:00', 'end' => '2026-10-30T11:00:00+09:00'],
            ]]]]),
        ]);

        $ranges = $service->busyRanges(
            $credential,
            Carbon::parse('2026-10-30 09:00', 'Asia/Tokyo'),
            Carbon::parse('2026-10-30 18:00', 'Asia/Tokyo'),
        );

        $this->assertCount(1, $ranges);
        $this->assertSame('2026-10-30 10:00', $ranges[0]['start']->timezone('Asia/Tokyo')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-30 11:00', $ranges[0]['end']->timezone('Asia/Tokyo')->format('Y-m-d H:i'));
        $this->assertSame('POST', $this->history[0]['request']->getMethod());
        $this->assertStringContainsString('/calendar/v3/freeBusy', (string) $this->history[0]['request']->getUri());
    }

    public function test_busy_ranges_returns_empty_and_deletes_credential_on_auth_error(): void
    {
        $coach = User::factory()->coach()->create();
        $credential = GoogleCredential::factory()->forCoach($coach)->create();
        $service = $this->serviceWith([
            $this->googleErrorResponse(401, 'Invalid Credentials'),
            $this->jsonResponse(400, ['error' => 'invalid_grant']),
        ]);

        $ranges = $service->busyRanges($credential, now(), now()->addDay());

        $this->assertSame([], $ranges);
        $this->assertDatabaseMissing('google_credentials', ['id' => $credential->id]);
    }

    public function test_busy_ranges_returns_empty_and_keeps_credential_on_server_error(): void
    {
        $coach = User::factory()->coach()->create();
        $credential = GoogleCredential::factory()->forCoach($coach)->create();
        $service = $this->serviceWith([$this->googleErrorResponse(500, 'Backend Error')]);

        $ranges = $service->busyRanges($credential, now(), now()->addDay());

        $this->assertSame([], $ranges);
        $this->assertDatabaseHas('google_credentials', ['id' => $credential->id]);
    }

    public function test_expired_token_refresh_with_invalid_grant_deletes_credential(): void
    {
        $coach = User::factory()->coach()->create();
        $credential = GoogleCredential::factory()->forCoach($coach)->create(['expires_at' => now()->subMinute()]);
        $service = $this->serviceWith([$this->jsonResponse(400, ['error' => 'invalid_grant'])]);

        $ranges = $service->busyRanges($credential, now(), now()->addDay());

        $this->assertSame([], $ranges);
        $this->assertDatabaseMissing('google_credentials', ['id' => $credential->id]);
    }

    public function test_create_meeting_event_saves_google_event_id(): void
    {
        $coach = User::factory()->coach()->create();
        GoogleCredential::factory()->forCoach($coach)->create();
        $meeting = $this->meetingFor($coach);
        $service = $this->serviceWith([$this->jsonResponse(200, ['id' => 'event-123'])]);

        $service->createMeetingEvent($meeting);

        $this->assertSame('event-123', $meeting->fresh()->google_event_id);
        $this->assertSame('POST', $this->history[0]['request']->getMethod());
        $this->assertStringContainsString('/calendars/primary/events', (string) $this->history[0]['request']->getUri());
    }

    public function test_create_meeting_event_failure_keeps_meeting_and_credential(): void
    {
        $coach = User::factory()->coach()->create();
        $credential = GoogleCredential::factory()->forCoach($coach)->create();
        $meeting = $this->meetingFor($coach);
        $service = $this->serviceWith([$this->googleErrorResponse(500, 'Backend Error')]);

        $service->createMeetingEvent($meeting);

        $this->assertNull($meeting->fresh()->google_event_id);
        $this->assertDatabaseHas('meetings', ['id' => $meeting->id, 'status' => 'reserved']);
        $this->assertDatabaseHas('google_credentials', ['id' => $credential->id]);
    }

    public function test_create_meeting_event_auth_error_deletes_credential(): void
    {
        $coach = User::factory()->coach()->create();
        $credential = GoogleCredential::factory()->forCoach($coach)->create();
        $meeting = $this->meetingFor($coach);
        $service = $this->serviceWith([
            $this->googleErrorResponse(401, 'Invalid Credentials'),
            $this->jsonResponse(400, ['error' => 'invalid_grant']),
        ]);

        $service->createMeetingEvent($meeting);

        $this->assertNull($meeting->fresh()->google_event_id);
        $this->assertDatabaseMissing('google_credentials', ['id' => $credential->id]);
    }

    public function test_create_meeting_event_does_nothing_without_credential(): void
    {
        $coach = User::factory()->coach()->create();
        $meeting = $this->meetingFor($coach);
        $service = $this->serviceWith([]);

        $service->createMeetingEvent($meeting);

        $this->assertCount(0, $this->history);
        $this->assertNull($meeting->fresh()->google_event_id);
    }

    public function test_delete_meeting_event_calls_google_with_event_id(): void
    {
        $coach = User::factory()->coach()->create();
        GoogleCredential::factory()->forCoach($coach)->create();
        $meeting = $this->meetingFor($coach, ['google_event_id' => 'event-123']);
        $service = $this->serviceWith([new Response(204)]);

        $service->deleteMeetingEvent($meeting);

        $this->assertSame('DELETE', $this->history[0]['request']->getMethod());
        $this->assertStringEndsWith('/calendars/primary/events/event-123', (string) $this->history[0]['request']->getUri());
    }

    public function test_delete_meeting_event_treats_not_found_as_success_and_keeps_credential(): void
    {
        $coach = User::factory()->coach()->create();
        $credential = GoogleCredential::factory()->forCoach($coach)->create();
        $meeting = $this->meetingFor($coach, ['google_event_id' => 'event-123']);
        $service = $this->serviceWith([$this->googleErrorResponse(404, 'Not Found')]);

        $service->deleteMeetingEvent($meeting);

        $this->assertDatabaseHas('google_credentials', ['id' => $credential->id]);
    }

    public function test_delete_meeting_event_failure_keeps_credential(): void
    {
        $coach = User::factory()->coach()->create();
        $credential = GoogleCredential::factory()->forCoach($coach)->create();
        $meeting = $this->meetingFor($coach, ['google_event_id' => 'event-123']);
        $service = $this->serviceWith([$this->googleErrorResponse(500, 'Backend Error')]);

        $service->deleteMeetingEvent($meeting);

        $this->assertDatabaseHas('google_credentials', ['id' => $credential->id]);
    }

    public function test_delete_meeting_event_auth_error_deletes_credential(): void
    {
        $coach = User::factory()->coach()->create();
        $credential = GoogleCredential::factory()->forCoach($coach)->create();
        $meeting = $this->meetingFor($coach, ['google_event_id' => 'event-123']);
        $service = $this->serviceWith([
            $this->googleErrorResponse(401, 'Invalid Credentials'),
            $this->jsonResponse(400, ['error' => 'invalid_grant']),
        ]);

        $service->deleteMeetingEvent($meeting);

        $this->assertDatabaseMissing('google_credentials', ['id' => $credential->id]);
    }

    public function test_delete_meeting_event_401_refreshes_token_and_retries_once(): void
    {
        $coach = User::factory()->coach()->create();
        $credential = GoogleCredential::factory()->forCoach($coach)->create();
        $meeting = $this->meetingFor($coach, ['google_event_id' => 'event-123']);
        $service = $this->serviceWith([
            $this->googleErrorResponse(401, 'Invalid Credentials'),
            $this->jsonResponse(200, ['access_token' => 'new-token', 'expires_in' => 3600]),
            $this->jsonResponse(204, []),
        ]);

        $service->deleteMeetingEvent($meeting);

        $this->assertCount(3, $this->history);
        $this->assertDatabaseHas('google_credentials', ['id' => $credential->id]);
    }

    public function test_delete_meeting_event_skips_google_when_no_event_id(): void
    {
        $coach = User::factory()->coach()->create();
        GoogleCredential::factory()->forCoach($coach)->create();
        $meeting = $this->meetingFor($coach);
        $service = $this->serviceWith([]);

        $service->deleteMeetingEvent($meeting);

        $this->assertCount(0, $this->history);
    }

    public function test_disconnect_deletes_only_own_credential(): void
    {
        $coachA = User::factory()->coach()->create();
        $coachB = User::factory()->coach()->create();
        $credentialA = GoogleCredential::factory()->forCoach($coachA)->create();
        $credentialB = GoogleCredential::factory()->forCoach($coachB)->create();
        $service = $this->serviceWith([$this->jsonResponse(200, [])]);

        $service->disconnect($coachA);

        $this->assertDatabaseMissing('google_credentials', ['id' => $credentialA->id]);
        $this->assertDatabaseHas('google_credentials', ['id' => $credentialB->id]);
    }

    public function test_authorization_url_contains_google_oauth_endpoint_and_state(): void
    {
        $service = $this->serviceWith([]);

        $url = $service->authorizationUrl('state-token-xyz');

        $this->assertStringContainsString('accounts.google.com/o/oauth2', $url);
        $this->assertStringContainsString('state=state-token-xyz', $url);
        $this->assertStringContainsString('access_type=offline', $url);
    }

    public function test_connect_exchanges_code_for_tokens_and_stores_credential(): void
    {
        $coach = User::factory()->coach()->create();
        $service = $this->serviceWith([
            $this->jsonResponse(200, [
                'access_token' => 'at-token-123',
                'refresh_token' => 'rt-token-456',
                'expires_in' => 3600,
            ]),
            $this->jsonResponse(200, ['id' => 'coach-calendar@example.com']),
        ]);

        $service->connect($coach, 'valid-auth-code');

        $this->assertDatabaseHas('google_credentials', [
            'user_id' => $coach->id,
            'access_token' => 'at-token-123',
            'refresh_token' => 'rt-token-456',
            'calendar_id' => 'coach-calendar@example.com',
            'status' => GoogleCredentialStatus::Active->value,
        ]);
        $this->assertCount(2, $this->history);
    }

    public function test_connect_throws_when_refresh_token_missing(): void
    {
        $coach = User::factory()->coach()->create();
        $service = $this->serviceWith([
            $this->jsonResponse(200, [
                'access_token' => 'at-token-123',
                'expires_in' => 3600,
            ]),
        ]);

        $this->expectException(GoogleCalendarException::class);
        $this->expectExceptionMessage('Google からリフレッシュトークンが取得できませんでした。');

        $service->connect($coach, 'auth-code-without-rt');
    }

    public function test_connect_throws_when_token_exchange_fails(): void
    {
        $coach = User::factory()->coach()->create();
        $service = $this->serviceWith([
            $this->jsonResponse(400, ['error' => 'invalid_grant']),
        ]);

        $this->expectException(GoogleCalendarException::class);
        $this->expectExceptionMessage('Google の認可コード交換に失敗しました。');

        $service->connect($coach, 'bad-code');
    }

    public function test_expired_token_refreshes_automatically_before_api_call_and_succeeds(): void
    {
        $coach = User::factory()->coach()->create();
        $credential = GoogleCredential::factory()->forCoach($coach)->create([
            'expires_at' => now()->subMinutes(10),
            'access_token' => 'expired-token',
            'refresh_token' => 'valid-refresh-token',
        ]);
        $service = $this->serviceWith([
            $this->jsonResponse(200, ['access_token' => 'new-refreshed-token', 'expires_in' => 3600]),
            $this->jsonResponse(200, ['calendars' => ['primary' => ['busy' => [
                ['start' => '2026-10-30T10:00:00+09:00', 'end' => '2026-10-30T11:00:00+09:00'],
            ]]]]),
        ]);

        $ranges = $service->busyRanges(
            $credential,
            Carbon::parse('2026-10-30 09:00', 'Asia/Tokyo'),
            Carbon::parse('2026-10-30 18:00', 'Asia/Tokyo'),
        );

        $this->assertCount(1, $ranges);
        $this->assertSame('new-refreshed-token', $credential->fresh()->access_token);
        $this->assertTrue($credential->fresh()->expires_at->isFuture());
        $this->assertCount(2, $this->history);
    }

    public function test_delete_meeting_event_treats_gone_410_as_success_and_keeps_credential(): void
    {
        $coach = User::factory()->coach()->create();
        $credential = GoogleCredential::factory()->forCoach($coach)->create();
        $meeting = $this->meetingFor($coach, ['google_event_id' => 'event-gone']);
        $service = $this->serviceWith([$this->googleErrorResponse(410, 'Gone')]);

        $service->deleteMeetingEvent($meeting);

        $this->assertDatabaseHas('google_credentials', ['id' => $credential->id]);
    }
}
