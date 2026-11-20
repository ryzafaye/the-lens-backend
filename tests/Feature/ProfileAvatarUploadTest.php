<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\ImageUploadService;
use Cloudinary\Cloudinary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

class ProfileAvatarUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_guest_cannot_upload_avatar(): void
    {
        $response = $this->postJson('/api/profile/avatar', [
            'image' => UploadedFile::fake()->create('test.jpg', 100, 'image/jpeg'),
        ]);

        $response->assertStatus(401);
    }

    public function test_avatar_upload_requires_an_image(): void
    {
        $role = Role::firstOrCreate(['role_name' => 'Writer']);
        $user = User::create([
            'role_id' => $role->id,
            'student_number' => '2023-00001-BN-0',
            'full_name' => 'Test Writer',
            'email' => 'writer@test.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->actingAs($user)->postJson('/api/profile/avatar', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    public function test_avatar_upload_validates_mime_types(): void
    {
        $role = Role::firstOrCreate(['role_name' => 'Writer']);
        $user = User::create([
            'role_id' => $role->id,
            'student_number' => '2023-00002-BN-0',
            'full_name' => 'Test Writer',
            'email' => 'writer2@test.com',
            'password' => Hash::make('password123'),
        ]);

        $pdfFile = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');

        $response = $this->actingAs($user)->postJson('/api/profile/avatar', [
            'image' => $pdfFile,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    public function test_avatar_upload_validates_max_size_3072kb(): void
    {
        $role = Role::firstOrCreate(['role_name' => 'Writer']);
        $user = User::create([
            'role_id' => $role->id,
            'student_number' => '2023-00003-BN-0',
            'full_name' => 'Test Writer',
            'email' => 'writer3@test.com',
            'password' => Hash::make('password123'),
        ]);

        // 4MB file exceeds max:3072
        $largeFile = UploadedFile::fake()->create('large.jpg', 4000, 'image/jpeg');

        $response = $this->actingAs($user)->postJson('/api/profile/avatar', [
            'image' => $largeFile,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    public function test_avatar_uploads_via_service_and_updates_user(): void
    {
        $role = Role::firstOrCreate(['role_name' => 'Writer']);
        $user = User::create([
            'role_id' => $role->id,
            'student_number' => '2023-00004-BN-0',
            'full_name' => 'Test Writer',
            'email' => 'writer4@test.com',
            'password' => Hash::make('password123'),
        ]);

        $mockService = Mockery::mock(ImageUploadService::class);
        $mockService->shouldReceive('uploadAvatar')
            ->once()
            ->andReturn([
                'secure_url' => 'https://res.cloudinary.com/demo/image/upload/v1234567/the-lens/avatars/avatar_123.jpg',
                'public_id' => 'the-lens/avatars/avatar_123',
                'url' => 'https://res.cloudinary.com/demo/image/upload/v1234567/the-lens/avatars/avatar_123.jpg',
            ]);

        $this->app->instance(ImageUploadService::class, $mockService);

        $image = UploadedFile::fake()->create('avatar.png', 500, 'image/png');

        $response = $this->actingAs($user)->postJson('/api/profile/avatar', [
            'image' => $image,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Profile picture uploaded successfully.',
                'profile_picture_url' => 'https://res.cloudinary.com/demo/image/upload/v1234567/the-lens/avatars/avatar_123.jpg',
                'public_id' => 'the-lens/avatars/avatar_123',
            ]);

        $user->refresh();
        $this->assertEquals(
            'https://res.cloudinary.com/demo/image/upload/v1234567/the-lens/avatars/avatar_123.jpg',
            $user->profile_picture_url
        );
        $this->assertEquals(
            'the-lens/avatars/avatar_123',
            $user->profile_picture_public_id
        );
    }

    public function test_avatar_upload_replaces_old_avatar_and_deletes_from_cloudinary(): void
    {
        $role = Role::firstOrCreate(['role_name' => 'Writer']);
        $user = User::create([
            'role_id' => $role->id,
            'student_number' => '2023-00004-REP-0',
            'full_name' => 'Test Writer',
            'email' => 'writer_rep@test.com',
            'password' => Hash::make('password123'),
            'profile_picture_url' => 'https://res.cloudinary.com/demo/image/upload/v111/the-lens/avatars/old_avatar.jpg',
            'profile_picture_public_id' => 'the-lens/avatars/old_avatar',
        ]);

        $mockService = Mockery::mock(ImageUploadService::class);
        $mockService->shouldReceive('uploadAvatar')
            ->once()
            ->andReturn([
                'secure_url' => 'https://res.cloudinary.com/demo/image/upload/v222/the-lens/avatars/new_avatar.jpg',
                'public_id' => 'the-lens/avatars/new_avatar',
                'url' => 'https://res.cloudinary.com/demo/image/upload/v222/the-lens/avatars/new_avatar.jpg',
            ]);

        // Expect deleteImage called for old avatar
        $mockService->shouldReceive('deleteImage')
            ->once()
            ->with('the-lens/avatars/old_avatar', 'https://res.cloudinary.com/demo/image/upload/v111/the-lens/avatars/old_avatar.jpg')
            ->andReturn(true);

        $this->app->instance(ImageUploadService::class, $mockService);

        $image = UploadedFile::fake()->create('new_avatar.png', 500, 'image/png');

        $response = $this->actingAs($user)->postJson('/api/profile/avatar', [
            'image' => $image,
        ]);

        $response->assertStatus(200);

        $user->refresh();
        $this->assertEquals('https://res.cloudinary.com/demo/image/upload/v222/the-lens/avatars/new_avatar.jpg', $user->profile_picture_url);
        $this->assertEquals('the-lens/avatars/new_avatar', $user->profile_picture_public_id);
    }

    public function test_avatar_upload_accepts_avatar_field_name(): void
    {
        $role = Role::firstOrCreate(['role_name' => 'Writer']);
        $user = User::create([
            'role_id' => $role->id,
            'student_number' => '2023-00005-BN-0',
            'full_name' => 'Test Writer',
            'email' => 'writer5@test.com',
            'password' => Hash::make('password123'),
        ]);

        $mockService = Mockery::mock(ImageUploadService::class);
        $mockService->shouldReceive('uploadAvatar')
            ->once()
            ->andReturn([
                'secure_url' => 'https://res.cloudinary.com/demo/image/upload/v1234567/the-lens/avatars/avatar_456.jpg',
                'public_id' => 'the-lens/avatars/avatar_456',
                'url' => 'https://res.cloudinary.com/demo/image/upload/v1234567/the-lens/avatars/avatar_456.jpg',
            ]);

        $this->app->instance(ImageUploadService::class, $mockService);

        $image = UploadedFile::fake()->create('my-pic.webp', 300, 'image/webp');

        $response = $this->actingAs($user)->postJson('/api/profile/avatar', [
            'avatar' => $image,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'profile_picture_url' => 'https://res.cloudinary.com/demo/image/upload/v1234567/the-lens/avatars/avatar_456.jpg',
            ]);

        $user->refresh();
        $this->assertEquals(
            'https://res.cloudinary.com/demo/image/upload/v1234567/the-lens/avatars/avatar_456.jpg',
            $user->profile_picture_url
        );
    }

    public function test_update_info_uploads_avatar_to_cloudinary(): void
    {
        $role = Role::firstOrCreate(['role_name' => 'Writer']);
        $user = User::create([
            'role_id' => $role->id,
            'student_number' => '2023-00006-BN-0',
            'full_name' => 'Original Name',
            'email' => 'writer6@test.com',
            'password' => Hash::make('password123'),
        ]);

        $mockService = Mockery::mock(ImageUploadService::class);
        $mockService->shouldReceive('uploadAvatar')
            ->once()
            ->andReturn([
                'secure_url' => 'https://res.cloudinary.com/demo/image/upload/v9999/the-lens/avatars/profile_update.jpg',
                'public_id' => 'the-lens/avatars/profile_update',
                'url' => 'https://res.cloudinary.com/demo/image/upload/v9999/the-lens/avatars/profile_update.jpg',
            ]);

        $this->app->instance(ImageUploadService::class, $mockService);

        $image = UploadedFile::fake()->create('profile.jpg', 1500, 'image/jpeg');

        $response = $this->actingAs($user)->postJson('/api/profile/update-info', [
            'full_name' => 'Updated Name',
            'student_number' => '2023-00006-BN-0',
            'avatar' => $image,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Profile updated successfully')
            ->assertJsonPath('profile_picture_url', 'https://res.cloudinary.com/demo/image/upload/v9999/the-lens/avatars/profile_update.jpg')
            ->assertJsonPath('user.full_name', 'Updated Name')
            ->assertJsonPath('user.profile_picture_url', 'https://res.cloudinary.com/demo/image/upload/v9999/the-lens/avatars/profile_update.jpg');

        $user->refresh();
        $this->assertEquals('Updated Name', $user->full_name);
        $this->assertEquals('https://res.cloudinary.com/demo/image/upload/v9999/the-lens/avatars/profile_update.jpg', $user->profile_picture_url);
        $this->assertEquals('the-lens/avatars/profile_update', $user->profile_picture_public_id);
        $this->assertStringContainsString('the-lens/avatars/profile_update.jpg', $user->avatar_url);
        $this->assertStringContainsString('f_auto,q_auto', $user->avatar_url);
    }

    public function test_update_info_replaces_old_avatar_and_deletes_from_cloudinary(): void
    {
        $role = Role::firstOrCreate(['role_name' => 'Writer']);
        $user = User::create([
            'role_id' => $role->id,
            'student_number' => '2023-00006-REPU-0',
            'full_name' => 'Original Name',
            'email' => 'writer6_rep@test.com',
            'password' => Hash::make('password123'),
            'profile_picture_url' => 'https://res.cloudinary.com/demo/image/upload/v8888/the-lens/avatars/old_pic.jpg',
            'profile_picture_public_id' => 'the-lens/avatars/old_pic',
        ]);

        $mockService = Mockery::mock(ImageUploadService::class);
        $mockService->shouldReceive('uploadAvatar')
            ->once()
            ->andReturn([
                'secure_url' => 'https://res.cloudinary.com/demo/image/upload/v7777/the-lens/avatars/new_pic.jpg',
                'public_id' => 'the-lens/avatars/new_pic',
                'url' => 'https://res.cloudinary.com/demo/image/upload/v7777/the-lens/avatars/new_pic.jpg',
            ]);

        $mockService->shouldReceive('deleteImage')
            ->once()
            ->with('the-lens/avatars/old_pic', 'https://res.cloudinary.com/demo/image/upload/v8888/the-lens/avatars/old_pic.jpg')
            ->andReturn(true);

        $this->app->instance(ImageUploadService::class, $mockService);

        $image = UploadedFile::fake()->create('new_pic.jpg', 1500, 'image/jpeg');

        $response = $this->actingAs($user)->postJson('/api/profile/update-info', [
            'full_name' => 'Original Name',
            'student_number' => '2023-00006-REPU-0',
            'avatar' => $image,
        ]);

        $response->assertStatus(200);

        $user->refresh();
        $this->assertEquals('https://res.cloudinary.com/demo/image/upload/v7777/the-lens/avatars/new_pic.jpg', $user->profile_picture_url);
        $this->assertEquals('the-lens/avatars/new_pic', $user->profile_picture_public_id);
    }

    public function test_update_info_removes_avatar_and_deletes_from_cloudinary(): void
    {
        $role = Role::firstOrCreate(['role_name' => 'Writer']);
        $user = User::create([
            'role_id' => $role->id,
            'student_number' => '2023-00006-REM-0',
            'full_name' => 'Remove Name',
            'email' => 'writer6_rem@test.com',
            'password' => Hash::make('password123'),
            'profile_picture_url' => 'https://res.cloudinary.com/demo/image/upload/v5555/the-lens/avatars/to_remove.jpg',
            'profile_picture_public_id' => 'the-lens/avatars/to_remove',
        ]);

        $mockService = Mockery::mock(ImageUploadService::class);
        $mockService->shouldReceive('deleteImage')
            ->once()
            ->with('the-lens/avatars/to_remove', 'https://res.cloudinary.com/demo/image/upload/v5555/the-lens/avatars/to_remove.jpg')
            ->andReturn(true);

        $this->app->instance(ImageUploadService::class, $mockService);

        $response = $this->actingAs($user)->postJson('/api/profile/update-info', [
            'full_name' => 'Remove Name',
            'student_number' => '2023-00006-REM-0',
            'remove_avatar' => '1',
        ]);

        $response->assertStatus(200);

        $user->refresh();
        $this->assertNull($user->profile_picture_url);
        $this->assertNull($user->profile_picture_public_id);
    }

    public function test_delete_avatar_endpoint_removes_avatar_and_deletes_from_cloudinary(): void
    {
        $role = Role::firstOrCreate(['role_name' => 'Writer']);
        $user = User::create([
            'role_id' => $role->id,
            'student_number' => '2023-00006-DEL-0',
            'full_name' => 'Delete Route Name',
            'email' => 'writer6_del@test.com',
            'password' => Hash::make('password123'),
            'profile_picture_url' => 'https://res.cloudinary.com/demo/image/upload/v4444/the-lens/avatars/endpoint_remove.jpg',
            'profile_picture_public_id' => 'the-lens/avatars/endpoint_remove',
        ]);

        $mockService = Mockery::mock(ImageUploadService::class);
        $mockService->shouldReceive('deleteImage')
            ->once()
            ->with('the-lens/avatars/endpoint_remove', 'https://res.cloudinary.com/demo/image/upload/v4444/the-lens/avatars/endpoint_remove.jpg')
            ->andReturn(true);

        $this->app->instance(ImageUploadService::class, $mockService);

        $response = $this->actingAs($user)->deleteJson('/api/profile/avatar');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Profile picture removed successfully.',
                'profile_picture_url' => null,
                'public_id' => null,
            ]);

        $user->refresh();
        $this->assertNull($user->profile_picture_url);
        $this->assertNull($user->profile_picture_public_id);
    }

    public function test_update_info_validates_avatar_max_size_3072kb(): void
    {
        $role = Role::firstOrCreate(['role_name' => 'Writer']);
        $user = User::create([
            'role_id' => $role->id,
            'student_number' => '2023-00007-BN-0',
            'full_name' => 'Test Writer',
            'email' => 'writer7@test.com',
            'password' => Hash::make('password123'),
        ]);

        $largeFile = UploadedFile::fake()->create('too_large.jpg', 4000, 'image/jpeg');

        $response = $this->actingAs($user)->postJson('/api/profile/update-info', [
            'full_name' => 'Test Writer',
            'student_number' => '2023-00007-BN-0',
            'avatar' => $largeFile,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['avatar']);
    }
}
