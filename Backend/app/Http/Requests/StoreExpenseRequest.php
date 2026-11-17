<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'category' => ['required', 'string', 'max:64'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'occurred_at' => ['required', 'date'],
            'description' => ['required', 'string', 'max:500'],
            'reference_number' => ['nullable', 'string', 'max:80'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
