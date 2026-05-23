<?php

declare(strict_types=1);

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:draft,active,archived'],
            'change_summary' => ['sometimes', 'nullable', 'string', 'max:255'],
            'definition' => ['sometimes', 'array'],
            'definition.schemaVersion' => ['required_with:definition', 'integer', 'in:1'],
            'definition.name' => ['required_with:definition', 'string', 'max:255'],
            'definition.globalTimeoutMs' => ['required_with:definition', 'integer', 'min:1000', 'max:600000'],
            'definition.steps' => ['required_with:definition', 'array', 'min:1'],
        ];
    }
}
