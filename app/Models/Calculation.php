<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Calculation model
 *
 * Represents a single calculation (Fixed or Monte Carlo).
 * Stores all intermediate and final results.
 */
class Calculation extends Model
{
    use HasFactory;

    protected $fillable = [
        'case_id',
        'hash_id',
        'calculation_type',
        'status',
        'input_params',
        'progress_percentage',
        'progress_message',
        'iterations_total',
        'iterations_completed',
        'started_at',
        'completed_at',
        'failed_at',
        'execution_time_seconds',
        'engineer_results',
        'production_results',
        'sales_results',
        'capex_results',
        'opex_results',
        'tax_results',
        'final_metrics',
        'distributions',
        'error_message',
        'error_trace',
        'detach_at',
        'delete_at',
    ];

    protected $casts = [
        'input_params' => 'array',
        'engineer_results' => 'array',
        'production_results' => 'array',
        'sales_results' => 'array',
        'capex_results' => 'array',
        'opex_results' => 'array',
        'tax_results' => 'array',
        'final_metrics' => 'array',
        'distributions' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'detach_at' => 'datetime',
        'delete_at' => 'datetime',
        'execution_time_seconds' => 'float',
        'progress_percentage' => 'integer',
        'iterations_total' => 'integer',
        'iterations_completed' => 'integer',
    ];

    /**
     * Relationship to the case
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(CaseModel::class, 'case_id');
    }

    /**
     * Scope: completed calculations only
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope: active calculations only (not marked for deletion)
     */
    public function scopeActive($query)
    {
        return $query->whereNull('delete_at');
    }

    /**
     * Scope: calculations due for cleanup
     */
    public function scopeForCleanup($query)
    {
        return $query->whereNotNull('delete_at')
            ->where('delete_at', '<=', now());
    }

    /**
     * Check whether the calculation is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check whether the calculation is in progress
     */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Check whether the calculation has failed
     */
    public function isFailed(): bool
    {
        return in_array($this->status, ['failed', 'permanently_failed']);
    }

    /**
     * Get key metrics
     */
    public function getKeyMetrics(): array
    {
        return [
            'npv' => $this->final_metrics['npv'] ?? null,
            'irr' => $this->final_metrics['irr'] ?? null,
            'pi' => $this->final_metrics['pi'] ?? null,
            'payback_period' => $this->final_metrics['payback_period'] ?? null,
        ];
    }
}
