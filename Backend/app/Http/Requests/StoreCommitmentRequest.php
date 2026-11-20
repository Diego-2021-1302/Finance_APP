<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommitmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

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
                'minimum_payment' => ['nullable', 'numeric', 'gte:0'],
                'credit_limit' => ['nullable', 'numeric', 'min:0'],
                'approved_credit_limit' => ['nullable', 'numeric', 'min:0'],
                'authorized_overdraft_limit' => ['nullable', 'numeric', 'min:0'],
                'limit_status' => ['nullable', 'in:unknown,confirmed,pending_verification,special_rules'],
                'special_rules_documented' => ['nullable', 'boolean'],
                'special_rules_source' => ['nullable', 'string', 'max:2048'],
                'special_rules_reference' => ['nullable', 'string', 'max:255'],
                'financial_institution_id' => ['nullable', 'integer', 'exists:financial_institutions,id'],
                'financial_product_variant_id' => ['nullable', 'integer', 'exists:financial_product_variants,id'],
                'overdraft_fee_type' => ['nullable', 'string', 'in:none,fixed,percentage'],
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
            'maturity_date' => [Rule::requiredIf($requiresLoanDetails), 'nullable', 'date', 'after_or_equal:next_due_date'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'approved_credit_limit' => ['nullable', 'numeric', 'min:0'],
            'authorized_overdraft_limit' => ['nullable', 'numeric', 'min:0'],
            'overdraft_fee_type' => ['nullable', 'string', 'in:none,fixed,percentage'],
            'overdraft_fee_amount' => ['nullable', 'numeric', 'min:0'],
            'overdraft_fee_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'overdraft_fee_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
