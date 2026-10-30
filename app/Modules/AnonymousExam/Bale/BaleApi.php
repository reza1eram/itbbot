<?php

namespace App\Modules\AnonymousExam\Bale;

/**
 * Thin abstraction over the Bale Bot API (https://tapi.bale.ai/bot<TOKEN>/METHOD).
 * Every method returns the decoded `result` field or throws BaleApiException.
 */
interface BaleApi
{
    public function isConfigured(): bool;

    public function call(string $method, array $params = [], ?int $timeout = null): mixed;

    public function getMe(): array;

    public function getChat(int|string $chatId): array;

    public function getChatMember(int|string $chatId, int|string $userId): array;

    public function getChatAdministrators(int|string $chatId): array;

    public function getChatMembersCount(int|string $chatId): int;

    public function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null): array;

    public function deleteMessage(int|string $chatId, int|string $messageId): bool;

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false, ?string $url = null): bool;

    public function getUpdates(int $offset = 0, int $limit = 100, int $timeout = 0): array;

    public function setWebhook(string $url): bool;

    public function deleteWebhook(): bool;

    public function getWebhookInfo(): array;
}
