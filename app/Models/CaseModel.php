<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Case model (project/case)
 *
 * Represents a single oil-and-gas modelling project.
 * Holds the relationship to calculations and the most recent active calculation.
 */
class CaseModel extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cases';

    protected $fillable = [
        'name',
        'description',
        'last_calculation_id',
        'last_calculation_hash',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * All calculations for this case
     */
    public function calculations(): HasMany
    {
        return $this->hasMany(Calculation::class, 'case_id');
    }

    /**
     * The most recent active calculation
     */
    public function lastCalculation(): BelongsTo
    {
        return $this->belongsTo(Calculation::class, 'last_calculation_id');
    }

    /**
     * Active calculations (not marked for deletion)
     */
    public function activeCalculations(): HasMany
    {
        return $this->calculations()->whereNull('delete_at');
    }

    /**
     * Completed calculations
     */
    public function completedCalculations(): HasMany
    {
        return $this->calculations()->where('status', 'completed');
    }
}
