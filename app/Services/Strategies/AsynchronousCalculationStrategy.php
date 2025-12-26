<?php

namespace App\Services\Strategies;

use App\Contracts\CalculationStrategyInterface;
use App\DTOs\CalculationInputDTO;
use App\DTOs\CalculationResultDTO;
use App\Jobs\RunMonteCarloCalculation;
use App\Models\Calculation;
use Illuminate\Support\Facades\Log;

/**
 * Асинхронная стратегия расчета (Monte Carlo Mode)
 * 
 * Используется для вероятностных расчетов (1000+ итераций).
 * - Выполняется через Laravel Queue
 * - Пишет результаты в MySQL
 * - Поддерживает прогресс-трекинг через WebSocket
 * - Возвращает Job ID для отслеживания статуса
 */
class AsynchronousCalculationStrategy implements CalculationStrategyInterface
{
    public function execute(CalculationInputDTO $input, ?callable $progressCallback = null): CalculationResultDTO
    {
        Log::info('Dispatching asynchronous calculation', [
            'case_id' => $input->caseId,
            'iterations' => $input->iterations,
        ]);

        // Создаем запись в БД со статусом "pending"
        $calculation = Calculation::create([
            'case_id' => $input->caseId,
            'hash_id' => app(\App\Services\HashGeneratorService::class)->generateForCalculation($input),
            'calculation_type' => $input->calculationType,
            'status' => 'pending',
            'input_params' => $input->toArray(),
            'iterations_total' => $input->iterations,
            'iterations_completed' => 0,
            'started_at' => now(),
        ]);

        // Dispatch Job в очередь
        RunMonteCarloCalculation::dispatch($calculation->id, $input)
            ->onQueue('calculations') // Специальная очередь для расчетов
            ->delay(now()->addSeconds(1)); // Небольшая задержка для UI

        Log::info('Calculation job dispatched', [
            'calculation_id' => $calculation->id,
            'job_queue' => 'calculations',
        ]);

        // Возвращаем временный результат с метаданными о job'е
        return new CalculationResultDTO(
            hashId: $calculation->hash_id,
            engineerResults: [],
            productionResults: [],
            salesResults: [],
            capexResults: [],
            opexResults: [],
            taxResults: [],
            finalMetrics: [
                'status' => 'pending',
                'calculation_id' => $calculation->id,
                'message' => 'Calculation queued for processing',
            ],
            distributions: null,
            iterationsCompleted: 0,
            executionTimeSeconds: 0.0,
        );
    }

    public function shouldPersist(): bool
    {
        return true; // Асинхронный режим пишет в БД
    }

    public function shouldCache(): bool
    {
        return false; // Асинхронный режим не использует кэш (использует БД)
    }

    public function getName(): string
    {
        return 'asynchronous';
    }
}
