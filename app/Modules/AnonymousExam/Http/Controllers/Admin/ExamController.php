<?php

namespace App\Modules\AnonymousExam\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\AnonymousExam\Http\Requests\SaveExamRequest;
use App\Modules\AnonymousExam\Models\Exam;
use App\Modules\AnonymousExam\Models\Group;
use App\Modules\AnonymousExam\Models\OutboxMessage;
use App\Modules\AnonymousExam\Services\ExamBuilderService;
use App\Modules\AnonymousExam\Services\ExamLifecycleService;
use App\Modules\AnonymousExam\Services\ResultsService;
use App\Modules\AnonymousExam\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class ExamController extends Controller
{
    public function __construct(
        private readonly ExamBuilderService $builder,
        private readonly ExamLifecycleService $lifecycle,
        private readonly ResultsService $results,
    ) {
    }

    public function index(Request $request): View
    {
        $status = $request->query('status');
        $q = trim((string) $request->query('q'));

        $exams = Exam::query()
            ->with('group')
            ->when(in_array($status, Exam::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->when(!$status, fn ($query) => $query->where('status', '!=', Exam::STATUS_ARCHIVED))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('title', 'like', '%' . addcslashes($q, '%_\\') . '%')->orWhere('public_code', $q)))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('ax::admin.exams.index', ['exams' => $exams, 'status' => $status, 'q' => $q]);
    }

    public function create(SettingsService $settings): View
    {
        return view('ax::admin.exams.builder', [
            'exam' => null,
            'groups' => Group::query()->where('is_active', true)->orderBy('title')->get(['id', 'title', 'chat_id']),
            'defaults' => ['min' => $settings->defaultMinResponses(), 'reminders' => implode(', ', $settings->defaultReminders())],
        ]);
    }

    public function edit(Exam $exam, SettingsService $settings): View
    {
        return view('ax::admin.exams.builder', [
            'exam' => $exam,
            'groups' => Group::query()->where('is_active', true)->orWhere('id', $exam->group_id)->orderBy('title')->get(['id', 'title', 'chat_id']),
            'defaults' => ['min' => $settings->defaultMinResponses(), 'reminders' => implode(', ', $settings->defaultReminders())],
        ]);
    }

    /** Run page: status, actions (announce / start / remind / close) */
    public function show(Exam $exam): View
    {
        $exam->load('group');

        return view('ax::admin.exams.show', [
            'exam' => $exam,
            'counts' => $this->results->counts($exam),
            'problems' => in_array($exam->status, [Exam::STATUS_DRAFT, Exam::STATUS_SCHEDULED], true) ? $this->lifecycle->publishProblems($exam) : [],
            'questionsCount' => $exam->questions()->count(),
            'outbox' => OutboxMessage::query()->where('exam_id', $exam->id)->latest('id')->limit(10)->get(),
        ]);
    }

    public function preview(Exam $exam): View
    {
        return view('ax::admin.exams.preview', ['exam' => $exam, 'questions' => $exam->questions()->with('options')->get()]);
    }

    public function apiShow(Exam $exam): JsonResponse
    {
        return response()->json($this->builder->toPayload($exam));
    }

    public function apiStore(SaveExamRequest $request): JsonResponse
    {
        $exam = $this->builder->create($request->validated(), $request->user('ax_admin'));

        return response()->json(['ok' => true, 'id' => $exam->id, 'exam' => $this->builder->toPayload($exam), 'edit_url' => route('ax.admin.exams.edit', $exam)]);
    }

    public function apiUpdate(SaveExamRequest $request, Exam $exam): JsonResponse
    {
        try {
            $exam = $this->builder->update($exam, $request->validated(), $request->user('ax_admin'));
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'exam' => $this->builder->toPayload($exam->refresh())]);
    }

    public function apiImport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'raw' => ['required', 'string', 'max:200000'],
            'format' => ['required', 'in:text,csv'],
        ]);

        return response()->json(['questions' => $this->builder->parseImport($data['raw'], $data['format'])]);
    }

    public function duplicate(Request $request, Exam $exam): RedirectResponse
    {
        $copy = $this->builder->duplicate($exam, $request->user('ax_admin'));

        return redirect()->route('ax.admin.exams.edit', $copy)->with('success', 'نسخهٔ جدید ساخته شد.');
    }

    public function archive(Request $request, Exam $exam): RedirectResponse
    {
        return $this->run(fn () => $this->lifecycle->archive($exam, $request->user('ax_admin')), 'آزمون بایگانی شد.');
    }

    public function publish(Request $request, Exam $exam): RedirectResponse
    {
        return $this->run(fn () => $this->lifecycle->publish($exam, $request->user('ax_admin')), 'آزمون منتشر شد.');
    }

    public function openNow(Request $request, Exam $exam): RedirectResponse
    {
        return $this->run(function () use ($exam, $request) {
            if ($exam->status === Exam::STATUS_DRAFT) {
                $exam->status = Exam::STATUS_SCHEDULED;
                $exam->save();
            }
            $this->lifecycle->open($exam, $request->user('ax_admin'));
        }, 'آزمون باز شد.');
    }

    public function announce(Request $request, Exam $exam): RedirectResponse
    {
        if (!$exam->isOpen()) {
            return back()->with('error', 'اعلام فقط برای آزمون باز ممکن است.');
        }

        return $this->run(fn () => $this->lifecycle->announce($exam, $request->user('ax_admin')), 'اعلام آزمون در صف ارسال به گروه قرار گرفت.');
    }

    public function remind(Request $request, Exam $exam): RedirectResponse
    {
        return $this->run(fn () => $this->lifecycle->sendReminder($exam, null, $request->user('ax_admin')), 'یادآوری ارسال شد.');
    }

    /** «پایان آزمون» – two-step confirmation: the admin must type the exam's public code */
    public function close(Request $request, Exam $exam): RedirectResponse
    {
        if (!hash_equals($exam->public_code, strtolower(trim((string) $request->input('confirm_code'))))) {
            return back()->with('error', 'برای تأیید پایان آزمون، کد آزمون را دقیق وارد کنید.');
        }

        return $this->run(fn () => $this->lifecycle->close($exam, $request->user('ax_admin')), 'آزمون بسته شد و نتایج باز شد.', route('ax.admin.exams.results', $exam));
    }

    private function run(callable $action, string $success, ?string $redirect = null): RedirectResponse
    {
        try {
            $action();
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return ($redirect ? redirect($redirect) : back())->with('success', $success);
    }
}
