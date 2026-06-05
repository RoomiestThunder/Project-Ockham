<?php

namespace App\Services;

use App\DTOs\CalculationInputDTO;
use App\DTOs\CalculationResultDTO;
use Illuminate\Support\Facades\Log;

/**
 * Core calculation service for Project Ockham
 *
 * Implements the sequential calculation pipeline:
 * Engineer → Production → Sales → CAPEX/OPEX → Taxes → Final Metrics
 *
 * Note: calculation logic is IDENTICAL for Sync and Async modes.
 * The only difference is execution method and persistence.
 */
class CalculatorService
{
    public function __construct(
        private readonly HashGeneratorService $hashGenerator
    ) {
    }

    /**
     * Execute the full calculation cycle
     *
     * @param CalculationInputDTO $input Input data
     * @param callable|null $progressCallback Callback for tracking progress
     * @return CalculationResultDTO
     */
    public function calculate(CalculationInputDTO $input, ?callable $progressCallback = null): CalculationResultDTO
    {
        $startTime = microtime(true);

        // Generate a stable Hash ID
        $hashId = $this->hashGenerator->generateForCalculation($input);

        Log::info('Starting calculation', [
            'hash_id' => $hashId,
            'case_id' => $input->caseId,
            'type' => $input->calculationType,
        ]);

        // For Monte Carlo: run iterations
        if ($input->isMonteCarlo()) {
            return $this->calculateMonteCarlo($input, $hashId, $progressCallback);
        }

        // For Fixed: run a single pass
        return $this->calculateFixed($input, $hashId, $progressCallback);
    }

