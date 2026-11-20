<?php

namespace Tests\Unit;

use App\Services\ImageUploadService;
use Cloudinary\Api\ApiResponse;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use Mockery;
use Tests\TestCase;

class ImageUploadServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_upload_avatar_calls_cloudinary_with_the_lens_avatars_folder(): void
    {
        $mockUploadApi = Mockery::mock(UploadApi::class);
        $mockCloudinary = Mockery::mock(Cloudinary::class);

        $mockCloudinary->shouldReceive('uploadApi')
            ->once()
            ->andReturn($mockUploadApi);

        $dummyResponse = [
            'secure_url' => 'https://res.cloudinary.com/test-cloud/image/upload/v1234/the-lens/avatars/sample.jpg',
            'public_id' => 'the-lens/avatars/sample',
            'url' => 'http://res.cloudinary.com/test-cloud/image/upload/v1234/the-lens/avatars/sample.jpg',
        ];

        $apiResponse = new \Cloudinary\Api\ApiResponse($dummyResponse, []);

        $mockUploadApi->shouldReceive('upload')
            ->once()
            ->withArgs(function ($filePath, $params) {
                return !empty($filePath)
                    && isset($params['folder']) && $params['folder'] === 'the-lens/avatars'
                    && isset($params['asset_folder']) && $params['asset_folder'] === 'the-lens/avatars'
                    && isset($params['resource_type']) && $params['resource_type'] === 'image'
                    && isset($params['overwrite']) && $params['overwrite'] === true;
            })
            ->andReturn($apiResponse);

        $service = new ImageUploadService($mockCloudinary);

        $tempFile = tempnam(sys_get_temp_dir(), 'test_img_');
        file_put_contents($tempFile, 'fake-image-bytes');

        $result = $service->uploadAvatar($tempFile);

        $this->assertEquals('https://res.cloudinary.com/test-cloud/image/upload/v1234/the-lens/avatars/sample.jpg', $result['secure_url']);
        $this->assertEquals('the-lens/avatars/sample', $result['public_id']);
        $this->assertEquals('https://res.cloudinary.com/test-cloud/image/upload/v1234/the-lens/avatars/sample.jpg', $result['url']);

        @unlink($tempFile);
    }

    public function test_delete_image_calls_destroy(): void
    {
        $mockUploadApi = Mockery::mock(UploadApi::class);
        $mockCloudinary = Mockery::mock(Cloudinary::class);

        $mockCloudinary->shouldReceive('uploadApi')
            ->once()
            ->andReturn($mockUploadApi);

        $mockUploadApi->shouldReceive('destroy')
            ->once()
            ->with('the-lens/avatars/sample', ['resource_type' => 'image'])
            ->andReturn(new \Cloudinary\Api\ApiResponse(['result' => 'ok'], []));

        $service = new ImageUploadService($mockCloudinary);

        $deleted = $service->deleteImage('the-lens/avatars/sample');

        $this->assertTrue($deleted);
    }

    public function test_delete_image_extracts_public_id_from_fallback_url(): void
    {
        $mockUploadApi = Mockery::mock(UploadApi::class);
        $mockCloudinary = Mockery::mock(Cloudinary::class);

        $mockCloudinary->shouldReceive('uploadApi')
            ->once()
            ->andReturn($mockUploadApi);

        $mockUploadApi->shouldReceive('destroy')
            ->once()
            ->with('the-lens/avatars/legacy_pic', ['resource_type' => 'image'])
            ->andReturn(new \Cloudinary\Api\ApiResponse(['result' => 'ok'], []));

        $service = new ImageUploadService($mockCloudinary);

        $deleted = $service->deleteImage(null, 'https://res.cloudinary.com/test-cloud/image/upload/v1234/the-lens/avatars/legacy_pic.jpg');

        $this->assertTrue($deleted);
    }

    public function test_delete_image_extracts_public_id_with_transformations(): void
    {
        $mockUploadApi = Mockery::mock(UploadApi::class);
        $mockCloudinary = Mockery::mock(Cloudinary::class);

        $mockCloudinary->shouldReceive('uploadApi')
            ->once()
            ->andReturn($mockUploadApi);

        $mockUploadApi->shouldReceive('destroy')
            ->once()
            ->with('the-lens/avatars/transformed_pic', ['resource_type' => 'image'])
            ->andReturn(new \Cloudinary\Api\ApiResponse(['result' => 'ok'], []));

        $service = new ImageUploadService($mockCloudinary);

        $deleted = $service->deleteImage(null, 'https://res.cloudinary.com/test-cloud/image/upload/c_fill,w_300/v9876/the-lens/avatars/transformed_pic.png');

        $this->assertTrue($deleted);
    }

    public function test_delete_image_returns_false_for_non_cloudinary_fallback(): void
    {
        $mockCloudinary = Mockery::mock(Cloudinary::class);
        $mockCloudinary->shouldReceive('uploadApi')->never();

        $service = new ImageUploadService($mockCloudinary);

        $this->assertFalse($service->deleteImage(null, '/images/member-placeholder.svg'));
        $this->assertFalse($service->deleteImage(null, null));
    }

    public function test_delete_image_handles_exception_gracefully(): void
    {
        $mockUploadApi = Mockery::mock(UploadApi::class);
        $mockCloudinary = Mockery::mock(Cloudinary::class);

        $mockCloudinary->shouldReceive('uploadApi')
            ->once()
            ->andReturn($mockUploadApi);

        $mockUploadApi->shouldReceive('destroy')
            ->once()
            ->with('the-lens/avatars/fail_asset', ['resource_type' => 'image'])
            ->andThrow(new \Exception('Cloudinary network timeout'));

        $service = new ImageUploadService($mockCloudinary);

        $deleted = $service->deleteImage('the-lens/avatars/fail_asset');

        $this->assertFalse($deleted);
    }
}
