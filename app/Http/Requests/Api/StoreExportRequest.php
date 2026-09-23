<?php

namespace App\Http\Requests\Api;

use App\Enums\ExportReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'report' => ['required', 'string', Rule::enum(ExportReport::class)],
            'format' => ['sometimes', 'string', 'in:csv'],
            'filters' => ['sometimes', 'array'],
            'filters.from' => ['sometimes', 'nullable', 'date'],
            'filters.to' => ['sometimes', 'nullable', 'date', 'after_or_equal:filters.from'],
            'sync' => ['sometimes', 'boolean'],
        ];
    }
}