    private function calculateFixed(CalculationInputDTO $input, string $hashId, ?callable $progressCallback = null): CalculationResultDTO
    {
        $startTime = microtime(true);

        $this->reportProgress($progressCallback, 0, 'Starting engineering analysis');
        $engineerResults = $this->calculateEngineer($input->engineerParams);
        $this->reportProgress($progressCallback, 25, 'Engineering analysis complete');

        $this->reportProgress($progressCallback, 25, 'Calculating production profile');
        $productionResults = $this->calculateProduction($input->productionParams, $engineerResults);
        $this->reportProgress($progressCallback, 40, 'Production profile complete');

        $this->reportProgress($progressCallback, 40, 'Calculating revenue');
        $salesResults = $this->calculateSales($input->salesParams, $productionResults);
        $this->reportProgress($progressCallback, 55, 'Revenue calculation complete');

        $this->reportProgress($progressCallback, 55, 'Calculating CAPEX and OPEX');
        $capexResults = $this->calculateCAPEX($input->capexParams, $engineerResults);
        $opexResults = $this->calculateOPEX($input->opexParams, $productionResults);
        $this->reportProgress($progressCallback, 70, 'Cost calculation complete');

        $this->reportProgress($progressCallback, 70, 'Calculating taxes');
        $taxResults = $this->calculateTaxes($input->taxParams, $salesResults, $capexResults, $opexResults);
        $this->reportProgress($progressCallback, 85, 'Tax calculation complete');

        $this->reportProgress($progressCallback, 85, 'Calculating financial metrics');
        $finalMetrics = $this->calculateFinalMetrics($salesResults, $capexResults, $opexResults, $taxResults);
        $this->reportProgress($progressCallback, 100, 'Calculation complete');

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
     * Monte Carlo calculation (probabilistic analysis)
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
        
        // Arrays for accumulating iteration results
        $allNPV = [];
        $allIRR = [];
        $allPI = [];
        $allPayback = [];

        for ($i = 1; $i <= $iterations; $i++) {
            // Apply probabilistic distributions to input parameters
            $stochasticInput = $this->applyStochasticDistributions($input);

            // Run a single calculation pass
            $result = $this->calculateFixed($stochasticInput, $hashId, null);

            // Collect key metrics
            $allNPV[] = $result->finalMetrics['npv'] ?? 0;
            $allIRR[] = $result->finalMetrics['irr'] ?? 0;
            $allPI[] = $result->finalMetrics['pi'] ?? 0;
            $allPayback[] = $result->finalMetrics['payback_period'] ?? 0;

            // Report progress every 5%
            if ($i % max(1, intdiv($iterations, 20)) === 0 || $i === $iterations) {
                $progress = intdiv($i * 100, $iterations);
                $this->reportProgress($progressCallback, $progress, "Iterations completed: {$i}/{$iterations}");
            }
        }

        // Calculate distribution statistics
        $distributions = [
            'npv' => $this->calculateDistributionStats($allNPV),
            'irr' => $this->calculateDistributionStats($allIRR),
            'pi' => $this->calculateDistributionStats($allPI),
            'payback_period' => $this->calculateDistributionStats($allPayback),
        ];

        // Final metrics - mean values
        $finalMetrics = [
            'npv' => $distributions['npv']['mean'],
            'irr' => $distributions['irr']['mean'],
            'pi' => $distributions['pi']['mean'],
            'payback_period' => $distributions['payback_period']['mean'],
        ];

        $executionTime = microtime(true) - $startTime;

        return new CalculationResultDTO(
            hashId: $hashId,
            engineerResults: [], // For Monte Carlo we do not store intermediate results of each iteration
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

    private function calculateEngineer(array $params): array
    {
        return [
            'reserves'           => $params['initial_reserves'] ?? 0,
            'well_count'         => $params['well_count'] ?? 0,
            'productivity_index' => $params['productivity_index'] ?? 1.0,
            'decline_rate'       => $params['decline_rate'] ?? 0.1,
        ];
    }

    private function calculateProduction(array $params, array $engineerResults): array
    {
        // Exponential decline curve: Q(t) = Q0 * exp(-D * t)
        
        $years = $params['project_lifetime'] ?? 20;
        $initialProduction = $engineerResults['reserves'] * 0.1;
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

    private function calculateSales(array $params, array $productionResults): array
    {
        
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

    private function calculateCAPEX(array $params, array $engineerResults): array
    {
        
        $wellCount = $engineerResults['well_count'];
        $costPerWell = $params['cost_per_well'] ?? 5_000_000;
        
        return [
            'drilling_capex' => $wellCount * $costPerWell,
            'facilities_capex' => $params['facilities_cost'] ?? 10_000_000,
            'total_capex' => ($wellCount * $costPerWell) + ($params['facilities_cost'] ?? 10_000_000),
        ];
    }

    private function calculateOPEX(array $params, array $productionResults): array
    {
        
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

    private function calculateTaxes(array $params, array $salesResults, array $capexResults, array $opexResults): array
    {
        
        $revenueProfile = $salesResults['revenue_profile'];
        $opexProfile = $opexResults['opex_profile'];
        
        $taxRate = $params['tax_rate'] ?? 0.20;
        $miningTaxRate = $params['mining_tax_rate'] ?? 0.10;
        
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
            'total_tax'   => $totalTax,
        ];
    }

    private function calculateFinalMetrics(array $salesResults, array $capexResults, array $opexResults, array $taxResults): array
    {
        // NPV via discounted cash flow, IRR via Newton-Raphson
        
        $discountRate = 0.10; // 10%
        $revenueProfile = $salesResults['revenue_profile'];
        $opexProfile = $opexResults['opex_profile'];
        $taxProfile = $taxResults['tax_profile'];
        $totalCapex = $capexResults['total_capex'];
        
        $npv = -$totalCapex; // Initial investment
        $cashFlows = [-$totalCapex]; // For IRR calculation
        
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

    private function applyStochasticDistributions(CalculationInputDTO $input): CalculationInputDTO
    {
        // Applies ±10% uniform noise to numeric parameters for Monte Carlo sampling.
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
            taxParams: $input->taxParams,
            iterations: $input->iterations,
            metadata: $input->metadata,
        );
    }

    /**
     * Calculate distribution statistics
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
            'distribution' => $values, // Full distribution for histograms
        ];
    }

    /**
     * Calculate IRR using the Newton-Raphson method
     */
    private function calculateIRR(array $cashFlows): float
    {
        $guess = 0.1;
        $maxIterations = 100;
        $tolerance = 0.0001;

        for ($i = 0; $i < $maxIterations; $i++) {
            $npv = 0.0;
            $dnpv = 0.0;

            foreach ($cashFlows as $year => $cashFlow) {
                $factor = pow(1 + $guess, $year);
                $npv += $cashFlow / $factor;
                $dnpv -= $year * $cashFlow / ($factor * (1 + $guess));
            }

            if (abs($dnpv) < 1e-10) {
                break;
            }

            $newGuess = $guess - $npv / $dnpv;

            if (abs($newGuess - $guess) < $tolerance) {
                return $newGuess;
            }

            $guess = $newGuess;

            if ($guess <= -1.0) {
                $guess = -0.99;
            }
        }

        return $guess;
    }

    /**
     * Calculate payback period
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
        
        return count($cashFlows); // Did not break even within the project lifetime
    }

    /**
     * Report progress
     */
    private function reportProgress(?callable $progressCallback, int $percentage, string $message): void
    {
        if ($progressCallback !== null) {
            $progressCallback($percentage, $message);
        }
    }
}
