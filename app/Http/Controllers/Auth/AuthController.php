<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\IssueJwtTokenPairAction;
use App\Actions\Auth\RevokeJwtRefreshTokenAction;
use App\Actions\Auth\RotateJwtRefreshTokenAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\LogoutRequest;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Http\Resources\AuthUserResource;
use App\Http\Resources\TokenPairResource;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;

class AuthController extends Controller
{
    public function __construct(
        private readonly IssueJwtTokenPairAction $issueAction,
        private readonly RotateJwtRefreshTokenAction $rotateAction,
        private readonly RevokeJwtRefreshTokenAction $revokeAction,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        /** @var User|null $user */
        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        $tokens = $this->issueAction->execute($user);

        return response()->json([
            'data' => TokenPairResource::make($tokens)->resolve(),
            'user' => AuthUserResource::make($user->load('tenant'))->resolve(),
        ], Response::HTTP_OK);
    }

    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        try {
            $tokens = $this->rotateAction->execute($request->validated()['refresh_token']);
        } catch (AuthenticationException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNAUTHORIZED);
        }

        return TokenPairResource::make($tokens)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    public function logout(LogoutRequest $request): JsonResponse
    {
        $this->revokeAction->execute($request->validated()['refresh_token'] ?? null);

        try {
            Auth::guard('api')->logout();
        } catch (JWTException) {
            // Already-blacklisted or missing access tokens are safe to ignore on logout.
        }

        return response()->json(['message' => 'Logged out.'], Response::HTTP_OK);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return AuthUserResource::make($user->load('tenant'))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
