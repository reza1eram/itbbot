<?php

namespace App\Modules\AnonymousExam\Http\Requests;

use App\Modules\AnonymousExam\Models\Question;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('ax_admin')?->canEdit();
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'group_id' => ['nullable', 'integer', Rule::exists('ax_groups', 'id')],
            'mode' => ['required', Rule::in(['quiz', 'survey'])],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'shuffle_questions' => ['boolean'],
            'shuffle_options' => ['boolean'],
            'display_mode' => ['required', Rule::in(['single_page', 'stepwise'])],
            'show_result_to_voter' => ['required', Rule::in(['none', 'score', 'score_and_answers'])],
            'results_visibility' => ['required', Rule::in(['after_close', 'live'])],
            'min_responses_to_show' => ['required', 'integer', 'min:1', 'max:1000'],
            'announce_on_open' => ['boolean'],
            'announce_on_close' => ['boolean'],
            'reminders' => ['nullable', 'string', 'max:100'],

            'questions' => ['present', 'array', 'max:200'],
            'questions.*.type' => ['required', Rule::in(Question::TYPES)],
            'questions.*.text' => ['required', 'string', 'max:2000'],
            'questions.*.help_text' => ['nullable', 'string', 'max:1000'],
            'questions.*.is_required' => ['boolean'],
            'questions.*.points' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'questions.*.config' => ['nullable', 'array'],
            'questions.*.config.max_length' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'questions.*.config.min' => ['nullable', 'numeric'],
            'questions.*.config.max' => ['nullable', 'numeric'],
            'questions.*.options' => ['nullable', 'array', 'max:50'],
            'questions.*.options.*.text' => ['required', 'string', 'max:1000'],
            'questions.*.options.*.is_correct' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => 'عنوان',
            'ends_at' => 'زمان پایان',
            'starts_at' => 'زمان شروع',
            'questions.*.text' => 'متن سؤال',
            'questions.*.options.*.text' => 'متن گزینه',
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'فیلد :attribute الزامی است.',
            'after' => ':attribute باید بعد از زمان شروع باشد.',
            'max' => ':attribute بیش از حد طولانی است.',
            'in' => 'مقدار :attribute نامعتبر است.',
        ];
    }
}
