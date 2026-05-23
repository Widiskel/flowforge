<?php

declare(strict_types=1);

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;

class ValidateCronRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cron_expression' => ['required', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $cron = $this->input('cron_expression');
            if (! $this->isValidCron($cron)) {
                $validator->errors()->add('cron_expression', 'Invalid cron expression.');
            }
        });
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
