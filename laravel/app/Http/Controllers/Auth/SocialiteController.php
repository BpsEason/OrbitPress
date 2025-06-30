<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Laravel\Socialite\Facades\Socialite;
use App\Models\Tenant\User; // Use Tenant User model
use App\Models\System\Tenant as SystemTenant; // Use System Tenant model
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

class SocialiteController extends Controller
{
    /**
     * Redirect the user to the OAuth provider's authentication page.
     *
     * @param string $provider
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function redirectToProvider($provider, Request $request)
    {
        // Store tenant_id in session so it's available in the callback
        $request->session()->put('tenant_id_for_socialite', $request->header('X-Tenant-ID'));
        Log::info("Redirecting to {$provider} for authentication, tenant: " . $request->header('X-Tenant-ID'));
        return Socialite::driver($provider)->stateless()->redirect(); // Use stateless for API
    }

    /**
     * Obtain the user information from the OAuth provider.
     *
     * @param string $provider
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function handleProviderCallback($provider, Request $request)
    {
        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Exception $e) {
            Log::error("Socialite callback failed for {$provider}: " . $e->getMessage());
            return response()->json(['error' => '認證失敗：無法從提供者獲取用戶資料。'], 400);
        }

        $tenantId = $request->session()->pull('tenant_id_for_socialite'); // Retrieve tenant_id from session

        if (!$tenantId) {
            Log::error("Socialite callback missing tenant ID for {$provider}.");
            return response()->json(['error' => '租戶 ID 缺失。'], 400);
        }

        // Initialize tenancy for the correct tenant database
        $tenant = SystemTenant::find($tenantId);
        if (!$tenant) {
            Log::error("Tenant {$tenantId} not found during Socialite callback.");
            return response()->json(['error' => '租戶不存在。'], 404);
        }
        tenancy()->initialize($tenant); // Switch to tenant's database

        try {
            // Find or create user in the tenant's database
            $user = User::firstOrCreate(
                ['email' => $socialUser->getEmail()],
                [
                    'name' => $socialUser->getName() ?: $socialUser->getNickname() ?: 'Social User',
                    'password' => Hash::make(Str::random(24)), // Generate a random password for social login users
                    'email_verified_at' => now(), // Assume verified via social provider
                ]
            );

            // Create a Sanctum token for the user in the current tenant context
            $token = $user->createToken('social-login-token', ['*'], ['tenant_id' => $tenantId])->plainTextToken;

            Log::info("User {$user->email} logged in via {$provider} for tenant {$tenantId}.");

            tenancy()->end(); // End tenancy

            // Redirect back to frontend with the token
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000'); // Configure frontend URL in .env
            return redirect()->away("{$frontendUrl}/{$tenantId}?token={$token}");

        } catch (\Exception $e) {
            Log::error("Failed to process social login for {$provider} and tenant {$tenantId}: " . $e->getMessage());
            tenancy()->end(); // Ensure tenancy is ended on error
            return response()->json(['error' => '處理社群登入失敗。'], 500);
        }
    }
}
