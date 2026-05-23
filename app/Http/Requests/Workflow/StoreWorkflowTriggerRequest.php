<?php

declare(strict_types=1);

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreWorkflowTriggerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:manual,scheduled,webhook'],
            'webhook_secret' => ['required_if:type,webhook', 'nullable', 'string', 'max:255'],
            'cron_expression' => ['required_if:type,scheduled', 'nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'enabled' => ['nullable', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('type') !== 'scheduled' || $validator->errors()->isNotEmpty()) {
                    return;
                }

                $cron = (string) $this->input('cron_expression');
                if (! $this->isValidCron($cron)) {
                    $validator->errors()->add('cron_expression', 'Invalid cron expression.');
                }
            },
        ];
    }

    private function isValidCron(string $cron): bool
    {
        $parts = preg_split('/\s+/', trim($cron));
        if (count($parts) !== 5) {
            return false;
        }

        foreach ($parts as $part) {
            if (! preg_match('/^(\*|[0-9,-\/]+)$/', $part)) {
                return false;
            }
        }

        return true;
    }
}
