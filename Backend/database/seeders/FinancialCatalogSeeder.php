<?php

namespace Database\Seeders;

use App\Models\FinancialInstitution;
use App\Models\FinancialProduct;
use App\Models\FinancialProductVariant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FinancialCatalogSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // 1. Banco Popular Dominicano
            $bpd = FinancialInstitution::updateOrCreate(
                ['sb_code' => '101'],
                [
                    'name' => 'Banco Popular Dominicano',
                    'type' => 'banco_multiple',
                    'logo_url' => 'https://popularenlinea.com/favicon.ico',
                    'is_active' => true,
                ]
            );

            // BPD - Visa Gold
            $p1 = FinancialProduct::updateOrCreate(
                ['financial_institution_id' => $bpd->id, 'name' => 'Visa Gold Popular'],
                [
                    'category' => 'credit_card',
                    'currency' => 'DOP',
                    'verified' => true,
                    'verification_status' => 'verified',
                    'official_source' => 'https://popularenlinea.com/personas/paginas/tarjetas.aspx',
                    'is_active' => true,
                ]
            );
            FinancialProductVariant::updateOrCreate(
                ['financial_product_id' => $p1->id, 'variant_name' => 'Estándar'],
                [
                    'min_credit_limit' => 25000.00,
                    'max_credit_limit' => 250000.00,
                    'annual_interest_rate' => 60.00,
                    'overdraft_fee_type' => 'fixed',
                    'overdraft_fee_amount' => 500.00,
                    'overdraft_fee_tax_rate' => 0.00,
                    'grace_days' => 21,
                    'effective_from' => '2026-01-01',
                    'verified' => true,
                    'verification_status' => 'verified',
                ]
            );

            // BPD - ExtraCrédito Popular
            $p2 = FinancialProduct::updateOrCreate(
                ['financial_institution_id' => $bpd->id, 'name' => 'ExtraCrédito Popular'],
                [
                    'category' => 'credit_line',
                    'currency' => 'DOP',
                    'verified' => true,
                    'verification_status' => 'verified',
                    'official_source' => 'https://popularenlinea.com/personas/paginas/extracredito.aspx',
                    'is_active' => true,
                ]
            );
            FinancialProductVariant::updateOrCreate(
                ['financial_product_id' => $p2->id, 'variant_name' => 'Preferencial'],
                [
                    'min_credit_limit' => 10000.00,
                    'max_credit_limit' => 500000.00,
                    'annual_interest_rate' => 24.00,
                    'overdraft_fee_type' => 'none',
                    'grace_days' => 30,
                    'effective_from' => '2026-01-01',
                    'verified' => true,
                    'verification_status' => 'verified',
                ]
            );

            // BPD - Cuenta de Ahorros
            $p3 = FinancialProduct::updateOrCreate(
                ['financial_institution_id' => $bpd->id, 'name' => 'Cuenta de Ahorros Popular'],
                [
                    'category' => 'savings_account',
                    'currency' => 'DOP',
                    'verified' => true,
                    'verification_status' => 'verified',
                    'official_source' => 'https://popularenlinea.com/personas/paginas/cuentas-ahorros.aspx',
                    'is_active' => true,
                ]
            );
            FinancialProductVariant::updateOrCreate(
                ['financial_product_id' => $p3->id, 'variant_name' => 'Ahorro Pesos'],
                [
                    'annual_interest_rate' => 1.50,
                    'overdraft_fee_type' => 'none',
                    'effective_from' => '2026-01-01',
                    'verified' => true,
                    'verification_status' => 'verified',
                ]
            );

            // 2. Banco de Reservas (Banreservas)
            $br = FinancialInstitution::updateOrCreate(
                ['sb_code' => '102'],
                [
                    'name' => 'Banreservas',
                    'type' => 'banco_multiple',
                    'logo_url' => 'https://www.banreservas.com/favicon.ico',
                    'is_active' => true,
                ]
            );

            // Banreservas - Visa Platinum
            $p4 = FinancialProduct::updateOrCreate(
                ['financial_institution_id' => $br->id, 'name' => 'Visa Platinum Banreservas'],
                [
                    'category' => 'credit_card',
                    'currency' => 'DOP',
                    'verified' => true,
                    'verification_status' => 'verified',
                    'official_source' => 'https://www.banreservas.com/tarjetas',
                    'is_active' => true,
                ]
            );
            FinancialProductVariant::updateOrCreate(
                ['financial_product_id' => $p4->id, 'variant_name' => 'Personal'],
                [
                    'min_credit_limit' => 50000.00,
                    'max_credit_limit' => 500000.00,
                    'annual_interest_rate' => 58.00,
                    'overdraft_fee_type' => 'fixed',
                    'overdraft_fee_amount' => 450.00,
                    'grace_days' => 21,
                    'effective_from' => '2026-01-01',
                    'verified' => true,
                    'verification_status' => 'verified',
                ]
            );

            // Banreservas - Préstamo Personal
            $p5 = FinancialProduct::updateOrCreate(
                ['financial_institution_id' => $br->id, 'name' => 'Préstamo Personal Banreservas'],
                [
                    'category' => 'personal_loan',
                    'currency' => 'DOP',
                    'verified' => true,
                    'verification_status' => 'verified',
                    'official_source' => 'https://www.banreservas.com/prestamos',
                    'is_active' => true,
                ]
            );
            FinancialProductVariant::updateOrCreate(
                ['financial_product_id' => $p5->id, 'variant_name' => 'Tasa Fija 1 Año'],
                [
                    'min_credit_limit' => 20000.00,
                    'max_credit_limit' => 1000000.00,
                    'annual_interest_rate' => 18.50,
                    'overdraft_fee_type' => 'none',
                    'grace_days' => 30,
                    'effective_from' => '2026-01-01',
                    'verified' => true,
                    'verification_status' => 'verified',
                ]
            );

            // 3. Banco BHD
            $bhd = FinancialInstitution::updateOrCreate(
                ['sb_code' => '103'],
                [
                    'name' => 'Banco BHD',
                    'type' => 'banco_multiple',
                    'logo_url' => 'https://www.bhd.com.do/favicon.ico',
                    'is_active' => true,
                ]
            );

            // BHD - Crediclick BHD
            $p6 = FinancialProduct::updateOrCreate(
                ['financial_institution_id' => $bhd->id, 'name' => 'Crediclick BHD'],
                [
                    'category' => 'credit_line',
                    'currency' => 'DOP',
                    'verified' => true,
                    'verification_status' => 'verified',
                    'official_source' => 'https://www.bhd.com.do/personas/crediclick',
                    'is_active' => true,
                ]
            );
            FinancialProductVariant::updateOrCreate(
                ['financial_product_id' => $p6->id, 'variant_name' => 'Línea Digital'],
                [
                    'min_credit_limit' => 15000.00,
                    'max_credit_limit' => 300000.00,
                    'annual_interest_rate' => 22.00,
                    'overdraft_fee_type' => 'none',
                    'effective_from' => '2026-01-01',
                    'verified' => true,
                    'verification_status' => 'verified',
                ]
            );

            // 4. Qik Banco Digital
            $qik = FinancialInstitution::updateOrCreate(
                ['sb_code' => '109'],
                [
                    'name' => 'Qik Banco Digital',
                    'type' => 'banco_digital',
                    'logo_url' => 'https://qik.com.do/favicon.ico',
                    'is_active' => true,
                ]
            );

            // Qik - Tarjeta de Crédito Qik Mastercard
            $p7 = FinancialProduct::updateOrCreate(
                ['financial_institution_id' => $qik->id, 'name' => 'Tarjeta Mastercard Qik'],
                [
                    'category' => 'credit_card',
                    'currency' => 'DOP',
                    'verified' => true,
                    'verification_status' => 'verified',
                    'official_source' => 'https://qik.com.do/tarjeta',
                    'is_active' => true,
                ]
            );
            FinancialProductVariant::updateOrCreate(
                ['financial_product_id' => $p7->id, 'variant_name' => 'Digital & Física'],
                [
                    'min_credit_limit' => 10000.00,
                    'max_credit_limit' => 300000.00,
                    'annual_interest_rate' => 54.00,
                    'overdraft_fee_type' => 'none',
                    'grace_days' => 21,
                    'effective_from' => '2026-01-01',
                    'verified' => true,
                    'verification_status' => 'verified',
                ]
            );

            // 5. APAP - Asociación Popular de Ahorros y Préstamos
            $apap = FinancialInstitution::updateOrCreate(
                ['sb_code' => '201'],
                [
                    'name' => 'Asociación Popular de Ahorros y Préstamos (APAP)',
                    'type' => 'asociacion_ahorros_prestamos',
                    'logo_url' => 'https://www.apap.com.do/favicon.ico',
                    'is_active' => true,
                ]
            );

            // APAP - Préstamo Hipotecario
            $p8 = FinancialProduct::updateOrCreate(
                ['financial_institution_id' => $apap->id, 'name' => 'Préstamo Hipotecario APAP'],
                [
                    'category' => 'mortgage',
                    'currency' => 'DOP',
                    'verified' => true,
                    'verification_status' => 'verified',
                    'official_source' => 'https://www.apap.com.do/hipotecario',
                    'is_active' => true,
                ]
            );
            FinancialProductVariant::updateOrCreate(
                ['financial_product_id' => $p8->id, 'variant_name' => 'Vivienda Familiar'],
                [
                    'min_credit_limit' => 500000.00,
                    'max_credit_limit' => 15000000.00,
                    'annual_interest_rate' => 12.50,
                    'overdraft_fee_type' => 'none',
                    'grace_days' => 30,
                    'effective_from' => '2026-01-01',
                    'verified' => true,
                    'verification_status' => 'verified',
                ]
            );
        });
    }
}
