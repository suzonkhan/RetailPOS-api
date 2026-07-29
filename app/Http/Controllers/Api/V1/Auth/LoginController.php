<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AuthMeResource;
use App\Models\User;
use App\Services\Auth\PinLockoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __construct(
        private readonly PinLockoutService $pinLockout,
    ) {}

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('mobile', $request->validated('mobile'))
            ->first();

        if ($user === null) {
            throw ValidationException::withMessages([
                'mobile' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($this->pinLockout->isLocked($user)) {
            throw ValidationException::withMessages([
                'mobile' => [
                    sprintf(
                        'Account is locked. Try again after %s.',
                        $this->pinLockout->lockedUntil($user)?->toDateTimeString() ?? 'later'
                    ),
                ],
            ]);
        }

        if (! Hash::check($request->validated('pin'), $user->pin_hash)) {
            $this->pinLockout->recordFailedAttempt($user);

            throw ValidationException::withMessages([
                'mobile' => ['The provided credentials are incorrect.'],
            ]);
        }

        $this->pinLockout->clearLockout($user);

        $user->load(['tenant.plan', 'tenant.store', 'roles']);

        $deviceName = $request->validated('device_name') ?? 'auth';
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            ...AuthMeResource::make($user)->resolve(),
        ]);
    }
}
