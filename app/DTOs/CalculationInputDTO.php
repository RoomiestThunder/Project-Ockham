<?php

namespace App\DTOs;

/**
 * DTO для входных данных расчета
 * 
 * Содержит все параметры, необходимые для выполнения расчета.
 * Используется для генерации Hash ID через DataCanonicalizer.
 */
class CalculationInputDTO
{
    public function __construct(
        public readonly int $caseId,
        public readonly string $calculationType, // 'fixed' или 'monte_carlo'
        public readonly array $engineerParams,    // Инженерные параметры
        public readonly array $productionParams,  // Параметры добычи
        public readonly array $salesParams,       // Параметры продаж
        public readonly array $capexParams,       // CAPEX
        public readonly array $opexParams,        // OPEX
        public readonly array $taxParams,         // Налоги
        public readonly ?int $iterations = null,  // Для Monte Carlo: количество итераций
        public readonly ?array $metadata = null,  // Дополнительные метаданные
    ) {
    }

    /**
     * Конвертировать в массив для хэширования
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'case_id' => $this->caseId,
            'calculation_type' => $this->calculationType,
            'engineer_params' => $this->engineerParams,
            'production_params' => $this->productionParams,
            'sales_params' => $this->salesParams,
            'capex_params' => $this->capexParams,
            'opex_params' => $this->opexParams,
            'tax_params' => $this->taxParams,
            'iterations' => $this->iterations,
            // metadata не участвует в хэшировании
        ];
    }

    /**
     * Проверка, является ли расчет интерактивным (синхронным)
     * 
     * @return bool
     */
    public function isInteractive(): bool
    {
        return $this->calculationType === 'fixed';
    }

    /**
     * Проверка, является ли расчет Monte Carlo (асинхронным)
     * 
     * @return bool
     */
    public function isMonteCarlo(): bool
    {
        return $this->calculationType === 'monte_carlo';
    }
}
