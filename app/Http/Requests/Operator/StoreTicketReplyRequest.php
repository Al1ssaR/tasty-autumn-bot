<?php

namespace App\Http\Requests\Operator;

use Illuminate\Foundation\Http\FormRequest;

final class StoreTicketReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:4000', 'regex:/\S/u'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'body.regex' => 'Введите непустой текст ответа.',
        ];
    }
}
