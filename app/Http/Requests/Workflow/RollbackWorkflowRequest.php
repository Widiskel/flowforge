<?php

declare(strict_types=1);

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;

class RollbackWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'change_summary' => ['nullable', 'string', 'max:255'],
        ];
    }
}
