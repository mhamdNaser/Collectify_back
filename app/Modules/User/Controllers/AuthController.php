<?php

namespace App\Modules\User\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\User\Resources\UserResource;
use App\Modules\User\Requests\User\RegisterRequest;
use App\Modules\User\Requests\User\LoginRequest;
use App\Modules\User\Requests\User\ChangePasswordRequest;
use App\Modules\User\Requests\User\ForgotPasswordRequest;
use App\Modules\User\Requests\User\ResetPasswordWithCodeRequest;
use App\Modules\User\Repositories\Interfaces\UserRepositoryInterface;
use App\Modules\User\Mail\VerificationCodeMail;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Modules\User\Models\User;
use App\Modules\User\Resources\LoginResource;

class AuthController extends Controller
{
    public function __construct(private UserRepositoryInterface $users) {}

    /**
     * ✅ Register (API)
     */
    public function register(RegisterRequest $request)
    {
        $data = $request->validated();

        // The form has one "full name" field; the users table keeps it split.
        $parts = preg_split('/\s+/', trim($data['name']), 2);

        $user = DB::transaction(function () use ($data, $parts) {
            $user = User::create([
                'first_name' => $parts[0],
                'last_name' => $parts[1] ?? $parts[0],
                'name' => $this->uniqueUsername($data['email']),
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'status' => 1,
            ]);

            $user->assignRole('partner');

            return $user;
        });

        // إنشاء توكن مباشر بعد التسجيل
        $token = $user->createToken('api_token')->plainTextToken;

        return response()->json([
            'message' => 'Registered successfully',
            'user' => new UserResource($user),
            'token' => $token,
        ], 201);
    }

