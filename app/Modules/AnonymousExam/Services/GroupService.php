<?php

namespace App\Modules\AnonymousExam\Services;

use App\Modules\AnonymousExam\Bale\BaleApi;
use App\Modules\AnonymousExam\Bale\BaleApiException;
use App\Modules\AnonymousExam\Models\AdminUser;
use App\Modules\AnonymousExam\Models\Group;
use App\Modules\AnonymousExam\Models\GroupMember;
use App\Modules\AnonymousExam\Models\RegistrationCode;
use App\Modules\AnonymousExam\Support\VoterHasher;
use Illuminate\Support\Facades\DB;

/** Linking Bale groups to the panel: one-time /register codes, or manual chat_id entry. */
class GroupService
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly BaleApi $bale,
        private readonly TemplateService $templates,
        private readonly AuditService $audit,
        private readonly VoterHasher $hasher,
    ) {
    }

    /** @return array{code:string,expires_at:\Carbon\Carbon} plaintext code is shown once, only its hash is stored */
    public function createRegistrationCode(?AdminUser $admin): array
    {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }
        $expires = now()->addMinutes((int) config('anonymous_exam.registration_code_ttl_minutes', 10));

        RegistrationCode::query()->create([
            'code_hash' => hash('sha256', $code),
            'created_by_admin_id' => $admin?->id,
            'expires_at' => $expires,
        ]);
        $this->audit->log($admin, 'group.code_created');

        return ['code' => $code, 'expires_at' => $expires];
    }

    /** Handles "/register <code>" sent inside a group */
    public function registerWithCode(int $chatId, ?string $title, string $code): ?Group
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9]{6,12}$/', $code)) {
            return null;
        }

        return DB::transaction(function () use ($chatId, $title, $code) {
            $row = RegistrationCode::query()->where('code_hash', hash('sha256', $code))->lockForUpdate()->first();
            if (!$row || $row->used_at || $row->expires_at->isPast()) {
                return null;
            }

            $group = Group::query()->updateOrCreate(
                ['chat_id' => $chatId],
                ['title' => $title, 'is_active' => true, 'registered_at' => now()]
            );
            $row->forceFill(['used_at' => now(), 'group_id' => $group->id])->save();
            $this->audit->log(null, 'group.registered', $group, ['via' => 'code']);

            return $group;
        });
    }

    /** Fallback when the bot cannot see /register: admin types the chat id and a test message is sent */
    public function addManually(int $chatId, ?AdminUser $admin): Group
    {
        $title = null;
        try {
            $chat = $this->bale->getChat($chatId);
            $title = $chat['title'] ?? null;
        } catch (BaleApiException) {
            // keep going: the test message below is the real check
        }

        $group = Group::query()->updateOrCreate(
            ['chat_id' => $chatId],
            ['title' => $title, 'is_active' => true, 'registered_at' => now()]
        );
        $this->audit->log($admin, 'group.registered', $group, ['via' => 'manual']);
        $this->sendTestMessage($group);

        return $group;
    }

    public function sendTestMessage(Group $group): void
    {
        $this->bale->sendMessage($group->chat_id, $this->templates->render('test_message'));
    }

    public function refreshMembersCount(Group $group): ?int
    {
        try {
            $count = $this->bale->getChatMembersCount($group->chat_id);
            $group->members_count = $count;
            $group->save();

            return $count;
        } catch (BaleApiException) {
            return null;
        }
    }

    /**
     * BALE_MEMBERSHIP_MODE=manual: replace the allow-list. Raw ids are hashed immediately and discarded.
     * @return int number of stored members
     */
    public function replaceManualMembers(Group $group, string $rawIds, ?AdminUser $admin): int
    {
        $ids = array_unique(array_filter(preg_split('/[\s,،;]+/u', $rawIds), fn ($v) => preg_match('/^-?\d{1,20}$/', $v)));

        DB::transaction(function () use ($group, $ids) {
            GroupMember::query()->where('group_id', $group->id)->delete();
            $rows = array_map(fn ($id) => ['group_id' => $group->id, 'member_hash' => $this->hasher->memberHash($id, $group->id)], $ids);
            foreach (array_chunk($rows, 500) as $chunk) {
                GroupMember::query()->insert($chunk);
            }
        });
        $this->audit->log($admin, 'group.members_replaced', $group, ['count' => count($ids)]);

        return count($ids);
    }
}
