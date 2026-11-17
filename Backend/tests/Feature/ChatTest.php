<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Commitment;
use App\Models\Debt;
use App\Models\FinancialTransaction;
use App\Models\Income;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_get_and_send_chat_messages(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/chat/messages');
        $response->assertOk()
            ->assertJsonPath('data', []);

        $sendResponse = $this->withToken($token)->postJson('/api/v1/chat/messages', [
            'message' => 'Compré un helado de 350 pesos hoy',
        ]);
        $sendResponse->assertCreated()
            ->assertJsonPath('data.role', 'assistant');
    }

    public function test_ai_responds_naturally_to_greetings_without_dumping_unneeded_data(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/chat/messages', [
            'message' => 'Hola, como estas',
        ]);

        $response->assertCreated();
        $content = $response->json('data.content');
        $this->assertStringContainsString('gracias por preguntar', mb_strtolower($content));
        $this->assertStringNotContainsString('cuenta principal: rd$', mb_strtolower($content));
    }

    public function test_ai_interactive_form_filling_and_confirmation_flow_for_income(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        // Step 1: User says "Cobré 11,000" without date
        $step1 = $this->withToken($token)->postJson('/api/v1/chat/messages', [
            'message' => 'Cobré 11,000',
        ]);
        $step1->assertCreated();
        $this->assertStringContainsString('fecha', mb_strtolower($step1->json('data.content')));

        // Step 2: User supplies date "Hoy" -> AI generates summary & confirmation prompt
        $step2 = $this->withToken($token)->postJson('/api/v1/chat/messages', [
            'message' => 'Hoy',
        ]);
        $step2->assertCreated();
        $this->assertStringContainsString('resumen', mb_strtolower($step2->json('data.content')));
        $this->assertStringContainsString('confirmar', mb_strtolower($step2->json('data.content')));

        // Income not in DB yet (awaiting confirmation)
        $this->assertEquals(0, Income::where('user_id', $user->id)->count());

        // Step 3: User confirms "Confirmar" -> Action executed!
        $step3 = $this->withToken($token)->postJson('/api/v1/chat/messages', [
            'message' => 'Confirmar',
        ]);
        $step3->assertCreated();
        $this->assertStringContainsString('confirmado', mb_strtolower($step3->json('data.content')));

        // Database verified
        $this->assertDatabaseHas('incomes', [
            'user_id' => $user->id,
            'amount' => '11000.00',
        ]);
    }

    public function test_ai_registers_debt_with_pre_save_summary_and_confirmation(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        // Step 1: User says "Tengo una deuda de 10,000 en el Banco Popular"
        $step1 = $this->withToken($token)->postJson('/api/v1/chat/messages', [
            'message' => 'Tengo una deuda de 10,000 en el Banco Popular',
        ]);
        $step1->assertCreated();
        $this->assertStringContainsString('confirmar', mb_strtolower($step1->json('data.content')));

        // Step 2: Confirm
        $step2 = $this->withToken($token)->postJson('/api/v1/chat/messages', [
            'message' => 'Confirmar',
        ]);
        $step2->assertCreated();

        $this->assertDatabaseHas('debts', [
            'user_id' => $user->id,
            'outstanding_amount' => '10000.00',
        ]);
    }

    public function test_ai_provides_personalized_financial_opinion_when_asked(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        // Create an account with balance
        $acc = Account::create([
            'user_id' => $user->id,
            'name' => 'Cuenta Principal',
            'type' => 'checking',
            'currency' => 'DOP',
            'is_active' => true,
        ]);
        FinancialTransaction::create([
            'user_id' => $user->id,
            'account_id' => $acc->id,
            'description' => 'Apertura',
            'amount' => '25000.00',
            'occurred_at' => now()->toDateString(),
            'kind' => 'income_receipt',
        ]);

        // Ask for analysis
        $response = $this->withToken($token)->postJson('/api/v1/chat/messages', [
            'message' => '¿Cómo ves mis finanzas?',
        ]);

        $response->assertCreated();
        $content = $response->json('data.content');
        $this->assertStringContainsString('análisis', mb_strtolower($content));
        $this->assertStringContainsString('25,000', $content);
    }
}
