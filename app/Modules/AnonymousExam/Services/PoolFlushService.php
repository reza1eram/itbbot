<?php

namespace App\Modules\AnonymousExam\Services;

use App\Modules\AnonymousExam\Models\PoolEntry;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ANON_POOL_MODE: responses wait encrypted in ax_response_pool and are moved into ax_responses
 * in shuffled batches, so insert order / timing in the response tables cannot be matched against
 * the time a particular person submitted.
 */
class PoolFlushService
{
    public function __construct(private readonly int $minBatch = 3)
    {
    }

    /** @param bool $force flush even when fewer than minBatch entries wait (e.g. on exam close) */
    public function flush(?int $examId = null, bool $force = false): int
    {
        $examIds = $examId !== null
            ? [$examId]
            : PoolEntry::query()->distinct()->pluck('exam_id')->all();

        $moved = 0;
        foreach ($examIds as $id) {
            $moved += $this->flushExam((int) $id, $force);
        }

        return $moved;
    }

    private function flushExam(int $examId, bool $force): int
    {
        return DB::transaction(function () use ($examId, $force) {
            $entries = PoolEntry::query()->where('exam_id', $examId)->lockForUpdate()->get();
            if ($entries->isEmpty() || (!$force && $entries->count() < $this->minBatch)) {
                return 0;
            }

            $moved = 0;
            foreach ($entries->shuffle() as $entry) {
                try {
                    $data = json_decode(Crypt::decryptString($entry->payload), true, 512, JSON_THROW_ON_ERROR);
                    SubmissionService::storeResponse($examId, $data['answers'], $data['score'], new \DateTimeImmutable($data['hour']));
                    $moved++;
                } catch (Throwable $e) {
                    Log::error('ax pool flush failed', ['exam_id' => $examId, 'error' => class_basename($e)]);

                    continue;
                }
                $entry->delete();
            }

            return $moved;
        });
    }
}
