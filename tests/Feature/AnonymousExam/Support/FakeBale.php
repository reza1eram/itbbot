<?php

namespace Tests\Feature\AnonymousExam\Support;

use App\Modules\AnonymousExam\Bale\BaleApi;
use App\Modules\AnonymousExam\Bale\BaleApiException;

/** In-memory Bale API double that records calls */
class FakeBale implements BaleApi
{
    /** @var array<int,array{method:string,params:array}> */
    public array $calls = [];

    /** user id => getChatMember status (missing = "left") */
    public array $memberStatus = [];

    /** chat ids that refuse private messages (user never started the bot) */
    public array $blockedChats = [];

    private int $messageId = 100;

    public function isConfigured(): bool
    {
        return true;
    }

    public function call(string $method, array $params = [], ?int $timeout = null): mixed
    {
        $this->calls[] = ['method' => $method, 'params' => $params];

        return true;
    }

    public function sent(): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['method'] === 'sendMessage'));
    }

    public function getMe(): array
    {
        $this->calls[] = ['method' => 'getMe', 'params' => []];

        return ['id' => 1, 'is_bot' => true, 'username' => 'ITBbot', 'first_name' => 'ITB'];
    }

    public function getChat(int|string $chatId): array
    {
        return ['id' => $chatId, 'type' => 'group', 'title' => 'گروه'];
    }

    public function getChatMember(int|string $chatId, int|string $userId): array
    {
        $this->calls[] = ['method' => 'getChatMember', 'params' => ['chat_id' => $chatId, 'user_id' => $userId]];

        return ['status' => $this->memberStatus[$userId] ?? 'left', 'user' => ['id' => $userId]];
    }

    public function getChatAdministrators(int|string $chatId): array
    {
        return [];
    }

    public function getChatMembersCount(int|string $chatId): int
    {
        return 20;
    }

    public function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null): array
    {
        if (in_array($chatId, $this->blockedChats, false)) {
            throw new BaleApiException('Forbidden: bot can not initiate conversation', 'sendMessage', 403);
        }
        $this->calls[] = ['method' => 'sendMessage', 'params' => ['chat_id' => $chatId, 'text' => $text, 'reply_markup' => $replyMarkup]];

        return ['message_id' => ++$this->messageId];
    }

    public function deleteMessage(int|string $chatId, int|string $messageId): bool
    {
        $this->calls[] = ['method' => 'deleteMessage', 'params' => ['chat_id' => $chatId, 'message_id' => $messageId]];

        return true;
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false, ?string $url = null): bool
    {
        $this->calls[] = ['method' => 'answerCallbackQuery', 'params' => compact('callbackQueryId', 'text', 'url')];

        return true;
    }

    public function getUpdates(int $offset = 0, int $limit = 100, int $timeout = 0): array
    {
        return [];
    }

    public function setWebhook(string $url): bool
    {
        return true;
    }

    public function deleteWebhook(): bool
    {
        return true;
    }

    public function getWebhookInfo(): array
    {
        return ['url' => '', 'pending_update_count' => 0];
    }
}
