<?php

namespace App\Modules\AnonymousExam\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\AnonymousExam\Models\Exam;
use App\Modules\AnonymousExam\Models\Group;
use App\Modules\AnonymousExam\Models\OutboxMessage;
use App\Modules\AnonymousExam\Services\BotStatusService;
use App\Modules\AnonymousExam\Services\ResultsService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(ResultsService $results, BotStatusService $bot): View
    {
        $active = Exam::query()->whereIn('status', [Exam::STATUS_OPEN, Exam::STATUS_SCHEDULED])->with('group')->orderBy('ends_at')->get();

        return view('ax::admin.dashboard', [
            'active' => $active->map(fn (Exam $e) => ['exam' => $e, 'counts' => $results->counts($e)]),
            'bot' => $bot->cachedStatus(),
            'groupsCount' => Group::query()->where('is_active', true)->count(),
            'examsCount' => Exam::query()->count(),
            'failures' => OutboxMessage::query()->where('status', 'failed')->latest('id')->limit(5)->get(),
        ]);
    }

    /** Live participation counts for the dashboard (polled by JS) */
    public function live(ResultsService $results): JsonResponse
    {
        $data = Exam::query()->where('status', Exam::STATUS_OPEN)->get()->map(fn (Exam $e) => [
            'id' => $e->id,
            'submitted' => $results->counts($e)['submitted'],
        ]);

        return response()->json(['exams' => $data]);
    }
}
