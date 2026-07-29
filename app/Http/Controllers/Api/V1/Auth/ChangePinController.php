<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePinRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ChangePinController extends Controller
{
    public function __invoke(ChangePinRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->validated('current_pin'), $user->pin_hash)) {
            throw ValidationException::withMessages([
                'current_pin' => ['The current PIN is incorrect.'],
            ]);
        }

        $user->update([
            'pin_hash' => $request->validated('new_pin'),
        ]);

        return response()->json([
            'message' => 'PIN updated successfully.',
        ]);
    }
}
