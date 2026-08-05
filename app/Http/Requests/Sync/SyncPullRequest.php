<?php

namespace App\Http\Requests\Sync;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class SyncPullRequest extends FormRequest
{
    public const ALLOWED_ENTITIES = [
        'settings',
        'payment_methods',
        'categories',
        'suppliers',
        'brands',
        'variation_attributes',
        'products',
        'customers',
        'stock',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('pos.use') ?? false;
    }

    public function rules(): array
    {
        return [
            'since' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    try {
                        Carbon::parse($value);
                    } catch (\Throwable) {
                        $fail('The since field must be a valid ISO-8601 timestamp.');
                    }
                },
            ],
            'device_id' => ['required', 'uuid'],
            'include' => ['sometimes', 'nullable', 'string'],
            'include_images' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<string>
     */
    public function includedEntities(): array
    {
        $raw = $this->validated('include');

        if ($raw === null || trim((string) $raw) === '') {
            return self::ALLOWED_ENTITIES;
        }

        $requested = collect(explode(',', (string) $raw))
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $invalid = array_values(array_diff($requested, self::ALLOWED_ENTITIES));

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'include' => ['Invalid entities: '.implode(', ', $invalid).'. Allowed: '.implode(', ', self::ALLOWED_ENTITIES)],
            ]);
        }

        return $requested;
    }

    public function includeImages(): bool
    {
        return (bool) ($this->validated('include_images') ?? false);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('include_images')) {
            $this->merge([
                'include_images' => filter_var($this->input('include_images'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                    ?? $this->input('include_images'),
            ]);
        }
    }
}
