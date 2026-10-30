<?php

namespace Tests\Feature\AnonymousExam;

use App\Modules\AnonymousExam\Models\AccessToken;
use App\Modules\AnonymousExam\Services\AccessService;
use App\Modules\AnonymousExam\Services\BotUpdateHandler;
use App\Modules\AnonymousExam\Support\AccessResult;

/** Test 1: group member gets a token, non-member does not */
class MembershipAndAccessTest extends AxTestCase
{
    public function test_member_receives_a_one_time_link_in_private_chat(): void
    {
        $exam = $this->makeOpenQuiz();
        $this->bale->memberStatus[555001] = 'member';

        app(BotUpdateHandler::class)->handle([
            'update_id' => 1,
            'message' => ['message_id' => 1, 'text' => '/start exam_' . $exam->public_code, 'chat' => ['id' => 555001, 'type' => 'private'], 'from' => ['id' => 555001]],
        ]);

        $sent = $this->bale->sent();
        $this->assertCount(1, $sent);
        $url = $sent[0]['params']['reply_markup']['inline_keyboard'][0][0]['url'] ?? '';
        $this->assertStringStartsWith('http://localhost/x/', $url);
        $this->assertSame(1, AccessToken::query()->count());

        // only the hash of the token is stored
        $plain = basename($url);
        $this->assertNull(AccessToken::query()->whereKey($plain)->first());
        $this->assertNotNull(AccessToken::query()->whereKey(hash('sha256', $plain))->first());
    }

    public function test_non_member_is_refused(): void
    {
        $exam = $this->makeOpenQuiz();
        $this->bale->memberStatus[555002] = 'left';

        $result = app(AccessService::class)->requestAccess($exam, 555002);

        $this->assertSame(AccessResult::NOT_MEMBER, $result->status);
        $this->assertSame(0, AccessToken::query()->count());
    }

    public function test_kicked_user_is_refused_and_admins_are_allowed(): void
    {
        $exam = $this->makeOpenQuiz();
        $this->bale->memberStatus[1] = 'kicked';
        $this->bale->memberStatus[2] = 'administrator';
        $this->bale->memberStatus[3] = 'creator';

        $access = app(AccessService::class);
        $this->assertSame(AccessResult::NOT_MEMBER, $access->requestAccess($exam, 1)->status);
        $this->assertTrue($access->requestAccess($exam, 2)->isOk());
        $this->assertTrue($access->requestAccess($exam, 3)->isOk());
    }

    public function test_callback_tap_inside_the_group_is_membership_proof_in_callback_mode(): void
    {
        config(['anonymous_exam.bale.membership_mode' => 'callback']);
        $exam = $this->makeOpenQuiz();
        $handler = app(BotUpdateHandler::class);

        // /start without having tapped in the group -> refused
        $this->assertFalse(app(AccessService::class)->requestAccess($exam, 777)->isOk());

        // tap from a DIFFERENT chat is not proof
        $handler->handle(['update_id' => 10, 'callback_query' => ['id' => 'cb1', 'data' => 'ax:join:' . $exam->public_code, 'from' => ['id' => 777], 'message' => ['chat' => ['id' => -999]]]]);
        $this->assertSame(0, AccessToken::query()->count());

        // tap inside the exam's group -> link is sent privately
        $handler->handle(['update_id' => 11, 'callback_query' => ['id' => 'cb2', 'data' => 'ax:join:' . $exam->public_code, 'from' => ['id' => 777], 'message' => ['chat' => ['id' => $exam->group->chat_id]]]]);
        $this->assertSame(1, AccessToken::query()->count());
        $this->assertSame(777, (int) $this->bale->sent()[0]['params']['chat_id']);
    }

    public function test_duplicate_update_ids_are_processed_once(): void
    {
        $exam = $this->makeOpenQuiz();
        $this->bale->memberStatus[42] = 'member';
        $update = ['update_id' => 99, 'message' => ['text' => '/start exam_' . $exam->public_code, 'chat' => ['id' => 42, 'type' => 'private'], 'from' => ['id' => 42]]];

        app(BotUpdateHandler::class)->handle($update);
        app(BotUpdateHandler::class)->handle($update);

        $this->assertCount(1, $this->bale->sent());
    }

    public function test_group_registration_with_one_time_code(): void
    {
        $code = app(\App\Modules\AnonymousExam\Services\GroupService::class)->createRegistrationCode($this->makeAdmin())['code'];
        $handler = app(BotUpdateHandler::class);
        $msg = fn ($id, $text) => ['update_id' => $id, 'message' => ['text' => $text, 'chat' => ['id' => -100777, 'type' => 'group', 'title' => 'تیم'], 'from' => ['id' => 5]]];

        $handler->handle($msg(200, '/register ' . $code));
        $this->assertDatabaseHas('ax_groups', ['chat_id' => -100777, 'title' => 'تیم']);

        // code is single-use
        $handler->handle(['update_id' => 201, 'message' => ['text' => '/register ' . $code, 'chat' => ['id' => -100888, 'type' => 'group', 'title' => 'x'], 'from' => ['id' => 5]]]);
        $this->assertDatabaseMissing('ax_groups', ['chat_id' => -100888]);
    }
}
