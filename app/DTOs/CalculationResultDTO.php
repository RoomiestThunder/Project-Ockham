<?php

namespace App\DTOs;

/**
 * DTO для результата расчета
 * 
 * Содержит все рассчитанные метрики и промежуточные данные.
 * Структура одинакова для Sync и Async режимов.
 */
class CalculationResultDTO
{
    public function __construct(
        public readonly string $hashId,           // Уникальный Hash ID расчета
        public readonly array $engineerResults,   // Результаты инженерных расчетов
        public readonly array $productionResults, // Результаты расчета добычи
        public readonly array $salesResults,      // Результаты расчета продаж
        public readonly array $capexResults,      // Результаты CAPEX
        public readonly array $opexResults,       // Результаты OPEX
        public readonly array $taxResults,        // Результаты налоговых расчетов
        public readonly array $finalMetrics,      // Финальные метрики (NPV, IRR, PI и т.д.)
        public readonly ?array $distributions = null, // Для Monte Carlo: распределения результатов
        public readonly ?int $iterationsCompleted = null, // Для Monte Carlo: завершено итераций
        public readonly float $executionTimeSeconds = 0.0, // Время выполнения
    ) {
    }

    /**
     * Конвертировать в массив для сериализации
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'hash_id' => $this->hashId,
            'engineer_results' => $this->engineerResults,
            'production_results' => $this->productionResults,
            'sales_results' => $this->salesResults,
            'capex_results' => $this->capexResults,
            'opex_results' => $this->opexResults,
            'tax_results' => $this->taxResults,
            'final_metrics' => $this->finalMetrics,
            'distributions' => $this->distributions,
            'iterations_completed' => $this->iterationsCompleted,
            'execution_time_seconds' => $this->executionTimeSeconds,
        ];
    }

    /**
     * Получить ключевые метрики для быстрого доступа
     * 
     * @return array
     */
    public function getKeyMetrics(): array
    {
        return [
            'npv' => $this->finalMetrics['npv'] ?? null,
            'irr' => $this->finalMetrics['irr'] ?? null,
            'pi' => $this->finalMetrics['pi'] ?? null,
            'payback_period' => $this->finalMetrics['payback_period'] ?? null,
        ];
    }
}
