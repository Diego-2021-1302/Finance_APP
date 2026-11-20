<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Commitment;
use App\Models\Debt;
use App\Models\FinancialInstitution;
use App\Models\FinancialProduct;
use App\Models\FinancialProductVariant;
use App\Models\FinancialTariffRecord;
use App\Models\FinancialTariffSyncLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FinancialCatalogController extends Controller
{
    public function institutions(Request $request): JsonResponse
    {
        $query = FinancialInstitution::query()->where('is_active', true);

        if ($q = $request->query('q')) {
            $normalized = Str::slug($q, ' ');
            $query->where(function ($builder) use ($q, $normalized) {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhere('sb_code', 'like', "%{$q}%")
                    ->orWhereRaw("LOWER(REPLACE(name, ' ', '')) LIKE ?", ['%' . str_replace(' ', '', strtolower($q)) . '%']);
            });
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        $items = $query->orderBy('name')->get();

        return response()->json(['data' => $items]);
    }

    public function categories(): JsonResponse
    {
        $categories = [
            ['code' => 'credit_card', 'label' => 'Tarjetas de Crédito'],
            ['code' => 'personal_loan', 'label' => 'Préstamos Personales'],
            ['code' => 'vehicle_loan', 'label' => 'Préstamos de Vehículos'],
            ['code' => 'mortgage', 'label' => 'Préstamos Hipotecarios'],
            ['code' => 'credit_line', 'label' => 'Líneas de Crédito / Extra Crédito / Crediclick'],
            ['code' => 'savings_account', 'label' => 'Cuentas de Ahorro'],
            ['code' => 'checking_account', 'label' => 'Cuentas Corrientes'],
        ];

        return response()->json(['data' => $categories]);
    }

    public function products(Request $request): JsonResponse
    {
        $query = FinancialProduct::query()->where('is_active', true)->with('institution', 'variants');

        if ($institutionId = $request->query('institution_id')) {
            $query->where('financial_institution_id', $institutionId);
        }

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        if ($q = $request->query('q')) {
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhereHas('institution', fn ($inst) => $inst->where('name', 'like', "%{$q}%"));
            });
        }

        $products = $query->orderBy('name')->get();

        return response()->json(['data' => $products]);
    }

    public function variants(Request $request): JsonResponse
    {
        $request->validate(['product_id' => ['required', 'integer', 'exists:financial_products,id']]);
        $variants = FinancialProductVariant::query()
            ->where('financial_product_id', $request->query('product_id'))
            ->get();

        return response()->json(['data' => $variants]);
    }

    public function link(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_type' => ['required', 'string', 'in:account,debt,commitment'],
            'entity_id' => ['required', 'integer'],
            'institution_id' => ['required', 'integer', 'exists:financial_institutions,id'],
            'variant_id' => ['required', 'integer', 'exists:financial_product_variants,id'],
        ]);

        $user = $request->user();
        $variant = FinancialProductVariant::query()->with('product')->findOrFail($validated['variant_id']);
        $institution = FinancialInstitution::query()->findOrFail($validated['institution_id']);

        if ($validated['entity_type'] === 'commitment') {
            $commitment = Commitment::query()->where('user_id', $user->id)->whereKey($validated['entity_id'])->firstOrFail();
            $commitment->update([
                'financial_institution_id' => $institution->id,
                'financial_product_variant_id' => $variant->id,
                'institution' => $institution->name,
                'interest_rate' => $variant->annual_interest_rate ?? $commitment->interest_rate,
                'overdraft_fee_type' => $variant->overdraft_fee_type ?? $commitment->overdraft_fee_type,
                'overdraft_fee_amount' => $variant->overdraft_fee_amount ?? $commitment->overdraft_fee_amount,
                'overdraft_fee_percentage' => $variant->overdraft_fee_percentage ?? $commitment->overdraft_fee_percentage,
                'overdraft_fee_tax_rate' => $variant->overdraft_fee_tax_rate ?? $commitment->overdraft_fee_tax_rate,
            ]);

            if ($commitment->debt) {
                $commitment->debt->update([
                    'financial_institution_id' => $institution->id,
                    'financial_product_variant_id' => $variant->id,
                    'overdraft_fee_type' => $variant->overdraft_fee_type ?? $commitment->debt->overdraft_fee_type,
                    'overdraft_fee_amount' => $variant->overdraft_fee_amount ?? $commitment->debt->overdraft_fee_amount,
                    'overdraft_fee_percentage' => $variant->overdraft_fee_percentage ?? $commitment->debt->overdraft_fee_percentage,
                    'overdraft_fee_tax_rate' => $variant->overdraft_fee_tax_rate ?? $commitment->debt->overdraft_fee_tax_rate,
                ]);
            }

            if ($commitment->account) {
                $commitment->account->update([
                    'financial_institution_id' => $institution->id,
                    'financial_product_variant_id' => $variant->id,
                    'institution' => $institution->name,
                ]);
            }

            return response()->json(['data' => ['linked' => true, 'commitment' => $commitment->fresh()]]);
        }

        if ($validated['entity_type'] === 'account') {
            $account = Account::query()->where('user_id', $user->id)->whereKey($validated['entity_id'])->firstOrFail();
            $account->update([
                'financial_institution_id' => $institution->id,
                'financial_product_variant_id' => $variant->id,
                'institution' => $institution->name,
            ]);

            return response()->json(['data' => ['linked' => true, 'account' => $account->fresh()]]);
        }

        $debt = Debt::query()->where('user_id', $user->id)->whereKey($validated['entity_id'])->firstOrFail();
        $debt->update([
            'financial_institution_id' => $institution->id,
            'financial_product_variant_id' => $variant->id,
        ]);

        return response()->json(['data' => ['linked' => true, 'debt' => $debt->fresh()]]);
    }

    public function tariffs(Request $request): JsonResponse
    {
        $query = FinancialTariffRecord::query()->with('institution', 'product', 'variant');

        if ($institutionId = $request->query('institution_id')) {
            $query->where('financial_institution_id', $institutionId);
        }

        if ($productId = $request->query('product_id')) {
            $query->where('financial_product_id', $productId);
        }

        if ($status = $request->query('status')) {
            $query->where('verification_status', $status);
        }

        $records = $query->orderByDesc('effective_from')->orderByDesc('version')->paginate(50);

        return response()->json([
            'data' => $records->items(),
            'meta' => [
                'current_page' => $records->currentPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    public function syncLogs(Request $request): JsonResponse
    {
        $query = FinancialTariffSyncLog::query()->with('institution');

        if ($institutionId = $request->query('institution_id')) {
            $query->where('financial_institution_id', $institutionId);
        }

        $logs = $query->orderByDesc('created_at')->paginate(50);

        return response()->json([
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
