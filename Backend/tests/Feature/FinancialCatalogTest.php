<?php

namespace Tests\Feature;

use App\Models\Commitment;
use App\Models\FinancialInstitution;
use App\Models\FinancialProduct;
use App\Models\FinancialProductVariant;
use App\Models\User;
use Database\Seeders\FinancialCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinancialCatalogSeeder::class);
    }

    public function test_can_list_official_dominican_institutions_and_search(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/catalog/institutions');
        $response->assertOk();
        $this->assertTrue(collect($response->json('data'))->contains('type', 'banco_multiple'));

        $searchRes = $this->withToken($token)->getJson('/api/v1/catalog/institutions?q=Popular');
        $searchRes->assertOk();
        $this->assertTrue(collect($searchRes->json('data'))->contains('name', 'Banco Popular Dominicano'));
    }

    public function test_can_list_categories_products_and_variants(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        // List categories
        $catRes = $this->withToken($token)->getJson('/api/v1/catalog/categories');
        $catRes->assertOk();
        $this->assertTrue(collect($catRes->json('data'))->contains('code', 'credit_card'));

        // List products
        $prodRes = $this->withToken($token)->getJson('/api/v1/catalog/products?category=credit_card');
        $prodRes->assertOk();
        $product = collect($prodRes->json('data'))->firstWhere('name', 'Visa Gold Popular');
        $this->assertNotNull($product);

        // List variants
        $varRes = $this->withToken($token)->getJson("/api/v1/catalog/variants?product_id={$product['id']}");
        $varRes->assertOk();
        $this->assertNotEmpty($varRes->json('data'));
    }

    public function test_can_link_existing_commitment_to_catalog_variant(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $bpd = FinancialInstitution::where('sb_code', '101')->firstOrFail();
        $product = FinancialProduct::where('financial_institution_id', $bpd->id)->where('name', 'Visa Gold Popular')->firstOrFail();
        $variant = FinancialProductVariant::where('financial_product_id', $product->id)->firstOrFail();

        // Create a manual commitment
        $this->withToken($token)->postJson('/api/v1/commitments', [
            'name' => 'Mi Tarjeta Popular',
            'type' => 'credit_card',
            'reference_number' => '1234',
            'institution' => 'Manual Bank',
            'current_balance' => '10000.00',
            'statement_day' => 15,
            'payment_grace_days' => 21,
        ]);

        $commitment = Commitment::where('user_id', $user->id)->where('name', 'Mi Tarjeta Popular')->firstOrFail();

        // Link commitment to official catalog variant
        $linkRes = $this->withToken($token)->postJson('/api/v1/catalog/link', [
            'entity_type' => 'commitment',
            'entity_id' => $commitment->id,
            'institution_id' => $bpd->id,
            'variant_id' => $variant->id,
        ]);

        $linkRes->assertOk();

        $commitment->refresh();
        $this->assertEquals($bpd->id, $commitment->financial_institution_id);
        $this->assertEquals($variant->id, $commitment->financial_product_variant_id);
        $this->assertEquals($bpd->name, $commitment->institution);
    }
}
