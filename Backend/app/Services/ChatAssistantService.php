<?php

namespace App\Services;

use App\Actions\RecalculateCommitmentAmortization;
use App\Actions\RecordCommitmentPayment;
use App\Actions\RecordDebtPayment;
use App\Actions\RecordIncomeReceipt;
use App\Models\Account;
use App\Models\ChatMessage;
use App\Models\Commitment;
use App\Models\CommitmentOccurrence;
use App\Models\Debt;
use App\Models\FinancialTransaction;
use App\Models\Income;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatAssistantService
{
    public function processMessage(User $user, string $messageText): ChatMessage
    {
        // 1. Save user message
        $userMessage = ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'content' => $messageText,
        ]);

        // 2. Fetch context accounts (auto-provision default account if 0 exist)
        $accounts = Account::where('user_id', $user->id)->where('is_active', true)->get();
        if ($accounts->isEmpty()) {
            $defaultAccount = Account::create([
                'user_id' => $user->id,
                'name' => 'Cuenta Principal',
                'type' => 'checking',
                'currency' => 'DOP',
                'institution' => 'Banco Principal',
                'is_active' => true,
            ]);
            $accounts = collect([$defaultAccount]);
        }

        // 3. Check for active pending_action from previous assistant message
        $lastAssistantMsg = ChatMessage::where('user_id', $user->id)
            ->where('role', 'assistant')
            ->whereNotNull('pending_action')
            ->latest()
            ->first();

        if ($lastAssistantMsg && !empty($lastAssistantMsg->pending_action)) {
            $pendingResult = $this->handlePendingAction(
                $user,
                $messageText,
                $lastAssistantMsg->pending_action,
                $accounts,
                $lastAssistantMsg
            );

            if ($pendingResult) {
                return $pendingResult;
            }
        }

        // 4. Process new message intent
        $contextData = $this->buildContextData($user, $accounts);
        $aiResult = $this->queryAIOrFallback($user, $messageText, $contextData, $accounts);

        // 5. Check if action is ready or needs confirmation / missing fields
        $pendingDraft = null;
        $executedActions = [];

        if (!empty($aiResult['actions'])) {
            $action = $aiResult['actions'][0];
            $type = $action['action_type'] ?? 'none';

            if ($type !== 'none') {
                $check = $this->evaluateActionCompleteness($messageText, $type, $action['data'] ?? [], $accounts);

                if ($check['is_complete'] && ($action['confirmed'] ?? false)) {
                    // Direct execution if confirmed
                    $res = $this->executeAction($user, $action, $accounts);
                    if ($res) {
                        $executedActions[] = $res;
                    }
                } else {
                    // Requires collecting fields or pre-save summary confirmation
                    $pendingDraft = [
                        'action_type' => $type,
                        'data' => $check['data'],
                        'missing_fields' => $check['missing_fields'],
                        'options' => $check['options'] ?? [],
                        'status' => empty($check['missing_fields']) ? 'awaiting_confirmation' : 'collecting_fields',
                    ];

                    $aiResult['reply'] = $this->buildDraftPromptReply($type, $check, $accounts);
                }
            }
        }

        // 6. Save assistant message
        return ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'assistant',
            'content' => $aiResult['reply'],
            'actions_taken' => $executedActions,
            'pending_action' => $pendingDraft,
        ]);
    }

    private function handlePendingAction(User $user, string $userText, array $draft, $accounts, ChatMessage $lastMsg): ?ChatMessage
    {
        $normalized = Str::lower(trim($userText));

        // Check if user is aborting / canceling
        if (preg_match('/^(cancelar|abortar|no|descartar|borrar)/ui', $normalized)) {
            $lastMsg->update(['pending_action' => null]);
            return ChatMessage::create([
                'user_id' => $user->id,
                'role' => 'assistant',
                'content' => "❌ **Operación cancelada.** No se guardó ningún registro en la base de datos.",
                'actions_taken' => [],
                'pending_action' => null,
            ]);
        }

        $type = $draft['action_type'] ?? 'none';
        $data = $draft['data'] ?? [];
        $status = $draft['status'] ?? 'collecting_fields';

        // Check if user is confirming a completed summary
        if ($status === 'awaiting_confirmation' || preg_match('/^(confirmar|confirmado|s[íi]|guardar|aceptar)/ui', $normalized)) {
            $lastMsg->update(['pending_action' => null]);

            $executedAction = $this->executeAction($user, ['action_type' => $type, 'data' => $data], $accounts);

            $reply = "✅ **¡Registro confirmado y guardado!**\n";
            if ($executedAction) {
                $reply .= sprintf("He guardado exitosamente: **%s** (%s).", $executedAction['title'], $executedAction['detail']);
            }

            return ChatMessage::create([
                'user_id' => $user->id,
                'role' => 'assistant',
                'content' => $reply,
                'actions_taken' => $executedAction ? [$executedAction] : [],
                'pending_action' => null,
            ]);
        }

        // Collecting missing fields
        // Extract date if missing
        if (!empty($draft['missing_fields']) && in_array('date', $draft['missing_fields'], true)) {
            $parsedDate = $this->parseDate($userText);
            if ($parsedDate) {
                $data['date'] = $parsedDate;
                $draft['missing_fields'] = array_values(array_diff($draft['missing_fields'], ['date']));
            }
        }

        // Extract account if missing
        if (!empty($draft['missing_fields']) && in_array('account_id', $draft['missing_fields'], true)) {
            $matchedAcc = $this->matchAccount($normalized, $accounts);
            if ($matchedAcc) {
                $data['account_id'] = $matchedAcc->id;
                $draft['missing_fields'] = array_values(array_diff($draft['missing_fields'], ['account_id']));
            } elseif (str_contains($normalized, 'nueva') || str_contains($normalized, 'crear cuenta')) {
                // User wants to create a new account
                $draft['action_type'] = 'create_account';
                $draft['data'] = ['name' => 'Nueva Cuenta', 'type' => 'checking', 'opening_balance' => '0.00'];
                $draft['missing_fields'] = ['name', 'opening_balance'];
            } elseif ($accounts->count() === 1) {
                // Single account auto-assign
                $data['account_id'] = $accounts->first()->id;
                $draft['missing_fields'] = array_values(array_diff($draft['missing_fields'], ['account_id']));
            }
        }

        // Re-evaluate draft completeness
        $check = $this->evaluateActionCompleteness($userText, $type, $data, $accounts);
        $draft['data'] = $check['data'];
        $draft['missing_fields'] = $check['missing_fields'];
        $draft['options'] = $check['options'] ?? [];
        $draft['status'] = empty($check['missing_fields']) ? 'awaiting_confirmation' : 'collecting_fields';

        $lastMsg->update(['pending_action' => null]);

        $reply = $this->buildDraftPromptReply($type, $check, $accounts);

        return ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'assistant',
            'content' => $reply,
            'actions_taken' => [],
            'pending_action' => $draft,
        ]);
    }

    private function evaluateActionCompleteness(string $userText, string $type, array $data, $accounts): array
    {
        $missing = [];
        $options = [];

        // Common date check: parse date if empty
        if (in_array($type, ['create_income_and_receipt', 'record_expense', 'create_commitment'], true)) {
            if (empty($data['date'])) {
                $parsed = $this->parseDate($userText);
                if ($parsed) {
                    $data['date'] = $parsed;
                } else {
                    $missing[] = 'date';
                }
            }
        }

        // Account check: match account or auto-assign if single account exists
        if (in_array($type, ['create_income_and_receipt', 'record_expense', 'record_commitment_payment', 'record_debt_payment'], true)) {
            if (empty($data['account_id'])) {
                $acc = $this->matchAccount($userText, $accounts);
                if ($acc) {
                    $data['account_id'] = $acc->id;
                } elseif ($accounts->count() === 1) {
                    $data['account_id'] = $accounts->first()->id;
                } else {
                    $missing[] = 'account_id';
                }
            }
        }

        // Specific entity checks
        if ($type === 'create_income_and_receipt') {
            if (empty($data['source'])) $data['source'] = 'Salario / Ingreso';
        }

        if ($type === 'record_expense') {
            if (empty($data['description'])) $data['description'] = 'Gasto';
        }

        if ($type === 'create_commitment') {
            if (empty($data['name'])) $data['name'] = 'Factura / Servicio';
            if (empty($data['category'])) $data['category'] = 'Servicios públicos';
            if (empty($data['frequency'])) $data['frequency'] = 'monthly';
            if (empty($data['next_due_date'])) $data['next_due_date'] = $data['date'] ?? now()->toDateString();
        }

        if ($type === 'create_debt') {
            if (empty($data['name'])) $data['name'] = 'Préstamo / Deuda';
            if (empty($data['kind'])) $data['kind'] = 'loan';
            if (empty($data['minimum_payment'])) $data['minimum_payment'] = (string) round(((float) ($data['outstanding_amount'] ?? 0)) * 0.05, 2);
        }

        // Generate options for missing fields or confirmation
        if (in_array('account_id', $missing, true)) {
            foreach ($accounts as $acc) {
                $options[] = ['label' => '💳 ' . $acc->name, 'value' => $acc->name];
            }
            $options[] = ['label' => '➕ Crear nueva cuenta', 'value' => 'Crear nueva cuenta'];
        } elseif (in_array('date', $missing, true)) {
            $options[] = ['label' => '📅 Hoy (' . now()->format('d/m') . ')', 'value' => 'Hoy'];
            $options[] = ['label' => '📅 Ayer (' . now()->subDay()->format('d/m') . ')', 'value' => 'Ayer'];
        } elseif (empty($missing)) {
            $options[] = ['label' => '✅ Confirmar', 'value' => 'Confirmar'];
            $options[] = ['label' => '❌ Cancelar', 'value' => 'Cancelar'];
        }

        return [
            'is_complete' => empty($missing),
            'data' => $data,
            'missing_fields' => $missing,
            'options' => $options,
        ];
    }

    private function buildDraftPromptReply(string $type, array $check, $accounts): string
    {
        $data = $check['data'];
        $missing = $check['missing_fields'];

        if (!empty($missing)) {
            $prompts = [];
            if (in_array('date', $missing, true)) {
                $prompts[] = "📅 **¿En qué fecha se realizó este movimiento?** (Selecciona o escribe 'Hoy', 'Ayer' o una fecha como '15/11/2026').";
            }
            if (in_array('account_id', $missing, true)) {
                $accNames = $accounts->pluck('name')->toArray();
                $prompts[] = "💳 **¿En qué cuenta / método de pago ingresó o se debitó?**\nSelecciona una de tus cuentas inscritas:";
            }

            return sprintf("¡Entendido! Registraremos un %s por **RD$ %s**.\n\nPara completar la información:\n%s",
                $type === 'create_income_and_receipt' ? 'ingreso' : 'gasto',
                number_format((float) ($data['amount'] ?? 0), 2),
                implode("\n\n", $prompts)
            );
        }

        // Pre-save summary confirmation
        $accName = Account::find($data['account_id'] ?? null)?->name ?? $accounts->first()?->name ?? 'Cuenta Principal';
        $formattedDate = Carbon::parse($data['date'] ?? now())->format('d/m/Y');

        $typeName = match ($type) {
            'create_income_and_receipt' => 'Ingreso',
            'record_expense' => 'Gasto / Compra',
            'create_commitment' => 'Compromiso / Factura recurrente',
            'create_debt' => 'Deuda / Préstamo',
            'create_account' => 'Nueva Cuenta',
            default => 'Movimiento',
        };

        $nameOrSource = $data['source'] ?? $data['description'] ?? $data['name'] ?? 'General';
        $amountStr = number_format((float) ($data['amount'] ?? $data['outstanding_amount'] ?? 0), 2);

        return <<<SUMMARY
📋 **Resumen de la información a guardar:**
• **Tipo:** {$typeName}
• **Concepto/Nombre:** {$nameOrSource}
• **Monto:** RD$ {$amountStr}
• **Método de pago / Cuenta:** {$accName}
• **Fecha:** {$formattedDate}

¿Deseas guardar estos datos en tu cuenta?
Presiona o responde **Confirmar** para guardar o **Cancelar** para abortar.
SUMMARY;
    }

    private function buildContextData(User $user, $accounts): array
    {
        $commitments = CommitmentOccurrence::where('user_id', $user->id)
            ->where('status', 'scheduled')
            ->with('commitment')
            ->get();
        $debts = Debt::where('user_id', $user->id)->get();

        $history = ChatMessage::where('user_id', $user->id)
            ->latest()
            ->take(6)
            ->get()
            ->reverse();

        return [
            'accounts' => $accounts->map(fn($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'institution' => $a->institution,
                'type' => $a->type,
                'balance' => (float) FinancialTransaction::where('account_id', $a->id)->sum('amount'),
            ])->toArray(),
            'commitments' => $commitments->map(fn($c) => [
                'id' => $c->id,
                'commitment_id' => $c->commitment_id,
                'name' => $c->commitment?->name ?? 'Compromiso',
                'amount_due' => (float) $c->amount_due,
                'due_date' => $c->due_date->toDateString(),
            ])->toArray(),
            'debts' => $debts->map(fn($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'outstanding' => (float) $d->outstanding_amount,
            ])->toArray(),
            'recent_history' => $history->map(fn($h) => [
                'role' => $h->role,
                'content' => $h->content,
            ])->toArray(),
        ];
    }

    private function queryAIOrFallback(User $user, string $messageText, array $contextData, $accounts): array
    {
        $apiKey = config('services.gemini.api_key');
        if (is_string($apiKey) && $apiKey !== '') {
            try {
                $models = array_values(array_unique(array_filter(array_merge(
                    [(string) config('services.gemini.model', 'gemini-3.8-flash')],
                    config('services.gemini.fallback_models', ['gemini-3.5-flash']),
                ))));

                $prompt = $this->buildSystemPrompt($contextData, $messageText);

                foreach ($models as $model) {
                    $response = Http::acceptJson()
                        ->withHeaders(['x-goog-api-key' => $apiKey])
                        ->timeout(15)
                        ->post('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', [
                            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                            'generationConfig' => [
                                'temperature' => 0.3,
                                'maxOutputTokens' => 1024,
                                'responseMimeType' => 'application/json',
                            ],
                        ]);

                    if ($response->successful()) {
                        $text = $response->json('candidates.0.content.parts.0.text');
                        $parsed = is_string($text) ? json_decode($text, true) : null;
                        if (is_array($parsed) && isset($parsed['reply'])) {
                            return [
                                'reply' => $parsed['reply'],
                                'actions' => $parsed['actions'] ?? [],
                            ];
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Gemini chat assistant error, using smart fallback parser.', ['error' => $e->getMessage()]);
            }
        }

        // Smart Agent fallback engine
        return $this->smartAgentFallback($user, $messageText, $accounts, $contextData);
    }

    private function buildSystemPrompt(array $contextData, string $userMessage): string
    {
        $today = now()->toDateString();

        return <<<PROMPT
Eres el Agente de Finanzas Personal de Finanzas Clara (República Dominicana).
Tu objetivo es responder de forma 100% NATURAL, HUMANA, AMABLE Y CONCISA en español (RD$).

INSTRUCCIONES CLAVE DE CONVERSACIÓN Y EXTRACCIÓN DE FECHAS:
1. EXTRACCIÓN DE FECHA ("date"): Si el usuario menciona "ayer", pon "date": "ayer". Si dice "hoy", pon "date": "hoy". Si menciona una fecha específica (ej. "15 de noviembre"), conviértela a YYYY-MM-DD. Si no menciona fecha, deja "date": null.
2. EXTRACCIÓN DE CUENTA ("account_id"): Si menciona el nombre de alguna de sus cuentas (ej. "cuenta corriente", "banco popular"), pon su ID. Si no menciona ninguna cuenta, deja "account_id": null.
3. SÉ CONCISO Y PRECISO.

FECHA DE HOY: {$today}
MONEDA BASE: DOP (RD$)

DATOS FINANCIEROS DEL USUARIO:
- Cuentas: {$this->jsonEncode($contextData['accounts'])}
- Compromisos: {$this->jsonEncode($contextData['commitments'])}
- Deudas: {$this->jsonEncode($contextData['debts'])}
- Historial reciente: {$this->jsonEncode($contextData['recent_history'])}

ACCIONES CRUD DISPONIBLES:
- "create_income_and_receipt": { "source": string, "amount": float, "account_id": int/string, "date": "YYYY-MM-DD" }
- "record_expense": { "description": string, "amount": float, "account_id": int/string, "date": "YYYY-MM-DD" }
- "create_commitment": { "name": string, "type": string, "amount": float, "frequency": "monthly", "next_due_date": "YYYY-MM-DD" }
- "create_debt": { "name": string, "kind": string, "outstanding_amount": float, "minimum_payment": float }

FORMATO DE RESPUESTA JSON OBLIGATORIO:
{
  "reply": "Tu respuesta humana, fluida y natural.",
  "actions": [
    {
      "action_type": "tipo_de_accion_o_none",
      "data": { ... }
    }
  ]
}

MENSAJE DEL USUARIO: "{$userMessage}"
PROMPT;
    }

    private function smartAgentFallback(User $user, string $messageText, $accounts, array $contextData): array
    {
        $normalized = Str::lower(trim($messageText));
        $amount = $this->extractAmount($messageText);
        $date = $this->parseDate($messageText);
        $accountMatch = $this->matchAccount($normalized, $accounts);

        // 1. Natural Salutations & Small Talk
        if (preg_match('/^(hola|buenas|saludos|que tal|qué tal)/ui', $normalized)) {
            if (str_contains($normalized, 'como estas') || str_contains($normalized, 'cómo estás') || str_contains($normalized, 'como te va') || str_contains($normalized, 'cómo te va')) {
                return [
                    'reply' => "¡Hola! Estoy muy bien, gracias por preguntar. 😊 ¿Cómo estás tú y en qué te puedo ayudar hoy con tus finanzas?",
                    'actions' => [],
                ];
            }
            return [
                'reply' => "¡Hola! ¿En qué te puedo colaborar hoy?",
                'actions' => [],
            ];
        }

        // 2. Financial Analysis request
        if (preg_match('/(v[ee]s|opini[oó]n|an[aá]lisi|diagn[oó]stico|estado|resumen|situaci[oó]n|salud financiera)/ui', $normalized)) {
            $totalBalance = (float) FinancialTransaction::whereIn('account_id', $accounts->pluck('id'))->sum('amount');
            $commitments = CommitmentOccurrence::where('user_id', $user->id)->where('status', 'scheduled')->get();
            $totalCommitments = (float) $commitments->sum('amount_due');
            $debts = Debt::where('user_id', $user->id)->get();
            $totalDebts = (float) $debts->sum('outstanding_amount');

            $accountDetails = [];
            foreach ($accounts as $acc) {
                $bal = (float) FinancialTransaction::where('account_id', $acc->id)->sum('amount');
                $accountDetails[] = sprintf('%s: RD$ %s', $acc->name, number_format($bal, 2));
            }

            $reply = $this->generateFinancialOpinion($totalBalance, $totalCommitments, $totalDebts, $accountDetails, $commitments, $debts);
            return ['reply' => $reply, 'actions' => []];
        }

        // 3. Income intent: gane, cobré, cobre, consegui, recibi, salario, sueldo, me pagaron, ingreso
        if (preg_match('/(gan[eé]|cobr[eé]|consegu[ií]|recib[ií]|salario|sueldo|bono|me pagaron|pago|ingreso)/ui', $normalized)) {
            if ($amount > 0) {
                $source = 'Salario';
                if (str_contains($normalized, 'bono')) {
                    $source = 'Bono';
                } elseif (str_contains($normalized, 'trabajo') || str_contains($normalized, 'freelance')) {
                    $source = 'Trabajo / Freelance';
                } elseif (str_contains($normalized, 'venta')) {
                    $source = 'Venta';
                }

                return [
                    'reply' => 'Entendido, vamos a registrar este ingreso.',
                    'actions' => [[
                        'action_type' => 'create_income_and_receipt',
                        'data' => [
                            'source' => $source,
                            'amount' => (string) $amount,
                            'account_id' => $accountMatch?->id,
                            'date' => $date,
                        ],
                    ]],
                ];
            }
        }

        // 4. Debt Creation intent: tengo una deuda, préstamo, prestamo
        if ($amount > 0 && preg_match('/(deuda|pr[eé]stamo|tarjeta|tom[eé] prestado)/ui', $normalized)) {
            $bankName = 'Banco Principal';
            if (preg_match('/(banco popular|popular|banreservas|bhd|scotiabank|banco)/ui', $normalized, $bMatches)) {
                $bankName = ucfirst($bMatches[1]);
            }

            return [
                'reply' => 'Entendido, registremos esta deuda.',
                'actions' => [[
                    'action_type' => 'create_debt',
                    'data' => [
                        'name' => 'Préstamo / Deuda ' . $bankName,
                        'kind' => 'loan',
                        'outstanding_amount' => (string) $amount,
                        'minimum_payment' => (string) round($amount * 0.05, 2),
                    ],
                ]],
            ];
        }

        // 5. Commitment Creation intent: compromiso, pagar mensual, factura mensual
        if ($amount > 0 && (str_contains($normalized, 'mensual') || preg_match('/(compromiso|factura|tengo que pagar .* mensual)/ui', $normalized))) {
            $commName = 'Compromiso';
            if (preg_match('/(luz|agua|internet|renta|netflix|spotify|cable|seguro)/ui', $normalized, $cMatches)) {
                $commName = ucfirst($cMatches[1]);
            }

            return [
                'reply' => 'Entendido, programemos este compromiso.',
                'actions' => [[
                    'action_type' => 'create_commitment',
                    'data' => [
                        'name' => $commName,
                        'type' => 'utility',
                        'amount' => (string) $amount,
                        'frequency' => 'monthly',
                        'next_due_date' => $date ?? now()->toDateString(),
                    ],
                ]],
            ];
        }

        // 6. Expense intent: compre, gasté, gaste, pague, consumo, helado, etc.
        if ($amount > 0 && preg_match('/(compr[eé]|gast[eé]|pagu[eé]|consumo|helado|super|almuerzo|gasolina|comida|renta|luz|agua|internet)/ui', $normalized)) {
            $description = 'Gasto';
            if (preg_match('/(helado|supermercado|super|almuerzo|comida|gasolina|luz|agua|internet|renta|cine|taxi|uber)/ui', $normalized, $matches)) {
                $description = ucfirst($matches[1]);
            }

            return [
                'reply' => 'Entendido, registremos este gasto.',
                'actions' => [[
                    'action_type' => 'record_expense',
                    'data' => [
                        'description' => $description,
                        'amount' => (string) $amount,
                        'account_id' => $accountMatch?->id,
                        'date' => $date,
                    ],
                ]],
            ];
        }

        return [
            'reply' => "Entendido. Si deseas registrar algún ingreso, compra o consultar sobre tus finanzas, solo dímelo y con gusto te ayudo.",
            'actions' => [],
        ];
    }

    private function parseDate(string $text): ?string
    {
        $normalized = Str::lower($text);

        if (str_contains($normalized, 'hoy')) {
            return now()->toDateString();
        }
        if (str_contains($normalized, 'ayer')) {
            return now()->subDay()->toDateString();
        }
        if (str_contains($normalized, 'anteayer') || str_contains($normalized, 'antes de ayer')) {
            return now()->subDays(2)->toDateString();
        }

        // Match YYYY-MM-DD
        if (preg_match('/\b(20[2-9][0-9])-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])\b/', $text, $m)) {
            return $m[0];
        }

        // Match DD/MM/YYYY
        if (preg_match('/\b(0[1-9]|[12][0-9]|3[01])\/(0[1-9]|1[0-2])\/(20[2-9][0-9])\b/', $text, $m)) {
            return sprintf('%s-%s-%s', $m[3], $m[2], $m[1]);
        }

        return null;
    }

    private function generateFinancialOpinion(float $totalBalance, float $totalCommitments, float $totalDebts, array $accountDetails, $commitments, $debts): string
    {
        if ($totalBalance == 0 && $totalCommitments == 0 && $totalDebts == 0) {
            return "Tus registros financieros están en cero actualmente. Si me comentas tus ingresos o gastos (ej. 'Cobré 15,000 de sueldo ayer'), los registraré y comenzaré a analizar tu salud financiera.";
        }

        $lines = ["Aquí tienes mi análisis de tu estado financiero:\n"];
        $lines[] = sprintf("• **Saldo disponible:** RD$ %s (%s).", number_format($totalBalance, 2), implode(', ', $accountDetails));
        $lines[] = sprintf("• **Compromisos próximos:** RD$ %s.", number_format($totalCommitments, 2));
        $lines[] = sprintf("• **Deudas activas:** RD$ %s.", number_format($totalDebts, 2));

        $netAfterCommitments = $totalBalance - $totalCommitments;

        $lines[] = "\n**Mi opinión:**";
        if ($totalBalance >= $totalCommitments && $netAfterCommitments > 0) {
            $lines[] = sprintf(
                "Tienes buena liquidez. Tras cubrir tus compromisos próximos de RD$ %s, te quedan RD$ %s libres.",
                number_format($totalCommitments, 2),
                number_format($netAfterCommitments, 2)
            );
        } else {
            $lines[] = "Tus saldos actuales cubren justamente tus compromisos próximos.";
        }

        return implode("\n", $lines);
    }

    private function extractAmount(string $text): float
    {
        $normalized = Str::lower($text);

        if (preg_match('/([0-9]+(?:[,.][0-9]+)?)\s*(?:mil|k)\b/ui', $normalized, $m)) {
            $raw = (float) str_replace(',', '.', $m[1]);
            return $raw * 1000.0;
        }

        if (preg_match_all('/([0-9]{1,3}(?:[,.][0-9]{3})+|[0-9]+(?:[,.][0-9]{1,2})?)/u', $text, $matches)) {
            foreach ($matches[1] as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '') continue;

                if (preg_match('/^[0-9]{1,3}(?:[,.][0-9]{3})+$/', $candidate)) {
                    $val = (float) preg_replace('/[,.]/', '', $candidate);
                    if ($val > 0) return $val;
                }

                if (str_contains($candidate, ',') && str_contains($candidate, '.')) {
                    if (strrpos($candidate, '.') > strrpos($candidate, ',')) {
                        $candidate = str_replace(',', '', $candidate);
                    } else {
                        $cleaned = str_replace('.', '', $candidate);
                        $cleaned = str_replace(',', '.', $candidate);
                    }
                } elseif (str_contains($candidate, ',')) {
                    $parts = explode(',', $candidate);
                    if (count($parts) === 2 && strlen($parts[1]) === 3) {
                        $candidate = implode('', $parts);
                    } elseif (count($parts) === 2 && strlen($parts[1]) <= 2) {
                        $cleaned = $parts[0].'.'.$parts[1];
                    } else {
                        $cleaned = str_replace(',', '', $candidate);
                    }
                } elseif (str_contains($candidate, '.')) {
                    $parts = explode('.', $candidate);
                    if (count($parts) === 2 && strlen($parts[1]) === 3) {
                        $cleaned = implode('', $parts);
                    } else {
                        $cleaned = $candidate;
                    }
                } else {
                    $cleaned = $candidate;
                }

                $val = (float) $cleaned;
                if ($val > 0) return $val;
            }
        }

        return 0.0;
    }

    private function matchAccount(string $normalized, $accounts): ?Account
    {
        foreach ($accounts as $acc) {
            $nameNorm = Str::lower($acc->name);
            if (str_contains($normalized, $nameNorm)) {
                return $acc;
            }
            if ($acc->institution && str_contains($normalized, Str::lower($acc->institution))) {
                return $acc;
            }
        }
        return null;
    }

    private function getOrCreateDefaultAccount(User $user, &$accounts): Account
    {
        $existing = $accounts->first() ?? Account::where('user_id', $user->id)->where('is_active', true)->first();
        if ($existing) {
            return $existing;
        }

        $newAccount = Account::create([
            'user_id' => $user->id,
            'name' => 'Cuenta Principal',
            'type' => 'checking',
            'currency' => 'DOP',
            'institution' => 'Banco Principal',
            'is_active' => true,
        ]);

        $accounts = collect([$newAccount]);
        return $newAccount;
    }

    private function executeAction(User $user, array $action, &$accounts): ?array
    {
        $type = $action['action_type'] ?? 'none';
        $data = $action['data'] ?? [];

        try {
            if ($type === 'create_income_and_receipt') {
                $source = $data['source'] ?? 'Salario';
                $amount = (string) ($data['amount'] ?? '0.00');
                $date = $data['date'] ?? now()->toDateString();

                $account = Account::where('user_id', $user->id)->find($data['account_id'] ?? null)
                    ?? $this->getOrCreateDefaultAccount($user, $accounts);

                $income = Income::create([
                    'user_id' => $user->id,
                    'source' => $source,
                    'amount' => $amount,
                    'expected_date' => $date,
                ]);

                app(RecordIncomeReceipt::class)->execute($user, $income, [
                    'account_id' => $account->id,
                    'amount' => $amount,
                    'received_at' => $date,
                    'idempotency_key' => Str::uuid()->toString(),
                ]);

                return [
                    'type' => 'income',
                    'title' => 'Ingreso registrado',
                    'detail' => sprintf('%s · RD$ %s en %s (Fecha: %s)', $source, number_format((float) $amount, 2), $account->name, $date),
                ];
            }

            if ($type === 'record_expense') {
                $description = $data['description'] ?? 'Gasto';
                $amount = (string) ($data['amount'] ?? '0.00');
                $date = $data['date'] ?? now()->toDateString();

                if ((float) $amount <= 0) return null;

                $account = Account::where('user_id', $user->id)->find($data['account_id'] ?? null)
                    ?? $this->getOrCreateDefaultAccount($user, $accounts);

                FinancialTransaction::create([
                    'user_id' => $user->id,
                    'account_id' => $account->id,
                    'description' => $description,
                    'amount' => bcsub('0.00', $amount, 2),
                    'occurred_at' => $date,
                    'kind' => 'expense',
                ]);

                return [
                    'type' => 'expense',
                    'title' => 'Gasto registrado',
                    'detail' => sprintf('%s · RD$ %s desde %s (Fecha: %s)', $description, number_format((float) $amount, 2), $account->name, $date),
                ];
            }

            if ($type === 'create_commitment') {
                $name = $data['name'] ?? 'Compromiso';
                $commType = $data['type'] ?? 'utility';
                $amount = (string) ($data['amount'] ?? '0.00');
                $frequency = $data['frequency'] ?? 'monthly';
                $nextDueDate = $data['next_due_date'] ?? $data['date'] ?? now()->toDateString();

                if ((float) $amount <= 0) return null;

                $commitment = Commitment::create([
                    'user_id' => $user->id,
                    'name' => $name,
                    'type' => $commType,
                    'amount' => $amount,
                    'frequency' => $frequency,
                    'next_due_date' => $nextDueDate,
                    'is_active' => true,
                ]);

                CommitmentOccurrence::create([
                    'user_id' => $user->id,
                    'commitment_id' => $commitment->id,
                    'amount_due' => $amount,
                    'due_date' => $nextDueDate,
                    'status' => 'scheduled',
                    'schedule_version' => 1,
                ]);

                return [
                    'type' => 'commitment',
                    'title' => 'Compromiso programado',
                    'detail' => sprintf('%s · RD$ %s mensual (Vence: %s)', $name, number_format((float) $amount, 2), $nextDueDate),
                ];
            }

            if ($type === 'create_debt') {
                $name = $data['name'] ?? 'Deuda';
                $kind = $data['kind'] ?? 'loan';
                $outstanding = (string) ($data['outstanding_amount'] ?? '0.00');
                $minimum = (string) ($data['minimum_payment'] ?? '0.00');

                if ((float) $outstanding <= 0) return null;

                $debt = Debt::create([
                    'user_id' => $user->id,
                    'name' => $name,
                    'kind' => $kind,
                    'outstanding_amount' => $outstanding,
                    'minimum_payment' => $minimum,
                ]);

                return [
                    'type' => 'debt',
                    'title' => 'Deuda/Préstamo registrado',
                    'detail' => sprintf('%s · RD$ %s', $name, number_format((float) $outstanding, 2)),
                ];
            }

            if ($type === 'create_account') {
                $name = $data['name'] ?? 'Nueva Cuenta';
                $accType = $data['type'] ?? 'checking';
                $balance = (string) ($data['opening_balance'] ?? '0.00');
                $institution = $data['institution'] ?? null;

                $account = Account::create([
                    'user_id' => $user->id,
                    'name' => $name,
                    'type' => $accType,
                    'currency' => 'DOP',
                    'institution' => $institution,
                    'is_active' => true,
                ]);

                if ((float) $balance > 0) {
                    FinancialTransaction::create([
                        'user_id' => $user->id,
                        'account_id' => $account->id,
                        'description' => 'Saldo de apertura',
                        'amount' => $balance,
                        'occurred_at' => now()->toDateString(),
                        'kind' => 'opening_balance',
                    ]);
                }

                return [
                    'type' => 'account',
                    'title' => 'Cuenta creada',
                    'detail' => sprintf('%s · RD$ %s', $name, number_format((float) $balance, 2)),
                ];
            }
        } catch (\Throwable $e) {
            Log::error('Error executing AI action.', ['type' => $type, 'error' => $e->getMessage()]);
        }

        return null;
    }
}
