<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfilePicturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'status' => 'active']);
    }

    private function picture(): UploadedFile
    {
        // Real tiny PNG, without requiring GD on the application's server.
        return UploadedFile::fake()->createWithContent('picture.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/q1sAAAAASUVORK5CYII='));
    }

    public function test_doctor_and_front_desk_can_upload_view_replace_and_remove_their_own_pictures(): void
    {
        foreach (['doctor', 'front_desk'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user, 'sanctum');
            $url = $this->post('/api/me/profile-picture', ['picture' => $this->picture()], ['Accept' => 'application/json'])
                ->assertOk()->json('profilePictureUrl');
            $path = $user->fresh()->profile_picture_path;
            $this->assertStringStartsWith('profile-pictures/', $path);
            Storage::disk('local')->assertExists($path);
            $this->get('/api'.$url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->actingAs($user->fresh(), 'sanctum');
            $this->getJson('/api/user')->assertOk()->assertJsonPath('user.profilePictureUrl', $url)->assertJsonMissingPath('user.profile_picture_path');
            $this->getJson('/api/me/staff-profile')->assertOk()->assertJsonPath('data.profilePictureUrl', $url)->assertJsonMissingPath('data.profile_picture_path');

            $newUrl = $this->post('/api/me/profile-picture', ['picture' => $this->picture()], ['Accept' => 'application/json'])->assertOk()->json('profilePictureUrl');
            $newPath = $user->fresh()->profile_picture_path;
            $this->assertNotSame($url, $newUrl);
            Storage::disk('local')->assertMissing($path);
            Storage::disk('local')->assertExists($newPath);
            $this->get('/api'.$url)->assertNotFound();
            $this->get('/api'.$newUrl)->assertOk();
            $this->deleteJson('/api/me/profile-picture')->assertOk()->assertJsonPath('profilePictureUrl', null);
            $this->assertNull($user->fresh()->profile_picture_path);
            Storage::disk('local')->assertMissing($newPath);
            $this->get('/api'.$newUrl)->assertNotFound();
            $this->deleteJson('/api/me/profile-picture')->assertOk();
            $this->assertSame(2, ActivityLog::where('user_id', $user->id)->where('action', 'Updated own profile picture')->count());
            $this->assertSame(1, ActivityLog::where('user_id', $user->id)->where('action', 'Removed own profile picture')->count());
        }
    }

    public function test_invalid_and_oversized_uploads_preserve_the_existing_picture(): void
    {
        $user = $this->user('doctor');
        $this->actingAs($user, 'sanctum');
        $this->post('/api/me/profile-picture', ['picture' => $this->picture()], ['Accept' => 'application/json'])->assertOk();
        $path = $user->fresh()->profile_picture_path;
        foreach ([
            UploadedFile::fake()->createWithContent('script.jpg', '<?php echo "unsafe";'),
            UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            $this->picture()->size(2049),
        ] as $file) {
            $this->post('/api/me/profile-picture', ['picture' => $file], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('picture');
            $this->assertSame($path, $user->fresh()->profile_picture_path);
        }
        $this->postJson('/api/me/profile-picture', [])->assertUnprocessable()->assertJsonValidationErrors('picture');
        Storage::disk('local')->assertExists($path);
        $this->assertCount(1, Storage::disk('local')->allFiles('profile-pictures'));
    }

    public function test_uploads_are_self_only_and_reading_requires_authenticated_staff(): void
    {
        $owner = $this->user('doctor');
        $this->actingAs($owner, 'sanctum');
        $url = $this->post('/api/me/profile-picture', ['picture' => $this->picture()], ['Accept' => 'application/json'])->assertOk()->json('profilePictureUrl');
        $path = $owner->fresh()->profile_picture_path;
        $frontDesk = $this->user('front_desk');
        $this->actingAs($frontDesk, 'sanctum');
        $this->post('/api/me/profile-picture', ['picture' => $this->picture(), 'user_id' => $owner->id], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->deleteJson('/api/me/profile-picture', ['user_id' => $owner->id])->assertUnprocessable();
        $this->postJson('/api/users/'.$owner->id.'/profile-picture', [])->assertNotFound();
        $this->assertSame($path, $owner->fresh()->profile_picture_path);
        $this->assertNull($frontDesk->fresh()->profile_picture_path);
        $this->get('/api'.$url)->assertOk(); // Staff can see directory avatars.
        foreach (['nurse', 'admin'] as $role) {
            $this->actingAs($this->user($role), 'sanctum');
            $this->post('/api/me/profile-picture', ['picture' => $this->picture()], ['Accept' => 'application/json'])->assertForbidden();
            $this->deleteJson('/api/me/profile-picture')->assertForbidden();
        }
        $studentRole = Role::firstOrCreate(['name' => 'student'], ['description' => 'Student']);
        $this->actingAs(User::factory()->create(['role_id' => $studentRole->id]), 'sanctum');
        $this->getJson('/api'.$url)->assertForbidden();
        Storage::disk('local')->assertExists($path);
    }

    public function test_picture_endpoints_require_sign_in(): void
    {
        $this->postJson('/api/me/profile-picture', [])->assertUnauthorized();
        $this->deleteJson('/api/me/profile-picture')->assertUnauthorized();
        $this->getJson('/api/profile-pictures/1?v=unknown')->assertUnauthorized();
    }
}
