<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Enums\MeetingStatus;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\GoogleCredential;
use App\Models\Meeting;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Carbon\Carbon;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class MeetingGoogleCalendarTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    /**
     * URL に含まれる文字列ごとに応答を返すモック。freeBusy は何度呼ばれても同じ応答にする。
     *
     * @param array<string, Response> $responsesByPath
     */
    private function mockGoogle(array $responsesByPath): void
    {
        $this->history = [];
        $handler = function (RequestInterface $request) use ($responsesByPath) {
            $this->history[] = ['request' => $request];
            foreach ($responsesByPath as $path => $response) {
                if (str_contains((string) $request->getUri(), $path)) {
                    return Create::promiseFor($response);
                }
            }

            return Create::rejectionFor(new \RuntimeException('Unexpected Google request: '.$request->getUri()));
        };
        $this->app->instance(GoogleCalendarService::class, new GoogleCalendarService(new HandlerStack($handler)));
    }

    private function jsonResponse(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function setUpBookableCoach(): array
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-room',
        ]);
        $certification = Certification::factory()->published()->create();
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();

        return [$student, $coach, $enrollment];
    }

    private function nextMondayAt10(): Carbon
    {
        return now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);
    }

    public function test_store_registers_google_event_for_connected_coach(): void
    {
        [$student, $coach, $enrollment] = $this->setUpBookableCoach();
        GoogleCredential::factory()->forCoach($coach)->create();
        $this->mockGoogle([
            'freeBusy' => $this->jsonResponse(200, ['calendars' => ['primary' => ['busy' => []]]]),
            '/events' => $this->jsonResponse(200, ['id' => 'event-abc']),
        ]);

        $this->actingAs($student)->post(route('meetings.store', $enrollment), [
            'scheduled_at' => $this->nextMondayAt10()->format('Y-m-d\TH:i:s'),
            'topic' => '相談したい',
        ])->assertRedirect();

        $meeting = Meeting::query()->where('coach_id', $coach->id)->firstOrFail();
        $this->assertSame(MeetingStatus::Reserved, $meeting->status);
        $this->assertSame('event-abc', $meeting->google_event_id);
    }

    public function test_store_excludes_coach_busy_on_google_calendar(): void
    {
        [$student, $coach, $enrollment] = $this->setUpBookableCoach();
        GoogleCredential::factory()->forCoach($coach)->create();
        $slot = $this->nextMondayAt10();
        $this->mockGoogle([
            'freeBusy' => $this->jsonResponse(200, ['calendars' => ['primary' => ['busy' => [
                ['start' => $slot->copy()->toRfc3339String(), 'end' => $slot->copy()->addHour()->toRfc3339String()],
            ]]]]),
        ]);

        $this->actingAs($student)->post(route('meetings.store', $enrollment), [
            'scheduled_at' => $slot->format('Y-m-d\TH:i:s'),
            'topic' => '相談したい',
        ])->assertRedirect();

        $this->assertDatabaseMissing('meetings', ['coach_id' => $coach->id]);
        foreach ($this->history as $entry) {
            $this->assertStringContainsString('freeBusy', (string) $entry['request']->getUri());
        }
        $this->assertNotEmpty($this->history);
    }

    public function test_store_keeps_booking_when_google_event_insert_fails(): void
    {
        [$student, $coach, $enrollment] = $this->setUpBookableCoach();
        GoogleCredential::factory()->forCoach($coach)->create();
        $this->mockGoogle([
            'freeBusy' => $this->jsonResponse(200, ['calendars' => ['primary' => ['busy' => []]]]),
            '/events' => $this->jsonResponse(500, ['error' => ['code' => 500, 'message' => 'Backend Error']]),
        ]);

        $this->actingAs($student)->post(route('meetings.store', $enrollment), [
            'scheduled_at' => $this->nextMondayAt10()->format('Y-m-d\TH:i:s'),
            'topic' => '相談したい',
        ])->assertRedirect();

        $this->assertDatabaseHas('meetings', [
            'coach_id' => $coach->id,
            'status' => MeetingStatus::Reserved->value,
            'google_event_id' => null,
        ]);
    }

    public function test_cancel_deletes_google_event_and_cancels_meeting(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
            'google_event_id' => 'event-abc',
        ]);
        GoogleCredential::factory()->forCoach($coach)->create();
        $this->mockGoogle(['/events/event-abc' => new Response(204)]);

        $this->actingAs($student)->post(route('meetings.cancel', $meeting))->assertRedirect();

        $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);
        $this->assertSame('DELETE', $this->history[0]['request']->getMethod());
        $this->assertStringEndsWith('/events/event-abc', (string) $this->history[0]['request']->getUri());
    }

    public function test_cancel_stays_canceled_when_google_delete_fails(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
            'google_event_id' => 'event-abc',
        ]);
        $credential = GoogleCredential::factory()->forCoach($coach)->create();
        $this->mockGoogle(['/events/event-abc' => $this->jsonResponse(500, ['error' => ['code' => 500, 'message' => 'Backend Error']])]);

        $this->actingAs($student)->post(route('meetings.cancel', $meeting))
            ->assertRedirect(route('meetings.show', $meeting))
            ->assertSessionHas('success');

        $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);
        $this->assertDatabaseHas('google_credentials', ['id' => $credential->id]);
    }
}
