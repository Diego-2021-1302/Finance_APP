<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'destination_account_id' => ['required', 'integer', 'exists:accounts,id', 'different:source_account_id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'transferred_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