    /**
     * ✅ Login لأي مستخدم (Token-based)
     */
    public function userLogin(LoginRequest $request)
    {
        $credentials = $request->validated();

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // حذف التوكنات القديمة (اختياري)
        $user->tokens()->delete();

        // إنشاء توكن جديد
        $token = $user->createToken('api_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => new UserResource($user),
            'token' => $token,
        ]);
    }

    /**
     * ✅ Login للأدمن فقط (Token-based)
     */
    public function login(LoginRequest $request)
    {
        $credentials = $request->validated();

        $user = User::where('email', $credentials['email'])
            ->with('roles')
            ->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials'
            ], 401);
        }

        $allowedRoles = ['admin'];
        // $allowedRoles = ['admin', 'partner'];

        if (!$user->hasAnyRole($allowedRoles)) {
            return response()->json([
                'message' => 'You are not authorized to access this panel'
            ], 403);
        }

        $user->tokens()->delete();

        $tokenAbilities = $user->roles
            ->pluck('name')
            ->filter(fn($role) => in_array($role, $allowedRoles))
            ->map(fn($role) => 'role:' . $role)
            ->values()
            ->toArray();

        $token = $user->createToken('panel_token', $tokenAbilities)->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => new LoginResource($user),
            'token' => $token,
        ]);
    }

    /**
     * ✅ Logout (Token-based)
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    /**
     * ✅ Get Authenticated User
     */
    public function me(Request $request)
    {
        return new UserResource($request->user());
    }

    /**
     * No current password needed. Super admins change it directly; everyone else
     * confirms with the code sent to their email (sendAccountCode, purpose=password).
     */
    public function changePassword(ChangePasswordRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();

        if (!$user->hasRole('admin')) {
            $this->consumeAccountCode($user, 'password', (string) ($validated['code'] ?? ''));
        }

        $user->password = $validated['new_password'];
        $user->save();

        // Sign out every other session; keep the one that made the change.
        $current = $user->currentAccessToken();
        $user->tokens()
            ->when($current instanceof PersonalAccessToken, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->delete();

        return response()->json([
            'message' => 'Password changed successfully.',
        ]);
    }

    /**
     * Sends a 6-digit code: to the current email (purpose=password) or to the new
     * email (purpose=email) so the owner proves they control it.
     */
    public function sendAccountCode(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'purpose' => ['required', Rule::in(['password', 'email'])],
            'email' => [
                Rule::requiredIf($request->input('purpose') === 'email'),
                'nullable', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ]);

        $purpose = $validated['purpose'];
        $sendTo = $purpose === 'email' ? strtolower((string) $validated['email']) : (string) $user->email;
        $code = (string) random_int(100000, 999999);

        try {
            Mail::to($sendTo)->send(new VerificationCodeMail($code, $purpose));
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Could not send the verification email. Try again later.'], 503);
        }

        Cache::put($this->accountCodeKey($user, $purpose), [
            'hash' => Hash::make($code),
            'email' => $purpose === 'email' ? $sendTo : null,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(15)->getTimestamp(),
        ], now()->addMinutes(15));

        return response()->json([
            'message' => 'Verification code sent to ' . $sendTo . '.',
            'dev_code' => app()->environment('production') ? null : $code,
        ]);
    }

    /**
     * Super admins change their email directly; everyone else needs the code that
     * was sent to the new address.
     */
    public function changeEmail(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user->hasRole('admin');
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'code' => [Rule::requiredIf(!$isAdmin), 'nullable', 'digits:6'],
        ]);
        $email = strtolower((string) $validated['email']);

        if (!$isAdmin) {
            $entry = $this->consumeAccountCode($user, 'email', (string) $validated['code']);

            if ($entry['email'] !== $email) {
                throw ValidationException::withMessages(['email' => 'This is not the email the code was sent to.']);
            }
        }

        $user->email = $email;
        $user->save();

        return response()->json([
            'message' => 'Email changed successfully.',
            'data' => new UserResource($user->fresh()),
        ]);
    }

    private function accountCodeKey(User $user, string $purpose): string
    {
        return "account-code:{$user->id}:{$purpose}";
    }

    /**
     * Checks and burns the code. 5 wrong tries invalidate it.
     *
     * @return array{hash: string, email: ?string, attempts: int, expires_at: int}
     */
    private function consumeAccountCode(User $user, string $purpose, string $code): array
    {
        $key = $this->accountCodeKey($user, $purpose);
        $entry = Cache::get($key);

        if (!is_array($entry)) {
            throw ValidationException::withMessages(['code' => 'The code has expired or was not requested. Send a new code.']);
        }

        if (!Hash::check($code, $entry['hash'])) {
            $entry['attempts']++;

            $entry['attempts'] >= 5
                ? Cache::forget($key)
                : Cache::put($key, $entry, Carbon::createFromTimestamp($entry['expires_at']));

            throw ValidationException::withMessages(['code' => 'Invalid verification code.']);
        }

        Cache::forget($key);

        return $entry;
    }

    public function forgotPassword(ForgotPasswordRequest $request)
    {
        $validated = $request->validated();
        $email = strtolower((string) $validated['email']);

        $user = User::where('email', $email)->first();
        if (!$user) {
            return response()->json([
                'message' => 'If this email exists, a verification code has been sent.',
            ]);
        }

        $code = (string) random_int(100000, 999999);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => Hash::make($code),
                'created_at' => now(),
            ]
        );

        try {
            Mail::to($email)->send(new VerificationCodeMail($code));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'message' => 'Verification code generated successfully.',
            'dev_code' => app()->environment('production') ? null : $code,
        ]);
    }

    public function resetPasswordWithCode(ResetPasswordWithCodeRequest $request)
    {
        $validated = $request->validated();
        $email = strtolower((string) $validated['email']);
        $code = (string) $validated['code'];

        $record = DB::table('password_reset_tokens')->where('email', $email)->first();
        if (!$record) {
            return response()->json([
                'message' => 'Invalid verification code.',
            ], 422);
        }

        // Carbon 3 returns a signed diff, so measure from created_at forwards.
        if (Carbon::parse($record->created_at)->diffInMinutes(now()) > 15) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            return response()->json([
                'message' => 'Verification code expired.',
            ], 422);
        }

        if (!Hash::check($code, $record->token)) {
            return response()->json([
                'message' => 'Invalid verification code.',
                'errors' => [
                    'code' => ['Invalid verification code.'],
                ],
            ], 422);
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }

        $user->password = $validated['new_password'];
        $user->setRememberToken(Str::random(60));
        $user->save();
        $user->tokens()->delete();

        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return response()->json([
            'message' => 'Password reset successfully.',
        ]);
    }

    /**
     * Username (users.name, unique) derived from the email: "naser@test.com" -> "naser",
     * then "naser_2", "naser_3"... when taken.
     */
    private function uniqueUsername(string $email): string
    {
        $base = Str::slug(Str::before($email, '@'), '_') ?: 'user';
        $username = $base;

        for ($i = 2; User::where('name', $username)->exists(); $i++) {
            $username = "{$base}_{$i}";
        }

        return $username;
    }
}
