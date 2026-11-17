<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SettingsRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'currency' => ['sometimes', 'string', 'in:DOP,USD'],
            'timezone' => ['sometimes', 'timezone'],
            'reminders' => ['sometimes', 'integer', 'between:1,14'],
            'week_starts' => ['sometimes', 'in:Monday,Sunday'],
            'appearance' => ['sometimes', 'in:light,dark,system'],
        ];
    }
}
