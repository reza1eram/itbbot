<?php

namespace App\Modules\AnonymousExam\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\AnonymousExam\Models\Exam;
use App\Modules\AnonymousExam\Services\AuditService;
use App\Modules\AnonymousExam\Services\PoolFlushService;
use App\Modules\AnonymousExam\Services\ResultsService;
use App\Modules\AnonymousExam\Services\SchedulerHealth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResultsController extends Controller
{
    public function show(Exam $exam, ResultsService $results): View
    {
        $exam->load('group');

        return view('ax::admin.exams.results', [
            'exam' => $exam,
            'summary' => $results->summary($exam),
            'cronHealthy' => SchedulerHealth::cronHealthy(),
            'minBatch' => (int) config('anonymous_exam.anon.pool_flush_min_batch', 3),
        ]);
    }

    /**
     * Moves encrypted, waiting answers (ANON_POOL_MODE) into the readable tables now, in random order.
     * While the exam is open the minimum batch size still applies, so a single answer is never released alone.
     */
    public function flushPool(Request $request, Exam $exam, PoolFlushService $pool, AuditService $audit): RedirectResponse
    {
        $moved = $pool->flush($exam->id, $exam->isClosed());
        $audit->log($request->user('ax_admin'), 'exam.flush_pool', $exam, ['moved' => $moved]);

        if ($moved === 0) {
            return back()->with('error', 'پاسخی منتقل نشد. برای حفظ ناشناسی، تا وقتی آزمون باز است دست‌کم ' . (int) config('anonymous_exam.anon.pool_flush_min_batch', 3) . ' پاسخ باید در صف باشد.');
        }

        return back()->with('success', $moved . ' پاسخ با ترتیب تصادفی منتقل شد.');
    }

    /** CSV: UTF-8 with BOM (Excel + Persian), shuffled rows, no timestamps */
    public function csv(Request $request, Exam $exam, ResultsService $results, AuditService $audit): StreamedResponse|RedirectResponse
    {
        $rows = $results->csvRows($exam);
        if ($rows === null) {
            return back()->with('error', 'خروجی تا باز شدن نتایج (پایان آزمون و رسیدن به حداقل تعداد پاسخ) در دسترس نیست.');
        }
        $audit->log($request->user('ax_admin'), 'exam.export_csv', $exam);

        $filename = 'exam-' . $exam->public_code . '-results.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'safeCell'], $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Prevent CSV/formula injection when the file is opened in Excel */
    public static function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/u', $value) ? "'" . $value : $value;
    }
}
