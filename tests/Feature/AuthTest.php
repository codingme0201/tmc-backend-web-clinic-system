<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(array $attributes = []): User
    {
        // The base roles are inserted by the roles migration.
        $adminRole = Role::where('name', 'admin')->firstOrFail();

        return User::factory()->create(array_merge([
            'email' => 'admin@tmc.edu.ph',
            'password' => 'admin123',
            'role_id' => $adminRole->id,
        ], $attributes));
    }

    public function test_login_with_valid_credentials_returns_token_and_user(): void
    {
        $this->createUser();

        $response = $this->postJson('/api/login', [
            'email' => 'admin@tmc.edu.ph',
            'password' => 'admin123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role', 'permissions']])
            ->assertJsonPath('user.email', 'admin@tmc.edu.ph')
            ->assertJsonPath('user.role', 'admin');
    }

    public function test_patient_login_on_web_is_forbidden_with_mobile_only_notice(): void
    {
        $patientRole = Role::firstOrCreate(['name' => 'patient'], ['description' => 'Patient']);
        User::factory()->create([
            'email' => 'patient@tmc.edu.ph',
            'password' => 'password123',
            'role_id' => $patientRole->id,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'patient@tmc.edu.ph',
            'password' => 'password123',
            'client' => 'web',
        ]);

        $response->assertForbidden()
            ->assertJsonPath('code', 'PATIENT_MOBILE_ONLY')
            ->assertJsonPath('role', 'patient');
    }

    public function test_patient_login_on_mobile_succeeds(): void
    {
        $patientRole = Role::firstOrCreate(['name' => 'patient'], ['description' => 'Patient']);
        User::factory()->create([
            'email' => 'patient@tmc.edu.ph',
            'password' => 'password123',
            'role_id' => $patientRole->id,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'patient@tmc.edu.ph',
            'password' => 'password123',
            'client' => 'mobile',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonPath('user.email', 'patient@tmc.edu.ph')
            ->assertJsonPath('user.role', 'patient');
    }

    public function test_clinic_staff_login_on_mobile_is_forbidden_with_web_only_notice(): void
    {
        $doctorRole = Role::firstOrCreate(['name' => 'doctor'], ['description' => 'Doctor']);
        User::factory()->create([
            'email' => 'doctor@tmc.edu.ph',
            'password' => 'doctor123',
            'role_id' => $doctorRole->id,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'doctor@tmc.edu.ph',
            'password' => 'doctor123',
            'client' => 'mobile',
        ]);

        $response->assertForbidden()
            ->assertJsonPath('code', 'CLINIC_STAFF_WEB_ONLY')
            ->assertJsonPath('role', 'doctor');
    }

    public function test_login_response_never_exposes_the_password(): void
    {
        $user = $this->createUser();

        $response = $this->postJson('/api/login', [
            'email' => 'admin@tmc.edu.ph',
            'password' => 'admin123',
        ]);

        $response->assertOk();
        $content = $response->json();

        $this->assertArrayNotHasKey('password', $content['user']);
        $this->assertStringNotContainsString($user->password, json_encode($content));
    }

    public function test_login_with_invalid_credentials_is_rejected(): void
    {
        $this->createUser();

        $this->postJson('/api/login', [
            'email' => 'admin@tmc.edu.ph',
            'password' => 'wrong-password',
        ])->assertUnauthorized();

        $this->postJson('/api/login', [
            'email' => 'nobody@tmc.edu.ph',
            'password' => 'admin123',
        ])->assertUnauthorized();
    }

    public function test_login_requires_valid_input(): void
    {
        $this->postJson('/api/login', [])->assertUnprocessable();

        $this->postJson('/api/login', [
            'email' => 'not-an-email',
            'password' => '',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_current_user_requires_authentication(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_current_user_is_returned_with_valid_token(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@tmc.edu.ph')
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonMissingPath('user.password');
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/logout')
            ->assertOk();

        // The token row is gone from storage.
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Tests share one process, and the sanctum guard caches the resolved
        // user on its instance — force a fresh guard so the next request
        // re-resolves (and rejects) the revoked token, as a real HTTP request
        // would.
        $this->app['auth']->forgetGuards();

        // The same token must no longer authenticate.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();
    }
}
