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
            'kind' => ['required', 'in:loan,credit_card,personal,other'],
            'outstanding_amount' => ['required', 'numeric', 'gte:0', 'decimal:0,2'],
            'due_date' => ['nullable', 'date'],
            'account_id' => ['nullable', 'integer', 'min:1'],
            'card_product_name' => ['nullable', 'string', 'max:160'],
            'institution' => ['nullable', 'string', 'max:160'],
            'minimum_payment' => ['nullable', 'numeric', 'gte:0', 'decimal:0,2'],
            'approved_credit_limit' => ['nullable', 'numeric', 'gte:0', 'decimal:0,2'],
            'authorized_overdraft_limit' => ['nullable', 'numeric', 'gte:0', 'decimal:0,2'],
            'statement_balance' => ['nullable', 'numeric', 'gte:0', 'decimal:0,2'],
            'statement_day' => ['nullable', 'integer', 'between:1,31'],
            'payment_grace_days' => ['nullable', 'integer', 'between:1,365'],
            'loan_number' => ['required_if:kind,loan', 'nullable', 'string', 'max:80'],
            'institution' => ['nullable', 'string', 'max:160'],
            'installment_amount' => ['required_if:kind,loan', 'nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'next_payment_date' => ['required_if:kind,loan', 'nullable', 'date'],
            'recurrence' => ['required_if:kind,loan', 'nullable', 'in:biweekly,monthly'],
            'maturity_date' => ['required_if:kind,loan', 'nullable', 'date'],
            'last_four' => ['nullable', 'digits:4'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
