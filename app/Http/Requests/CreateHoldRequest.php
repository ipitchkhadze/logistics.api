<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'Idempotency-Key' => $this->header('Idempotency-Key'),
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'Idempotency-Key' => ['required', 'uuid'],
        ];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('Idempotency-Key');
    }
}
