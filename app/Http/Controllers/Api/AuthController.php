<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Services\UserLoginGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validated = $request->validate([
            'username' => ['nullable', 'string', 'required_without:user_name'],
            'user_name' => ['nullable', 'string', 'required_without:username'],
            'password' => ['required', 'string'],
        ]);

        $username = trim((string) ($validated['username'] ?? $validated['user_name'] ?? ''));

        $user = User::with([
            'employee.branch:id,name,location',
            'managedBranch:id,name,location',
        ])
            ->where('user_name', $username)
            ->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid username or password.',
            ], 401);
        }

        if (isset($user->is_active) && !$user->is_active) {
            return response()->json([
                'message' => 'Your account is inactive. Please contact admin.',
            ], 403);
        }

        $loginError = UserLoginGuard::apiLoginError($user);

        if ($loginError) {
            return response()->json([
                'message' => $loginError,
            ], 403);
        }

        if (array_key_exists('is_online', $user->getAttributes()) && !$user->is_online) {
            $user->forceFill([
                'is_online' => true,
            ])->save();
        }

        return response()->json([
            'message' => 'Login successful.',
            'data' => $this->accountPayload($user, $request),
        ]);
    }

    public function updateAccount(Request $request)
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'current_password' => ['required', 'string'],
            'user_name' => ['nullable', 'string', 'min:3', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/'],
            'username' => ['nullable', 'string', 'min:3', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/'],
            'password' => ['nullable', 'string', 'min:8', 'max:100', 'confirmed'],
        ]);

        $user = User::with([
            'employee.branch:id,name,location',
            'managedBranch:id,name,location',
        ])->find($validated['user_id']);

        if (! $user || ! Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.',
            ], 401);
        }

        if (isset($user->is_active) && ! $user->is_active) {
            return response()->json([
                'message' => 'Your account is inactive. Please contact admin.',
            ], 403);
        }

        $username = trim((string) ($validated['user_name'] ?? $validated['username'] ?? ''));
        $password = (string) ($validated['password'] ?? '');
        $usernameChanged = $username !== '' && strcasecmp($username, (string) $user->user_name) !== 0;
        $passwordChanged = $password !== '';

        if (! $usernameChanged && ! $passwordChanged) {
            throw ValidationException::withMessages([
                'user_name' => 'Enter a new username or a new password.',
            ]);
        }

        if ($usernameChanged) {
            $taken = User::query()
                ->where('id', '!=', $user->id)
                ->whereRaw('LOWER(user_name) = ?', [strtolower($username)])
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages([
                    'user_name' => 'That username is already used.',
                ]);
            }

            $user->user_name = $username;
        }

        if ($passwordChanged) {
            if (Hash::check($password, $user->password)) {
                throw ValidationException::withMessages([
                    'password' => 'New password must be different from the current password.',
                ]);
            }

            $user->password = $password;
        }

        $user->save();

        return response()->json([
            'message' => 'Account updated. Use the new username and password the next time you sign in.',
            'data' => $this->accountPayload($user->fresh([
                'employee.branch:id,name,location',
                'managedBranch:id,name,location',
            ]), $request),
        ]);
    }

    private function accountPayload(User $user, Request $request): array
    {
        $profilePath = $user->profile_picture
            ?? $user->employee?->profile_picture
            ?? null;

        $employeeProfilePath = $user->employee?->profile_picture ?? null;
        $branch = $user->employee?->branch ?? $user->managedBranch;
        $branchPayload = $this->branchPayload($branch);

        return [
            'id' => $user->id,
            'user_name' => $user->user_name,
            'name' => $user->name ?? $user->user_name,
            'email' => $user->email,
            'role' => $user->role ?? 'staff',
            'is_active' => (bool) ($user->is_active ?? true),
            'is_online' => (bool) ($user->is_online ?? false),
            'profile_picture' => $profilePath,
            'profile_picture_url' => $this->buildPublicImageUrl($profilePath, $request),
            'branch' => $branchPayload,
            'employee' => $user->employee
                ? [
                    'id' => $user->employee->id,
                    'branch_id' => $branch?->id ?? $user->employee->branch_id,
                    'branch_name' => $branch?->name,
                    'branch' => $branchPayload,
                    'profile_picture' => $employeeProfilePath,
                    'profile_picture_url' => $this->buildPublicImageUrl($employeeProfilePath, $request),
                ]
                : null,
        ];
    }

    /**
     * @return array{id: int, name: string, location: string}|null
     */
    private function branchPayload(?Branch $branch): ?array
    {
        if (!$branch) {
            return null;
        }

        return [
            'id' => $branch->id,
            'name' => $branch->name,
            'location' => $branch->location,
        ];
    }

    private function buildPublicImageUrl(?string $path, Request $request): ?string
    {
        if ($path === null) {
            return null;
        }

        $path = trim($path);
        if ($path === '') {
            return null;
        }

        // Already a full URL
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        // Protocol-relative URL
        if (str_starts_with($path, '//')) {
            return $request->getScheme() . ':' . $path;
        }

        // Normalize storage path
        $normalized = ltrim($path, '/');

        if (str_starts_with($normalized, 'public/')) {
            $normalized = substr($normalized, strlen('public/'));
        }

        if (str_starts_with($normalized, 'storage/')) {
            return url($normalized);
        }

        return url('storage/' . $normalized);
    }
}