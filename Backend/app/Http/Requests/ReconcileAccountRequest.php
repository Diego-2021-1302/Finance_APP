<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReconcileAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_balance' => ['required', 'numeric'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
