<?php

namespace App\Services\TariffConnectors;

use App\Models\FinancialTariffSyncLog;

class TariffAlertService
{
    public function logAlert(int $institutionId, string $message): void
    {
        // Logs alert or failure event
        \Illuminate\Support\Facades\Log::warning("[TariffAlert] Institution ID {$institutionId}: {$message}");
    }
}
