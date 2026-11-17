<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDebtPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'account_id' => ['required', 'integer', 'min:1'],
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
