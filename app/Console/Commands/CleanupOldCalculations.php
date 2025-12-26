<?php

namespace App\Console\Commands;

use App\Services\CaseBindingService;
use Illuminate\Console\Command;

/**
 * Команда для очистки старых расчетов
 * 
 * Должна запускаться по расписанию (например, раз в сутки)
 * через Laravel Scheduler.
 */
class CleanupOldCalculations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'calculations:cleanup
                            {--dry-run : Показать что будет удалено без фактического удаления}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Очистка старых расчетов с истекшим Grace Period';

    public function __construct(
        private readonly CaseBindingService $bindingService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Начинаем очистку старых расчетов...');

        $deletedCount = $this->bindingService->cleanupOldCalculations();

        if ($deletedCount > 0) {
            $this->info("✓ Удалено расчетов: {$deletedCount}");
        } else {
            $this->info('✓ Нет расчетов для удаления');
        }

        return Command::SUCCESS;
    }
}
