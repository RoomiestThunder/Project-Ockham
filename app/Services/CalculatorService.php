<?php

namespace App\Services;

use App\DTOs\CalculationInputDTO;
use App\DTOs\CalculationResultDTO;
use Illuminate\Support\Facades\Log;

/**
 * Монолитный сервис расчетов Project Ockham
 * 
 * Реализует последовательный пайплайн расчетов:
 * Engineer → Production → Sales → CAPEX/OPEX → Taxes → Final Metrics
 * 
 * Важно: Логика расчета ИДЕНТИЧНА для Sync и Async режимов.
 * Единственное различие - способ выполнения и персистентность.
 */
class CalculatorService
{
    public function __construct(
        private readonly HashGeneratorService $hashGenerator
    ) {
    }

    /**
     * Выполнить полный цикл расчета
     * 
     * @param CalculationInputDTO $input Входные данные
     * @param callable|null $progressCallback Коллбэк для отслеживания прогресса
     * @return CalculationResultDTO
     */
    public function calculate(CalculationInputDTO $input, ?callable $progressCallback = null): CalculationResultDTO
    {
        $startTime = microtime(true);

        // Генерируем стабильный Hash ID
        $hashId = $this->hashGenerator->generateForCalculation($input);

        Log::info('Starting calculation', [
            'hash_id' => $hashId,
            'case_id' => $input->caseId,
            'type' => $input->calculationType,
        ]);

        // Для Monte Carlo: выполняем итерации
        if ($input->isMonteCarlo()) {
            return $this->calculateMonteCarlo($input, $hashId, $progressCallback);
        }

        // Для Fixed: выполняем один проход
        return $this->calculateFixed($input, $hashId, $progressCallback);
    }

    /**
     * Расчет для фиксированных (детерминированных) параметров
     * 
     * @param CalculationInputDTO $input
     * @param string $hashId
     * @param callable|null $progressCallback
     * @return CalculationResultDTO
     */
    private function calculateFixed(CalculationInputDTO $input, string $hashId, ?callable $progressCallback = null): CalculationResultDTO
    {
        $startTime = microtime(true);

        // Шаг 1: Инженерные расчеты (25%)
        $this->reportProgress($progressCallback, 0, 'Запуск инженерных расчетов');
        $engineerResults = $this->calculateEngineer($input->engineerParams);
        $this->reportProgress($progressCallback, 25, 'Инженерные расчеты завершены');

        // Шаг 2: Расчет добычи (40%)
        $this->reportProgress($progressCallback, 25, 'Расчет добычи');
        $productionResults = $this->calculateProduction($input->productionParams, $engineerResults);
        $this->reportProgress($progressCallback, 40, 'Расчет добычи завершен');

        // Шаг 3: Расчет продаж (55%)
        $this->reportProgress($progressCallback, 40, 'Расчет выручки и продаж');
        $salesResults = $this->calculateSales($input->salesParams, $productionResults);
        $this->reportProgress($progressCallback, 55, 'Расчет продаж завершен');

        // Шаг 4: CAPEX и OPEX (70%)
        $this->reportProgress($progressCallback, 55, 'Расчет капитальных и операционных затрат');
        $capexResults = $this->calculateCAPEX($input->capexParams, $engineerResults);
        $opexResults = $this->calculateOPEX($input->opexParams, $productionResults);
        $this->reportProgress($progressCallback, 70, 'Расчет затрат завершен');

        // Шаг 5: Налоги (85%)
        $this->reportProgress($progressCallback, 70, 'Расчет налогов');
        $taxResults = $this->calculateTaxes($input->taxParams, $salesResults, $capexResults, $opexResults);
        $this->reportProgress($progressCallback, 85, 'Расчет налогов завершен');

        // Шаг 6: Финальные метрики (NPV, IRR, PI) (100%)
        $this->reportProgress($progressCallback, 85, 'Расчет финальных метрик');
        $finalMetrics = $this->calculateFinalMetrics($salesResults, $capexResults, $opexResults, $taxResults);
        $this->reportProgress($progressCallback, 100, 'Расчет завершен');

        $executionTime = microtime(true) - $startTime;

        return new CalculationResultDTO(
            hashId: $hashId,
            engineerResults: $engineerResults,
            productionResults: $productionResults,
            salesResults: $salesResults,
            capexResults: $capexResults,
            opexResults: $opexResults,
            taxResults: $taxResults,
            finalMetrics: $finalMetrics,
            distributions: null,
            iterationsCompleted: 1,
            executionTimeSeconds: $executionTime,
        );
    }

