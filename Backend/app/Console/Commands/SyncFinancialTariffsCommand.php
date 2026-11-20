<?php

namespace App\Console\Commands;

use App\Models\FinancialInstitution;
use App\Services\FinancialTariffSyncService;
use Illuminate\Console\Command;

class SyncFinancialTariffsCommand extends Command
{
    protected $signature = 'finance:sync-tariffs {--institution_id= : ID de la institución financiera opcional}';
    protected $description = 'Sincroniza y verifica las tarifas bancarias oficiales de la Superintendencia de Bancos (SB-RD)';

    public function handle(FinancialTariffSyncService $syncService): int
    {
        $this->info('Iniciando comprobación periódica de tarifas bancarias oficiales...');

        $institutionId = $this->option('institution_id');
        $query = FinancialInstitution::query()->where('is_active', true);

        if ($institutionId) {
            $query->where('id', $institutionId);
        }

        $institutions = $query->get();

        if ($institutions->isEmpty()) {
            $this->warn('No se encontraron instituciones bancarias activas para sincronizar.');
            return Command::SUCCESS;
        }

        $configuredCount = $institutions
            ->filter(fn (FinancialInstitution $institution) => $institution->products()
                ->where('is_active', true)
                ->whereNotNull('official_source')
                ->exists())
            ->count();

        if ($configuredCount === 0) {
            $this->warn('No hay conectores ni fuentes oficiales configuradas. No se realizará una sincronización ficticia.');
            return Command::SUCCESS;
        }

        $this->output->progressStart($institutions->count());

        foreach ($institutions as $institution) {
            try {
                $log = $syncService->syncInstitutionTariffs($institution, []);
                $this->output->progressAdvance();
            } catch (\Throwable $e) {
                $this->error("\nError al sincronizar {$institution->name}: {$e->getMessage()}");
            }
        }

        $this->output->progressFinish();
        $this->info('Sincronización de tarifas bancarias finalizada exitosamente.');

        return Command::SUCCESS;
    }
}
