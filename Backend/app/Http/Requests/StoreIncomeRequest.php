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
        ];
    }
}
