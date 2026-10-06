<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaBoard;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagination_orders_questions_and_preserves_combined_filters(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();
        $ids = [];
        for ($i = 0; $i < 21; $i++) {
            $ids[] = QaThread::factory()->for($student, 'user')->for($certification)->create([
                'title' => 'needle '.$i, 'created_at' => now()->addSeconds($i),
            ])->id;
        }
        $query = ['certification_id' => $certification->id, 'status' => 'unresolved', 'keyword' => 'needle'];

        $response = $this->actingAs($student)->get(route('qa-board.index', $query))->assertOk();
        $paginator = $response->viewData('threads');
        $this->assertSame(20, $paginator->perPage());
        $this->assertSame(21, $paginator->total());
        $this->assertSame(array_slice(array_reverse($ids), 0, 20), $paginator->pluck('id')->all());
        parse_str(parse_url($paginator->nextPageUrl(), PHP_URL_QUERY), $nextQuery);
        $this->assertSame([...$query, 'page' => '2'], $nextQuery);
        $pageTwo = $this->get($paginator->nextPageUrl())->assertOk();
        $this->assertSame([$ids[0]], $pageTwo->viewData('threads')->pluck('id')->all());
    }

    public function test_search_matches_title_question_body_and_any_reply_without_duplicates(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();
        $title = QaThread::factory()->for($certification)->create(['title' => 'findme title']);
        $body = QaThread::factory()->for($certification)->create(['body' => 'findme body']);
        $answered = QaThread::factory()->for($certification)->create();
        QaReply::factory()->count(2)->for($answered, 'thread')->create(['body' => 'findme reply']);
        QaThread::factory()->resolved()->for($certification)->create(['body' => 'findme resolved']);
        QaThread::factory()->create(['body' => 'findme another certification']);

        $response = $this->actingAs($student)->get(route('qa-board.index', [
            'certification_id' => $certification->id, 'status' => 'unresolved', 'keyword' => '  findme  ',
        ]))->assertOk();
        $this->assertEqualsCanonicalizing([$title->id, $body->id, $answered->id], $response->viewData('threads')->pluck('id')->all());
        $this->assertSame('findme', $response->viewData('filters')['keyword']);
    }

    public function test_long_and_blank_keywords_are_not_rejected_or_truncated(): void
    {
        $student = User::factory()->student()->create();
        $keyword = str_repeat('語', 101);
        $thread = QaThread::factory()->create(['body' => $keyword]);

        $response = $this->actingAs($student)->get(route('qa-board.index', ['keyword' => $keyword]))->assertOk();
        $this->assertSame($keyword, $response->viewData('filters')['keyword']);
        $this->assertSame([$thread->id], $response->viewData('threads')->pluck('id')->all());
        $blank = $this->get(route('qa-board.index', ['keyword' => '   ']))->assertOk();
        $this->assertSame(1, $blank->viewData('threads')->total());
    }

    public function test_invalid_filters_remove_only_invalid_conditions_for_each_context(): void
    {
        $student = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $message = '指定された絞り込み条件が無効なため、その条件を適用せずに表示しています。';

        foreach ([[$student, 'qa-board.index'], [$admin, 'admin.qa-board.index']] as [$viewer, $route]) {
            foreach (['invalid', (string) Str::ulid(), ['bad']] as $id) {
                $this->actingAs($viewer)->get(route($route, [
                    'certification_id' => $id, 'status' => 'resolved', 'keyword' => 'keep', 'page' => 2,
                ]))->assertRedirect(route($route, ['status' => 'resolved', 'keyword' => 'keep', 'page' => 2]))
                    ->assertSessionHas('warning', $message);
            }
            $this->get(route($route, ['certification_id' => $certification->id, 'status' => 'bad', 'keyword' => 'keep']))
                ->assertRedirect(route($route, ['certification_id' => $certification->id, 'keyword' => 'keep']));
            $this->get(route($route, ['certification_id' => 'bad', 'status' => 'bad', 'keyword' => 'keep']))
                ->assertRedirect(route($route, ['keyword' => 'keep']));
        }
    }

    public function test_role_specific_listing_and_choices_include_only_visible_certifications(): void
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $published = Certification::factory()->published()->create();
        $unassigned = Certification::factory()->published()->create();
        $draft = Certification::factory()->draft()->create();
        $archived = Certification::factory()->archived()->create();
        foreach ([$published, $draft] as $certification) {
            CertificationCoachAssignment::factory()->create(['certification_id' => $certification->id, 'user_id' => $coach->id]);
        }
        foreach ([$published, $unassigned, $draft, $archived] as $certification) {
            QaThread::factory()->for($certification)->create();
        }

        foreach ([
            [$student, 'qa-board.index', [$published->id, $unassigned->id]],
            [$coach, 'qa-board.index', [$published->id]],
            [$admin, 'admin.qa-board.index', [$published->id, $unassigned->id, $draft->id, $archived->id]],
        ] as [$viewer, $route, $expected]) {
            $response = $this->actingAs($viewer)->get(route($route))->assertOk();
            $this->assertEqualsCanonicalizing($expected, $response->viewData('certifications')->pluck('id')->all());
            $this->assertSame(count($expected), $response->viewData('threads')->total());
        }
        $this->actingAs($student)->get(route('qa-board.create'))->assertViewHas('certifications', function ($choices) use ($published, $unassigned): bool {
            return $choices->pluck('id')->sort()->values()->all() === collect([$published->id, $unassigned->id])->sort()->values()->all();
        });
        $this->get(route('qa-board.index', ['certification_id' => $draft->id, 'status' => 'bad']))->assertForbidden();
        $this->actingAs($coach)->get(route('qa-board.index', ['certification_id' => $unassigned->id]))->assertForbidden();
    }

    public function test_detail_returns_all_replies_in_oldest_first_order(): void
    {
        $thread = QaThread::factory()->create();
        for ($i = 21; $i >= 0; $i--) {
            QaReply::factory()->for($thread, 'thread')->create(['body' => 'reply-'.$i, 'created_at' => now()->addSeconds($i)]);
        }

        $response = $this->actingAs(User::factory()->student()->create())->get(route('qa-board.show', $thread))->assertOk();
        $this->assertSame(22, $response->viewData('thread')->replies->count());
        $this->assertSame(array_map(fn (int $i): string => 'reply-'.$i, range(0, 21)), $response->viewData('thread')->replies->pluck('body')->all());
    }
}
