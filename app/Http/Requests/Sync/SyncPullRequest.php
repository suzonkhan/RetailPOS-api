<?php

namespace App\Http\Requests\Sync;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class SyncPullRequest extends FormRequest
{
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
        ];
    }
}
