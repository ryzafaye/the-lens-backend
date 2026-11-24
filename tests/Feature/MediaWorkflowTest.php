<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $mediaTeamUser;
    protected User $photoHead;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $mediaRole = Role::firstOrCreate(['role_name' => 'Media Team']);
        $photoHeadRole = Role::firstOrCreate(['role_name' => 'Photojournalist Head']);

        $this->mediaTeamUser = User::create([
            'role_id' => $mediaRole->id,
            'full_name' => 'Shane Samson',
            'student_number' => '2024-88001-BN-0',
            'email' => 'shane@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'active',
        ]);

        $this->photoHead = User::create([
            'role_id' => $photoHeadRole->id,
            'full_name' => 'Gianne Sendon',
            'student_number' => '2024-88002-BN-0',
            'email' => 'gianne@lens.test',
            'password' => bcrypt('password123'),
            'account_status' => 'active',
        ]);
    }

    public function test_media_team_can_submit_photo_and_see_in_recent_media(): void
    {
        Storage::fake('public');
        $fakeFile = UploadedFile::fake()->create('campus_event.jpg', 150, 'image/jpeg');

        // Submit photo as Media Team member
        $submitResponse = $this->actingAs($this->mediaTeamUser)
            ->postJson('/api/media/submit-media', [
                'media_file' => $fakeFile,
                'title' => 'Campus Lantern Festival 2026',
                'type' => 'photo',
            ]);

        $submitResponse->assertStatus(200);
        $submitResponse->assertJson(['success' => true]);

        $this->assertDatabaseHas('article_images', [
            'uploader_id' => $this->mediaTeamUser->id,
            'title' => 'Campus Lantern Festival 2026',
            'image_type' => 'photo',
            'status' => 'pending_head',
        ]);

        // Access dashboard as Media Team member
        $dashboardResponse = $this->actingAs($this->mediaTeamUser)
            ->getJson('/api/media/dashboard');

        $dashboardResponse->assertStatus(200);
        $dashboardResponse->assertJsonStructure([
            'success',
            'metrics' => [
                'published',
                'pending',
                'revision',
                'published_articles',
                'library',
            ],
            'mediaItems',
            'isSectionHead',
        ]);

        $data = $dashboardResponse->json();
        $this->assertFalse($data['isSectionHead']);
        $this->assertEquals(1, $data['metrics']['pending']);
        $this->assertNotEmpty($data['mediaItems']);
        $this->assertEquals('Campus Lantern Festival 2026', $data['mediaItems'][0]['title']);
    }

    public function test_photojournalist_head_dashboard_handles_pending_and_own_media(): void
    {
        Storage::fake('public');

        // Contributor submits a photo
        ArticleImage::create([
            'uploader_id' => $this->mediaTeamUser->id,
            'title' => 'Basketball Championship',
            'image_type' => 'photo',
            'status' => 'pending_head',
            'file_url' => 'https://res.cloudinary.com/demo/image/upload/sample.jpg',
        ]);

        // Head views dashboard and sees pending verification item
        $headResponse = $this->actingAs($this->photoHead)
            ->getJson('/api/media/dashboard');

        $headResponse->assertStatus(200);
        $data = $headResponse->json();
        $this->assertTrue($data['isSectionHead']);
        $this->assertEquals(1, $data['metrics']['pending']);
        $this->assertCount(1, $data['mediaItems']);
        $this->assertEquals('Basketball Championship', $data['mediaItems'][0]['title']);
    }

    public function test_media_team_can_resubmit_photo_returned_for_revision(): void
    {
        Storage::fake('public');

        $photo = ArticleImage::create([
            'uploader_id' => $this->mediaTeamUser->id,
            'title' => 'Original Photo Title',
            'image_type' => 'photo',
            'status' => 'revision',
            'remarks' => 'Please adjust contrast and crop tighter.',
            'file_url' => 'https://res.cloudinary.com/demo/image/upload/sample.jpg',
        ]);

        $replacementFile = UploadedFile::fake()->create('revised_photo.jpg', 200, 'image/jpeg');

        $resubmitResponse = $this->actingAs($this->mediaTeamUser)
            ->postJson("/api/media/{$photo->id}/resubmit", [
                'media_file' => $replacementFile,
                'title' => 'Revised Photo Title',
            ]);

        $resubmitResponse->assertStatus(200);
        $resubmitResponse->assertJson(['success' => true]);

        $photo->refresh();
        $this->assertEquals('Revised Photo Title', $photo->title);
        $this->assertEquals('pending', $photo->status);
        $this->assertNull($photo->remarks);
    }
}
