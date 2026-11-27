<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreIncomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source' => ['required', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'expected_date' => ['required', 'date'],
            'frequency' => ['sometimes', 'in:once,weekly,biweekly,semimonthly,monthly,custom'],
            'recurrence_end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:expected_date'],
            'recurrence_day' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:31'],
            'recurrence_second_day' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:31'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
