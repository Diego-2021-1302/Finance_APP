<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommitmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('limit_status') === 'special_rules') {
                if (!$this->boolean('special_rules_documented') || !$this->input('special_rules_source') || !$this->input('special_rules_reference')) {
                    $validator->errors()->add('limit_status', 'Las reglas especiales requieren documentación contractual con fuente y referencia.');
                }
            }

            if ($this->input('type') === 'credit_card' && $this->input('card_payment_mode') === 'minimum') {
                $minimumPayment = $this->input('minimum_payment');
                if (!is_numeric($minimumPayment) || (float) $minimumPayment <= 0) {
                    $validator->errors()->add('minimum_payment', 'Indica un pago mínimo bancario mayor que cero para usar esta modalidad.');
                }
            }
        });
    }

    public function rules(): array
    {
        $type = $this->input('type');

        if ($type === 'credit_card') {
            return [
                'name' => ['required', 'string', 'max:160'],
                'type' => ['required', 'in:credit_card'],
                'reference_number' => ['required', 'string', 'max:4', 'regex:/^\d{4}$/'],
                'institution' => ['required', 'string', 'max:160'],
                'current_balance' => ['required', 'numeric', 'min:0'],
                'statement_day' => ['required', 'integer', 'between:1,31'],
                'payment_grace_days' => ['required', 'integer', 'between:1,31'],
                'statement_balance' => ['nullable', 'numeric', 'min:0'],
                'card_payment_mode' => ['nullable', 'in:minimum,statement'],
                'interest_rate' => ['nullable', 'numeric', 'between:0,100'],
                'minimum_payment' => ['nullable', 'numeric', 'gte:0'],
                'credit_limit' => ['nullable', 'numeric', 'min:0'],
                'approved_credit_limit' => ['nullable', 'numeric', 'min:0'],
                'authorized_overdraft_limit' => ['nullable', 'numeric', 'min:0'],
                'limit_status' => ['nullable', 'in:unknown,confirmed,pending_verification,special_rules'],
                'special_rules_documented' => ['nullable', 'boolean'],
                'special_rules_source' => ['nullable', 'string', 'max:500'],
                'special_rules_reference' => ['nullable', 'string', 'max:160'],
                'card_product_name' => ['nullable', 'string', 'max:160'],
                'card_variant' => ['nullable', 'string', 'max:160'],
                'card_network' => ['nullable', 'string', 'in:Visa,Mastercard,Amex,Discover,Otra'],
                'card_currency' => ['nullable', 'string', 'in:DOP,USD,EUR'],
                'issued_at' => ['nullable', 'date'],
                'annual_fee' => ['nullable', 'numeric', 'min:0'],
                'replacement_fee' => ['nullable', 'numeric', 'min:0'],
                'cash_advance_fee' => ['nullable', 'numeric', 'min:0'],
                'late_fee' => ['nullable', 'numeric', 'min:0'],
                'card_benefits' => ['nullable', 'array'],
                'card_benefits.*' => ['string', 'max:255'],
                'card_payment_methods' => ['nullable', 'array'],
                'card_payment_methods.*' => ['string', 'max:255'],
                'overdraft_fee_type' => ['nullable', 'string', 'in:none,fixed,percentage,cutoff_fixed,cutoff_percentage,cutoff_both,cutoff_daily'],
                'overdraft_fee_amount' => ['nullable', 'numeric', 'min:0'],
                'overdraft_fee_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'overdraft_fee_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ];
        }

        $requiresLoanDetails = $type === 'loan';

        return [
            'name' => ['required', 'string', 'max:160'],
            'type' => ['sometimes', 'in:loan,credit_card,utility,subscription,insurance,rent,other'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'frequency' => [
                'required',
                'in:once,weekly,biweekly,monthly,quarterly,yearly',
                ...($requiresLoanDetails ? ['not_in:once'] : []),
            ],
            'next_due_date' => ['required', 'date'],
            'reference_number' => [
                Rule::requiredIf($requiresLoanDetails),
                'nullable',
                'string',
                'max:80',
                ...($type === 'credit_card' ? ['regex:/^\d{4}$/'] : []),
            ],
            'institution' => [Rule::requiredIf($requiresLoanDetails), 'nullable', 'string', 'max:160'],
            'current_balance' => [Rule::requiredIf($requiresLoanDetails), 'nullable', 'numeric', 'min:0'],
            'interest_rate' => [Rule::requiredIf($requiresLoanDetails), 'nullable', 'numeric', 'min:0', 'max:100'],
            'category' => [
                Rule::requiredIf(in_array($type, ['utility', 'subscription', 'insurance'], true)),
                'nullable',
                'string',
                'max:80',
            ],
            'maturity_date' => [Rule::requiredIf($requiresLoanDetails), 'nullable', 'date'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'approved_credit_limit' => ['nullable', 'numeric', 'min:0'],
            'authorized_overdraft_limit' => ['nullable', 'numeric', 'min:0'],
            'overdraft_fee_type' => ['nullable', 'string', 'in:none,fixed,percentage,cutoff_fixed,cutoff_percentage,cutoff_both,cutoff_daily'],
            'overdraft_fee_amount' => ['nullable', 'numeric', 'min:0'],
            'overdraft_fee_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'overdraft_fee_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
