<?php

namespace App\Console\Commands;

use App\Services\CaseBindingService;
use Illuminate\Console\Command;

/**
 * Command for cleaning up old calculations
 *
 * Should be run on a schedule (e.g. once per day)
 * via Laravel Scheduler.
 */
class CleanupOldCalculations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'calculations:cleanup
                            {--dry-run : Show what would be deleted without actually deleting it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old calculations whose grace period has expired';

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
        $this->info('Starting cleanup of old calculations...');

        $deletedCount = $this->bindingService->cleanupOldCalculations();

        if ($deletedCount > 0) {
            $this->info("✓ Calculations deleted: {$deletedCount}");
        } else {
            $this->info('✓ No calculations to delete');
        }

        return Command::SUCCESS;
    }
}
