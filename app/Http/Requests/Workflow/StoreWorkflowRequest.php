<?php

declare(strict_types=1);

namespace App\Http\Requests\Workflow;

use App\Domain\Workflow\Exceptions\InvalidWorkflowDefinitionException;
use App\Domain\Workflow\Services\DagValidator;
use App\Models\Workflow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Workflow::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:draft,active,archived'],
            'change_summary' => ['nullable', 'string', 'max:255'],
            'definition' => ['required', 'array'],
            'definition.schemaVersion' => ['required', 'integer', 'in:1'],
            'definition.name' => ['required', 'string', 'max:255'],
            'definition.globalTimeoutMs' => ['required', 'integer', 'min:1000', 'max:600000'],
            'definition.steps' => ['required', 'array', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                try {
                    app(DagValidator::class)->validate($this->input('definition'));
                } catch (InvalidWorkflowDefinitionException $e) {
                    $validator->errors()->add('definition', $e->getMessage());
                }
            },
        ];
    }
}
