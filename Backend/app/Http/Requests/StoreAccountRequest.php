<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'institution' => ['nullable', 'string', 'max:160'],
            'type' => ['required', 'in:checking,savings,cash,credit_card,other'],
            'currency' => ['required', 'in:DOP'],
            'opening_balance' => ['required', 'numeric', 'decimal:0,2'],
        ];
    }
}