    /**
     * Расчет методом Monte Carlo (вероятностный анализ)
     * 
     * @param CalculationInputDTO $input
     * @param string $hashId
     * @param callable|null $progressCallback
     * @return CalculationResultDTO
     */
    private function calculateMonteCarlo(CalculationInputDTO $input, string $hashId, ?callable $progressCallback = null): CalculationResultDTO
    {
        $startTime = microtime(true);
        $iterations = $input->iterations ?? 1000;
        
        // Массивы для накопления результатов итераций
        $allNPV = [];
        $allIRR = [];
        $allPI = [];
        $allPayback = [];

        for ($i = 1; $i <= $iterations; $i++) {
            // Применяем вероятностные распределения к входным параметрам
            $stochasticInput = $this->applyStochasticDistributions($input);

            // Выполняем один проход расчета
            $result = $this->calculateFixed($stochasticInput, $hashId, null);

            // Собираем ключевые метрики
            $allNPV[] = $result->finalMetrics['npv'] ?? 0;
            $allIRR[] = $result->finalMetrics['irr'] ?? 0;
            $allPI[] = $result->finalMetrics['pi'] ?? 0;
            $allPayback[] = $result->finalMetrics['payback_period'] ?? 0;

            // Отчет о прогрессе каждые 5%
            if ($i % max(1, intdiv($iterations, 20)) === 0 || $i === $iterations) {
                $progress = intdiv($i * 100, $iterations);
                $this->reportProgress($progressCallback, $progress, "Завершено итераций: {$i}/{$iterations}");
            }
        }

        // Расчет статистики распределений
        $distributions = [
            'npv' => $this->calculateDistributionStats($allNPV),
            'irr' => $this->calculateDistributionStats($allIRR),
            'pi' => $this->calculateDistributionStats($allPI),
            'payback_period' => $this->calculateDistributionStats($allPayback),
        ];

        // Финальные метрики - среднее значение
        $finalMetrics = [
            'npv' => $distributions['npv']['mean'],
            'irr' => $distributions['irr']['mean'],
            'pi' => $distributions['pi']['mean'],
            'payback_period' => $distributions['payback_period']['mean'],
        ];

        $executionTime = microtime(true) - $startTime;

        return new CalculationResultDTO(
            hashId: $hashId,
            engineerResults: [], // Для Monte Carlo не храним промежуточные результаты каждой итерации
            productionResults: [],
            salesResults: [],
            capexResults: [],
            opexResults: [],
            taxResults: [],
            finalMetrics: $finalMetrics,
            distributions: $distributions,
            iterationsCompleted: $iterations,
            executionTimeSeconds: $executionTime,
        );
    }

    // ==================== PIPELINE CALCULATION METHODS ====================

    /**
     * Инженерные расчеты
     */
    private function calculateEngineer(array $params): array
    {
        // TODO: Реализовать вашу инженерную логику
        // Пример: расчет запасов, профиля добычи, технологических параметров
        
        return [
            'reserves' => $params['initial_reserves'] ?? 0,
            'well_count' => $params['well_count'] ?? 0,
            'productivity_index' => $params['productivity_index'] ?? 1.0,
            'decline_rate' => $params['decline_rate'] ?? 0.1,
            // ... другие инженерные результаты
        ];
    }

    /**
     * Расчет добычи
     */
    private function calculateProduction(array $params, array $engineerResults): array
    {
        // TODO: Реализовать расчет профиля добычи
        // Пример: применение кривой падения добычи (exponential/hyperbolic decline)
        
        $years = $params['project_lifetime'] ?? 20;
        $initialProduction = $engineerResults['reserves'] * 0.1; // Упрощенно
        $declineRate = $engineerResults['decline_rate'];
        
        $productionProfile = [];
        for ($year = 1; $year <= $years; $year++) {
            $productionProfile[$year] = $initialProduction * exp(-$declineRate * ($year - 1));
        }
        
        return [
            'production_profile' => $productionProfile,
            'cumulative_production' => array_sum($productionProfile),
            'peak_production' => max($productionProfile),
        ];
    }

