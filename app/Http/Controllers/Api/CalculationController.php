<?php

namespace App\Http\Controllers\Api;

use App\DTOs\CalculationInputDTO;
use App\Http\Requests\CalculateRequest;
use App\Models\Calculation;
use App\Services\CaseBindingService;
use App\Services\Strategies\AsynchronousCalculationStrategy;
use App\Services\Strategies\SynchronousCalculationStrategy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Контроллер для управления расчетами
 * 
 * Реализует переключение между Sync и Async режимами на основе
 * флага is_interactive в запросе.
 */
class CalculationController
{
    public function __construct(
        private readonly SynchronousCalculationStrategy $syncStrategy,
        private readonly AsynchronousCalculationStrategy $asyncStrategy,
        private readonly CaseBindingService $bindingService,
    ) {
    }

    /**
     * Выполнить расчет (Sync или Async в зависимости от типа)
     * 
     * POST /api/cases/{caseId}/calculate
     * 
     * @param CalculateRequest $request
     * @param int $caseId
     * @return JsonResponse
     */
    public function calculate(CalculateRequest $request, int $caseId): JsonResponse
    {
        $validated = $request->validated();

        // Определяем тип расчета по флагу is_interactive
        $isInteractive = $validated['is_interactive'] ?? false;
        $calculationType = $isInteractive ? 'fixed' : 'monte_carlo';

        Log::info('Calculation request received', [
            'case_id' => $caseId,
            'is_interactive' => $isInteractive,
            'calculation_type' => $calculationType,
        ]);

        // Создаем DTO с входными данными
        $input = new CalculationInputDTO(
            caseId: $caseId,
            calculationType: $calculationType,
            engineerParams: $validated['engineer_params'] ?? [],
            productionParams: $validated['production_params'] ?? [],
            salesParams: $validated['sales_params'] ?? [],
            capexParams: $validated['capex_params'] ?? [],
            opexParams: $validated['opex_params'] ?? [],
            taxParams: $validated['tax_params'] ?? [],
            iterations: $validated['iterations'] ?? ($isInteractive ? 1 : 1000),
            metadata: $validated['metadata'] ?? null,
        );

        // Проверяем Smart Binding: есть ли уже такой расчет?
        $existingCalculation = $this->bindingService->findExistingCalculation($input);

        if ($existingCalculation !== null) {
            Log::info('Using existing calculation', [
                'calculation_id' => $existingCalculation->id,
                'hash_id' => $existingCalculation->hash_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Calculation found in database',
                'data' => [
                    'calculation_id' => $existingCalculation->id,
                    'hash_id' => $existingCalculation->hash_id,
                    'status' => $existingCalculation->status,
                    'results' => $existingCalculation->isCompleted() 
                        ? $this->formatResults($existingCalculation) 
                        : null,
                    'from_cache' => false,
                    'from_database' => true,
                ],
            ]);
        }

        // Выбираем стратегию выполнения
        $strategy = $isInteractive ? $this->syncStrategy : $this->asyncStrategy;

        try {
            // Выполняем расчет через выбранную стратегию
            $result = $strategy->execute($input);

            // Если асинхронный режим, привязываем job к Case
            if (!$isInteractive && isset($result->finalMetrics['calculation_id'])) {
                $this->bindingService->bindCalculationToCase(
                    $caseId,
                    $result->finalMetrics['calculation_id']
                );
            }

            return response()->json([
                'success' => true,
                'message' => $isInteractive 
                    ? 'Calculation completed' 
                    : 'Calculation queued for processing',
                'data' => [
                    'hash_id' => $result->hashId,
                    'calculation_id' => $result->finalMetrics['calculation_id'] ?? null,
                    'status' => $isInteractive ? 'completed' : 'pending',
                    'results' => $isInteractive ? $this->formatResults($result) : null,
                    'execution_time' => $result->executionTimeSeconds,
                    'from_cache' => $isInteractive,
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('Calculation failed', [
                'case_id' => $caseId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Calculation failed: ' . $e->getMessage(),
                'error' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    /**
     * Получить статус расчета (для async-режима)
     * 
     * GET /api/calculations/{calculationId}/status
     * 
     * @param int $calculationId
     * @return JsonResponse
     */
    public function status(int $calculationId): JsonResponse
    {
        $calculation = Calculation::findOrFail($calculationId);

        return response()->json([
            'success' => true,
            'data' => [
                'calculation_id' => $calculation->id,
                'case_id' => $calculation->case_id,
                'hash_id' => $calculation->hash_id,
                'status' => $calculation->status,
                'progress_percentage' => $calculation->progress_percentage,
                'progress_message' => $calculation->progress_message,
                'iterations_completed' => $calculation->iterations_completed,
                'iterations_total' => $calculation->iterations_total,
                'started_at' => $calculation->started_at?->toIso8601String(),
                'completed_at' => $calculation->completed_at?->toIso8601String(),
                'execution_time_seconds' => $calculation->execution_time_seconds,
                'results' => $calculation->isCompleted() 
                    ? $this->formatResults($calculation) 
                    : null,
            ],
        ]);
    }

    /**
     * Получить результаты расчета
     * 
     * GET /api/calculations/{calculationId}/results
     * 
     * @param int $calculationId
     * @return JsonResponse
     */
    public function results(int $calculationId): JsonResponse
    {
        $calculation = Calculation::findOrFail($calculationId);

        if (!$calculation->isCompleted()) {
            return response()->json([
                'success' => false,
                'message' => 'Calculation is not completed yet',
                'status' => $calculation->status,
                'progress_percentage' => $calculation->progress_percentage,
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatResults($calculation),
        ]);
    }

    /**
     * Инвалидировать кэш для интерактивного расчета
     * 
     * DELETE /api/cases/{caseId}/cache
     * 
     * @param int $caseId
     * @return JsonResponse
     */
    public function invalidateCache(int $caseId): JsonResponse
    {
        // Инвалидируем все кэши для данного кейса
        // TODO: реализовать логику инвалидации по паттерну ключей

        return response()->json([
            'success' => true,
            'message' => 'Cache invalidated for case',
        ]);
    }

    /**
     * Отменить выполняющийся расчет
     * 
     * POST /api/calculations/{calculationId}/cancel
     * 
     * @param int $calculationId
     * @return JsonResponse
     */
    public function cancel(int $calculationId): JsonResponse
    {
        $calculation = Calculation::findOrFail($calculationId);

        if (!$calculation->isProcessing()) {
            return response()->json([
                'success' => false,
                'message' => 'Calculation is not in processing state',
                'status' => $calculation->status,
            ], 400);
        }

        // TODO: Реализовать отмену job через Laravel Queue
        // Пример: Queue::deleteJob($calculation->job_id);

        $calculation->update([
            'status' => 'failed',
            'progress_message' => 'Cancelled by user',
            'failed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Calculation cancelled',
        ]);
    }

    /**
     * Форматировать результаты для ответа API
     * 
     * @param Calculation|mixed $source
     * @return array
     */
    private function formatResults($source): array
    {
        if ($source instanceof Calculation) {
            return [
                'hash_id' => $source->hash_id,
                'final_metrics' => $source->final_metrics,
                'distributions' => $source->distributions,
                'engineer_results' => $source->engineer_results,
                'production_results' => $source->production_results,
                'sales_results' => $source->sales_results,
                'capex_results' => $source->capex_results,
                'opex_results' => $source->opex_results,
                'tax_results' => $source->tax_results,
            ];
        }

        // Если это CalculationResultDTO
        return [
            'hash_id' => $source->hashId,
            'final_metrics' => $source->finalMetrics,
            'distributions' => $source->distributions,
            'engineer_results' => $source->engineerResults,
            'production_results' => $source->productionResults,
            'sales_results' => $source->salesResults,
            'capex_results' => $source->capexResults,
            'opex_results' => $source->opexResults,
            'tax_results' => $source->taxResults,
        ];
    }
}
