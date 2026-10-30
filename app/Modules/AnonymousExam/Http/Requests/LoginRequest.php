<?php

namespace App\Modules\AnonymousExam\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:60'],
            'password' => ['required', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return ['required' => 'نام کاربری و رمز عبور را وارد کنید.'];
    }
}
