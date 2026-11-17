<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDebtRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'kind' => ['required', 'in:loan,credit_card,other'],
            'outstanding_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'minimum_payment' => ['required', 'numeric', 'gte:0', 'decimal:0,2'],
            'due_date' => ['nullable', 'date'],
            'account_id' => ['nullable', 'integer', 'min:1'],
            'last_four' => ['nullable', 'digits:4'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