    /**
     * Расчет продаж и выручки
     */
    private function calculateSales(array $params, array $productionResults): array
    {
        // TODO: Реализовать расчет выручки
        // Пример: production * price с учетом маркетинговых скидок
        
        $price = $params['oil_price'] ?? 70; // USD/bbl
        $productionProfile = $productionResults['production_profile'];
        
        $revenueProfile = [];
        $totalRevenue = 0;
        
        foreach ($productionProfile as $year => $production) {
            $revenue = $production * $price;
            $revenueProfile[$year] = $revenue;
            $totalRevenue += $revenue;
        }
        
        return [
            'revenue_profile' => $revenueProfile,
            'total_revenue' => $totalRevenue,
            'average_annual_revenue' => $totalRevenue / count($productionProfile),
        ];
    }

    /**
     * Расчет CAPEX (капитальные затраты)
     */
    private function calculateCAPEX(array $params, array $engineerResults): array
    {
        // TODO: Реализовать расчет CAPEX
        // Пример: затраты на бурение, обустройство, инфраструктуру
        
        $wellCount = $engineerResults['well_count'];
        $costPerWell = $params['cost_per_well'] ?? 5_000_000;
        
        return [
            'drilling_capex' => $wellCount * $costPerWell,
            'facilities_capex' => $params['facilities_cost'] ?? 10_000_000,
            'total_capex' => ($wellCount * $costPerWell) + ($params['facilities_cost'] ?? 10_000_000),
        ];
    }

    /**
     * Расчет OPEX (операционные затраты)
     */
    private function calculateOPEX(array $params, array $productionResults): array
    {
        // TODO: Реализовать расчет OPEX
        // Пример: фиксированные и переменные затраты
        
        $productionProfile = $productionResults['production_profile'];
        $fixedOpex = $params['fixed_opex'] ?? 1_000_000;
        $variableOpexRate = $params['variable_opex_rate'] ?? 10; // USD/bbl
        
        $opexProfile = [];
        $totalOpex = 0;
        
        foreach ($productionProfile as $year => $production) {
            $opex = $fixedOpex + ($production * $variableOpexRate);
            $opexProfile[$year] = $opex;
            $totalOpex += $opex;
        }
        
        return [
            'opex_profile' => $opexProfile,
            'total_opex' => $totalOpex,
        ];
    }

    /**
     * Расчет налогов
     */
    private function calculateTaxes(array $params, array $salesResults, array $capexResults, array $opexResults): array
    {
        // TODO: Реализовать налоговую модель
        // Пример: НДПИ, налог на прибыль, экспортная пошлина
        
        $revenueProfile = $salesResults['revenue_profile'];
        $opexProfile = $opexResults['opex_profile'];
        
        $taxRate = $params['tax_rate'] ?? 0.20; // 20% налог на прибыль
        $miningTaxRate = $params['mining_tax_rate'] ?? 0.10; // 10% НДПИ
        
        $taxProfile = [];
        $totalTax = 0;
        
        foreach ($revenueProfile as $year => $revenue) {
            $opex = $opexProfile[$year] ?? 0;
            $profit = $revenue - $opex;
            
            $incomeTax = max(0, $profit * $taxRate);
            $miningTax = $revenue * $miningTaxRate;
            
            $totalYearTax = $incomeTax + $miningTax;
            $taxProfile[$year] = $totalYearTax;
            $totalTax += $totalYearTax;
        }
        
        return [
            'tax_profile' => $taxProfile,
            'total_tax' => $totalTax,
        ];
    }

