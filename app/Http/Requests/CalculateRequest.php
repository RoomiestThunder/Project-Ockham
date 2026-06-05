<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request for validating calculation input data
 */
class CalculateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // TODO: add authorization (access rights check for the case)
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Calculation type
            'is_interactive' => ['required', 'boolean'],

            // Iterations (for Monte Carlo)
            'iterations' => ['nullable', 'integer', 'min:100', 'max:10000'],

            // Calculation parameters
            'engineer_params' => ['required', 'array'],
            'engineer_params.initial_reserves' => ['required', 'numeric', 'min:0'],
            'engineer_params.well_count' => ['required', 'integer', 'min:1'],
            'engineer_params.productivity_index' => ['required', 'numeric', 'min:0'],
            'engineer_params.decline_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            
            'production_params' => ['required', 'array'],
            'production_params.project_lifetime' => ['required', 'integer', 'min:1', 'max:50'],
            
            'sales_params' => ['required', 'array'],
            'sales_params.oil_price' => ['required', 'numeric', 'min:0'],
            
            'capex_params' => ['required', 'array'],
            'capex_params.cost_per_well' => ['required', 'numeric', 'min:0'],
            'capex_params.facilities_cost' => ['required', 'numeric', 'min:0'],
            
            'opex_params' => ['required', 'array'],
            'opex_params.fixed_opex' => ['required', 'numeric', 'min:0'],
            'opex_params.variable_opex_rate' => ['required', 'numeric', 'min:0'],
            
            'tax_params' => ['required', 'array'],
            'tax_params.tax_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'tax_params.mining_tax_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            
            // Metadata (optional)
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'is_interactive.required' => 'The calculation type is required',
            'engineer_params.required' => 'Engineering parameters are required',
            'iterations.min' => 'Minimum number of iterations for Monte Carlo: 100',
            'iterations.max' => 'Maximum number of iterations: 10000',
        ];
    }
}
