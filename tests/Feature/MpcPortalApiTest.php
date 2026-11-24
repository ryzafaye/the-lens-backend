<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Article;
use App\Models\ArticleImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MpcPortalApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    public function test_mpc_head_can_access_mpc_api_endpoints()
    {
        $mpcUser = User::whereHas('role', function ($query) {
            $query->where('role_name', 'like', '%Media and Public Communications%');
        })->firstOrFail();

        // Dashboard
        $response = $this->actingAs($mpcUser)->getJson('/api/mpc/dashboard');
        $response->assertOk()
            ->assertJsonStructure([
                'user',
                'metrics' => [
                    'total_views',
                    'monthly_views',
                    'published_stories',
                    'pending',
                    'active_members',
                ],
                'pendingArticles',
                'publishedArticles',
                'recentActivities',
                'topJournalists',
                'approvedCovers',
            ]);

        // Publish Article
        $response = $this->actingAs($mpcUser)->getJson('/api/mpc/publish-article');
        $response->assertOk()
            ->assertJsonStructure([
                'articles',
                'approvedArticles',
                'approvedCovers',
                'readyCount',
                'publishedCount',
                'unpublishedCount',
                'coversCount',
            ]);

        // Media Desk
        $response = $this->actingAs($mpcUser)->getJson('/api/mpc/media-desk');
        $response->assertOk()
            ->assertJsonStructure([
                'coverList',
            ]);

        // Manage Media
        $response = $this->actingAs($mpcUser)->getJson('/api/mpc/manage-media');
        $response->assertOk()
            ->assertJsonStructure([
                'mediaItems',
            ]);

        // Manage My Articles
        $response = $this->actingAs($mpcUser)->getJson('/api/mpc/manage-my-articles');
        $response->assertOk()
            ->assertJsonIsArray();

        // Submit Article
        $response = $this->actingAs($mpcUser)->getJson('/api/mpc/submit-article');
        $response->assertOk()
            ->assertJsonStructure([
                'categories',
                'drafts',
            ]);

        // Activity Logs
        $response = $this->actingAs($mpcUser)->getJson('/api/mpc/activity-logs');
        $response->assertOk()
            ->assertJsonStructure([
                'activities',
            ]);

        // Transparency
        $response = $this->actingAs($mpcUser)->getJson('/api/mpc/transparency');
        $response->assertOk();

        // Profile Settings
        $response = $this->actingAs($mpcUser)->getJson('/api/mpc/profile-settings');
        $response->assertOk()
            ->assertJsonStructure([
                'user',
            ]);
    }

    public function test_writer_cannot_access_mpc_api_endpoints()
    {
        $writerUser = User::whereHas('role', function ($query) {
            $query->where('role_name', 'Writer');
        })->firstOrFail();

        $response = $this->actingAs($writerUser)->getJson('/api/mpc/dashboard');
        $response->assertForbidden();

        $response = $this->actingAs($writerUser)->getJson('/api/mpc/publish-article');
        $response->assertForbidden();

        $response = $this->actingAs($writerUser)->getJson('/api/mpc/media-desk');
        $response->assertForbidden();
    }

    public function test_mpc_manage_my_media_scopes_only_to_owner_submissions(): void
    {
        $mpcUser = User::whereHas('role', function ($query) {
            $query->where('role_name', 'Media and Public Communications Head');
        })->firstOrFail();

        $otherUser = User::whereHas('role', function ($query) {
            $query->where('role_name', 'Writer');
        })->firstOrFail();

        $mpcPhoto = ArticleImage::create([
            'uploader_id' => $mpcUser->id,
            'image_type' => 'photo',
            'file_url' => 'https://res.cloudinary.com/the-lens/image/upload/v1/mpc-photo.jpg',
            'title' => 'MPC Photo',
            'status' => 'pending_head',
        ]);

        $otherPhoto = ArticleImage::create([
            'uploader_id' => $otherUser->id,
            'image_type' => 'photo',
            'file_url' => 'https://res.cloudinary.com/the-lens/image/upload/v1/other-photo.jpg',
            'title' => 'Other Photo',
            'status' => 'pending_head',
        ]);

        $res = $this->actingAs($mpcUser)->getJson('/api/mpc/manage-media');
        $res->assertOk();
        $items = collect($res->json('mediaItems'));
        $this->assertTrue($items->contains('id', $mpcPhoto->id));
        $this->assertFalse($items->contains('id', $otherPhoto->id));
    }

    public function test_mpc_approved_covers_library_includes_both_approved_media_and_cartoons(): void
    {
        $mpcUser = User::whereHas('role', function ($query) {
            $query->where('role_name', 'Media and Public Communications Head');
        })->firstOrFail();

        $photojournalist = User::whereHas('role', function ($query) {
            $query->where('role_name', 'Media Team');
        })->firstOrFail();

        $cartoonist = User::whereHas('role', function ($query) {
            $query->where('role_name', 'Cartoonist');
        })->firstOrFail();

        // 1. Create an approved photo (media)
        $approvedPhoto = ArticleImage::create([
            'uploader_id' => $photojournalist->id,
            'image_type' => 'photo',
            'file_url' => 'https://res.cloudinary.com/the-lens/image/upload/v1/approved-photo.jpg',
            'title' => 'Campus Lantern Parade Photo',
            'status' => 'approved',
        ]);

        // 2. Create an approved editorial cartoon
        $approvedCartoon = ArticleImage::create([
            'uploader_id' => $cartoonist->id,
            'image_type' => 'cartoon',
            'file_url' => 'https://res.cloudinary.com/the-lens/image/upload/v1/approved-cartoon.png',
            'title' => 'Press Freedom Editorial Cartoon',
            'status' => 'approved',
        ]);

        // 3. Create a pending cartoon (should NOT be in approved covers)
        $pendingCartoon = ArticleImage::create([
            'uploader_id' => $cartoonist->id,
            'image_type' => 'cartoon',
            'file_url' => 'https://res.cloudinary.com/the-lens/image/upload/v1/pending-cartoon.png',
            'title' => 'Pending Cartoon',
            'status' => 'pending_eic',
        ]);

        // Query publish-article as MPC
        $res = $this->actingAs($mpcUser)->getJson('/api/mpc/publish-article');
        $res->assertOk();

        $approvedCovers = collect($res->json('approvedCovers'));

        // Assert both photo and cartoon are in the approved covers library
        $this->assertTrue(
            $approvedCovers->contains('id', (string) $approvedPhoto->id),
            'Approved photo was not found in approvedCovers'
        );
        $this->assertTrue(
            $approvedCovers->contains('id', (string) $approvedCartoon->id),
            'Approved cartoon was not found in approvedCovers'
        );
        $this->assertFalse(
            $approvedCovers->contains('id', (string) $pendingCartoon->id),
            'Pending cartoon should not be in approvedCovers'
        );

        // Check types
        $photoItem = $approvedCovers->firstWhere('id', (string) $approvedPhoto->id);
        $cartoonItem = $approvedCovers->firstWhere('id', (string) $approvedCartoon->id);

        $this->assertEquals('photo', $photoItem['image_type']);
        $this->assertEquals('cartoon', $cartoonItem['image_type']);

        // 4. Assign the cartoon as the cover of an article
        $article = Article::create([
            'user_id' => $mpcUser->id,
            'category_id' => 1,
            'title' => 'Editorial: The Pen and The Ink',
            'content' => '<p>Editorial article content.</p>',
            'status' => 'Approved',
        ]);

        $assignRes = $this->actingAs($mpcUser)->postJson("/api/mpc/articles/{$article->id}/assign-cover", [
            'cover_id' => $approvedCartoon->id,
        ]);
        $assignRes->assertOk();

        $freshArticle = $article->fresh();
        $this->assertNotNull($freshArticle->coverImage);
        $this->assertEquals($approvedCartoon->id, $freshArticle->coverImage->id);
        $this->assertEquals('cartoon', $freshArticle->coverImage->image_type);

        // 5. Publish the article
        $publishRes = $this->actingAs($mpcUser)->postJson("/api/mpc/articles/{$article->id}/publish", [
            'cover_id' => $approvedCartoon->id,
        ]);
        $publishRes->assertOk();

        $this->assertEquals('Published', $freshArticle->fresh()->status);
    }

    public function test_mpc_submitting_media_persists_custom_title_for_display(): void
    {
        Storage::fake('public');
        $mpcUser = User::whereHas('role', function ($query) {
            $query->where('role_name', 'Media and Public Communications Head');
        })->firstOrFail();

        $file = UploadedFile::fake()->create('campus_photo.jpg', 100, 'image/jpeg');

        $res = $this->actingAs($mpcUser)->post('/api/mpc/submit-media', [
            'media_file' => $file,
            'title' => 'University Convocation 2026',
            'type' => 'photo',
        ], ['Accept' => 'application/json']);

        $res->assertOk();

        $saved = ArticleImage::where('uploader_id', $mpcUser->id)
            ->where('title', 'University Convocation 2026')
            ->first();

        $this->assertNotNull($saved);
        $this->assertEquals('University Convocation 2026', $saved->title);
    }
}