    /**
     * Расчет финальных метрик (NPV, IRR, PI)
     */
    private function calculateFinalMetrics(array $salesResults, array $capexResults, array $opexResults, array $taxResults): array
    {
        // TODO: Реализовать расчет финансовых метрик
        // Пример: NPV с дисконтированием, IRR методом Ньютона
        
        $discountRate = 0.10; // 10%
        $revenueProfile = $salesResults['revenue_profile'];
        $opexProfile = $opexResults['opex_profile'];
        $taxProfile = $taxResults['tax_profile'];
        $totalCapex = $capexResults['total_capex'];
        
        $npv = -$totalCapex; // Начальные инвестиции
        $cashFlows = [-$totalCapex]; // Для расчета IRR
        
        foreach ($revenueProfile as $year => $revenue) {
            $opex = $opexProfile[$year] ?? 0;
            $tax = $taxProfile[$year] ?? 0;
            $cashFlow = $revenue - $opex - $tax;
            
            $npv += $cashFlow / pow(1 + $discountRate, $year);
            $cashFlows[$year] = $cashFlow;
        }
        
        $irr = $this->calculateIRR($cashFlows);
        $pi = ($npv + $totalCapex) / $totalCapex; // Profitability Index
        $paybackPeriod = $this->calculatePaybackPeriod($cashFlows);
        
        return [
            'npv' => $npv,
            'irr' => $irr,
            'pi' => $pi,
            'payback_period' => $paybackPeriod,
            'discount_rate' => $discountRate,
        ];
    }

    // ==================== UTILITY METHODS ====================

    /**
     * Применить стохастические распределения к параметрам (Monte Carlo)
     */
    private function applyStochasticDistributions(CalculationInputDTO $input): CalculationInputDTO
    {
        // TODO: Применить вероятностные распределения к входным параметрам
        // Пример: Normal, Lognormal, Triangular distributions
        
        // Упрощенная реализация: добавляем случайный шум ±10%
        $randomize = function(array $params): array {
            return array_map(function($value) {
                if (is_numeric($value)) {
                    $noise = 1 + (mt_rand(-10, 10) / 100);
                    return $value * $noise;
                }
                return $value;
            }, $params);
        };
        
        return new CalculationInputDTO(
            caseId: $input->caseId,
            calculationType: $input->calculationType,
            engineerParams: $randomize($input->engineerParams),
            productionParams: $randomize($input->productionParams),
            salesParams: $randomize($input->salesParams),
            capexParams: $randomize($input->capexParams),
            opexParams: $randomize($input->opexParams),
            taxParams: $input->taxParams, // Налоги обычно детерминированы
            iterations: $input->iterations,
            metadata: $input->metadata,
        );
    }

    /**
     * Рассчитать статистику распределения
     */
    private function calculateDistributionStats(array $values): array
    {
        sort($values);
        $count = count($values);
        
        $mean = array_sum($values) / $count;
        $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $values)) / $count;
        $stdDev = sqrt($variance);
        
        return [
            'mean' => $mean,
            'median' => $values[intdiv($count, 2)],
            'std_dev' => $stdDev,
            'min' => min($values),
            'max' => max($values),
            'p10' => $values[intdiv($count * 10, 100)],
            'p50' => $values[intdiv($count * 50, 100)],
            'p90' => $values[intdiv($count * 90, 100)],
            'distribution' => $values, // Полное распределение для гистограмм
        ];
    }

    /**
     * Расчет IRR методом Ньютона
     */
    private function calculateIRR(array $cashFlows): float
    {
        // Упрощенная реализация IRR
        // TODO: Реализовать более точный алгоритм (Newton-Raphson)
        
        $guess = 0.1;
        $maxIterations = 100;
        $tolerance = 0.0001;
        
        for ($i = 0; $i < $maxIterations; $i++) {
            $npv = 0;
            $dnpv = 0;
            
            foreach ($cashFlows as $year => $cashFlow) {
                $npv += $cashFlow / pow(1 + $guess, $year);
                $dnpv -= $year * $cashFlow / pow(1 + $guess, $year + 1);
            }
            
            $newGuess = $guess - $npv / $dnpv;
            
            if (abs($newGuess - $guess) < $tolerance) {
                return $newGuess;
            }
            
            $guess = $newGuess;
        }
        
        return $guess;
    }

    /**
     * Расчет срока окупаемости
     */
    private function calculatePaybackPeriod(array $cashFlows): float
    {
        $cumulative = 0;
        
        foreach ($cashFlows as $year => $cashFlow) {
            $cumulative += $cashFlow;
            
            if ($cumulative >= 0) {
                return $year;
            }
        }
        
        return count($cashFlows); // Не окупился за период проекта
    }

    /**
     * Отправить отчет о прогрессе
     */
    private function reportProgress(?callable $progressCallback, int $percentage, string $message): void
    {
        if ($progressCallback !== null) {
            $progressCallback($percentage, $message);
        }
    }
}
