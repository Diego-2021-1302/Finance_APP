<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDebtMinimumPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'minimum_payment' => ['required', 'numeric', 'gte:0', 'decimal:0,2'],
            'due_date' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
