<?php

namespace App\Jobs;

use App\DTOs\CalculationInputDTO;
use App\Models\Calculation;
use App\Services\CalculatorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Job для асинхронного выполнения Monte Carlo расчетов
 * 
 * Особенности:
 * - Выполняется через Laravel Queue (Redis/Database)
 * - Поддерживает прогресс-трекинг через Redis
 * - Транслирует прогресс через WebSockets (broadcasting)
 * - Обрабатывает ошибки и retry-логику
 * - Записывает результаты в БД
 */
class RunMonteCarloCalculation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Количество попыток выполнения при ошибке
     */
    public int $tries = 3;

    /**
     * Таймаут выполнения (секунды)
     */
    public int $timeout = 3600; // 1 час для больших Monte Carlo

    /**
     * Задержка между retry (секунды)
     */
    public int $backoff = 60;

    public function __construct(
        private readonly int $calculationId,
        private readonly CalculationInputDTO $input,
    ) {
    }

    /**
     * Выполнить job
     */
    public function handle(CalculatorService $calculator): void
    {
        $calculation = Calculation::findOrFail($this->calculationId);

        try {
            Log::info('Starting Monte Carlo calculation job', [
                'calculation_id' => $this->calculationId,
                'case_id' => $this->input->caseId,
                'iterations' => $this->input->iterations,
            ]);

            // Обновляем статус
            $calculation->update([
                'status' => 'processing',
                'started_at' => now(),
            ]);

            // Отправляем начальный прогресс
            $this->updateProgress($calculation, 0, 'Инициализация расчета');

            // Выполняем расчет с коллбэком прогресса
            $result = $calculator->calculate(
                $this->input,
                fn($percentage, $message) => $this->updateProgress($calculation, $percentage, $message)
            );

            // Сохраняем результаты в БД
            $this->saveResults($calculation, $result);

            Log::info('Monte Carlo calculation completed', [
                'calculation_id' => $this->calculationId,
                'execution_time' => $result->executionTimeSeconds,
                'iterations' => $result->iterationsCompleted,
            ]);

        } catch (Throwable $e) {
            $this->handleFailure($calculation, $e);
            throw $e; // Пробрасываем для retry механизма
        }
    }

    /**
     * Обновить прогресс в Redis и WebSocket
     */
    private function updateProgress(Calculation $calculation, int $percentage, string $message): void
    {
        // Обновляем прогресс в Redis (для быстрого доступа)
        $progressKey = "calc:progress:{$calculation->id}";
        $progressData = [
            'calculation_id' => $calculation->id,
            'case_id' => $this->input->caseId,
            'percentage' => $percentage,
            'message' => $message,
            'timestamp' => now()->timestamp,
        ];

        Redis::setex($progressKey, 300, json_encode($progressData)); // TTL 5 минут

        // Обновляем процент в БД (каждые 5%)
        if ($percentage % 5 === 0 || $percentage === 100) {
            $calculation->update([
                'progress_percentage' => $percentage,
                'progress_message' => $message,
            ]);
        }

        // Транслируем прогресс через WebSocket (Laravel Broadcasting)
        // Предполагается, что у вас настроен Laravel Echo + Pusher/Soketi
        broadcast(new \App\Events\CalculationProgressUpdated(
            calculationId: $calculation->id,
            caseId: $this->input->caseId,
            percentage: $percentage,
            message: $message,
        ))->toOthers();

        Log::debug('Progress updated', [
            'calculation_id' => $calculation->id,
            'percentage' => $percentage,
            'message' => $message,
        ]);
    }

    /**
     * Сохранить результаты расчета в БД
     */
    private function saveResults(Calculation $calculation, $result): void
    {
        DB::transaction(function () use ($calculation, $result) {
            $calculation->update([
                'status' => 'completed',
                'progress_percentage' => 100,
                'progress_message' => 'Расчет завершен',
                'completed_at' => now(),
                'iterations_completed' => $result->iterationsCompleted,
                'execution_time_seconds' => $result->executionTimeSeconds,
                
                // Сохраняем все результаты в JSON-колонки
                'engineer_results' => $result->engineerResults,
                'production_results' => $result->productionResults,
                'sales_results' => $result->salesResults,
                'capex_results' => $result->capexResults,
                'opex_results' => $result->opexResults,
                'tax_results' => $result->taxResults,
                'final_metrics' => $result->finalMetrics,
                'distributions' => $result->distributions,
            ]);

            // Обновляем last_job_id в Case для Smart Binding
            DB::table('cases')
                ->where('id', $this->input->caseId)
                ->update([
                    'last_calculation_id' => $calculation->id,
                    'last_calculation_hash' => $result->hashId,
                    'updated_at' => now(),
                ]);
        });

        // Отправляем финальное событие о завершении
        broadcast(new \App\Events\CalculationCompleted(
            calculationId: $calculation->id,
            caseId: $this->input->caseId,
            results: $result->getKeyMetrics(),
        ))->toOthers();

        // Очищаем прогресс из Redis
        Redis::del("calc:progress:{$calculation->id}");
    }

    /**
     * Обработать ошибку выполнения
     */
    private function handleFailure(Calculation $calculation, Throwable $e): void
    {
        Log::error('Monte Carlo calculation failed', [
            'calculation_id' => $calculation->id,
            'case_id' => $this->input->caseId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        $calculation->update([
            'status' => 'failed',
            'progress_message' => 'Ошибка: ' . $e->getMessage(),
            'failed_at' => now(),
            'error_message' => $e->getMessage(),
            'error_trace' => $e->getTraceAsString(),
        ]);

        // Отправляем событие об ошибке
        broadcast(new \App\Events\CalculationFailed(
            calculationId: $calculation->id,
            caseId: $this->input->caseId,
            error: $e->getMessage(),
        ))->toOthers();

        // Очищаем прогресс из Redis
        Redis::del("calc:progress:{$calculation->id}");
    }

    /**
     * Хук при окончательной неудаче (после всех retry)
     */
    public function failed(Throwable $exception): void
    {
        $calculation = Calculation::find($this->calculationId);
        
        if ($calculation) {
            $calculation->update([
                'status' => 'permanently_failed',
                'progress_message' => 'Расчет не удался после нескольких попыток',
                'failed_at' => now(),
            ]);
        }

        Log::critical('Monte Carlo calculation permanently failed', [
            'calculation_id' => $this->calculationId,
            'exception' => $exception->getMessage(),
        ]);
    }

    /**
     * Получить теги для Laravel Horizon
     */
    public function tags(): array
    {
        return [
            'calculation',
            'monte_carlo',
            "case:{$this->input->caseId}",
            "calc:{$this->calculationId}",
        ];
    }
}
