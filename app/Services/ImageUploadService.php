<?php

declare(strict_types=1);

namespace App\Services;

use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class ImageUploadService
{
    protected Cloudinary $cloudinary;

    public function __construct(?Cloudinary $cloudinary = null)
    {
        if ($cloudinary !== null) {
            $this->cloudinary = $cloudinary;
            return;
        }

        if (app()->bound(Cloudinary::class)) {
            $this->cloudinary = app(Cloudinary::class);
            return;
        }

        $cloudinaryUrl = config('filesystems.disks.cloudinary.url')
            ?? config('cloudinary.cloud_url')
            ?? env('CLOUDINARY_URL');

        if (!empty($cloudinaryUrl)) {
            if (str_contains($cloudinaryUrl, '<your_api_key>') || str_contains($cloudinaryUrl, '<your_api_secret>')) {
                throw new RuntimeException('Cloudinary credentials in .env still contain placeholders (<your_api_key> / <your_api_secret>). Please replace them with your actual Cloudinary API Key and API Secret from your Cloudinary Dashboard.');
            }
            $this->cloudinary = new Cloudinary($cloudinaryUrl);
            return;
        }

        $cloudName = config('filesystems.disks.cloudinary.cloud') ?? env('CLOUDINARY_CLOUD_NAME');
        $apiKey = config('filesystems.disks.cloudinary.key') ?? env('CLOUDINARY_KEY');
        $apiSecret = config('filesystems.disks.cloudinary.secret') ?? env('CLOUDINARY_SECRET');

        if (!empty($cloudName) && !empty($apiKey) && !empty($apiSecret)) {
            $this->cloudinary = new Cloudinary([
                'cloud' => [
                    'cloud_name' => $cloudName,
                    'api_key' => $apiKey,
                    'api_secret' => $apiSecret,
                ],
                'url' => [
                    'secure' => true,
                ],
            ]);
            return;
        }

        throw new RuntimeException('Cloudinary configuration is missing. Please set CLOUDINARY_URL in .env.');
    }

    /**
     * Upload an avatar to Cloudinary inside the the-lens/avatars folder.
     *
     * @param UploadedFile|string $file
     * @param array<string, mixed> $options
     * @return array{secure_url: string, public_id: string, url: string}
     */
    public function uploadAvatar(UploadedFile|string $file, array $options = []): array
    {
        return $this->uploadImage($file, 'the-lens/avatars', $options);
    }

    /**
     * Upload an image to Cloudinary in the specified folder.
     *
     * @param UploadedFile|string $file
     * @param string $folder
     * @param array<string, mixed> $options
     * @return array{secure_url: string, public_id: string, url: string}
     */
    public function uploadImage(UploadedFile|string $file, string $folder = 'the-lens/avatars', array $options = []): array
    {
        $filePath = $file instanceof UploadedFile ? $file->getRealPath() : $file;

        if (empty($filePath)) {
            throw new InvalidArgumentException('Invalid file path or UploadedFile provided.');
        }

        $params = array_merge([
            'folder' => $folder,
            'asset_folder' => $folder,
            'resource_type' => 'image',
            'overwrite' => true,
        ], $options);

        $response = $this->cloudinary->uploadApi()->upload($filePath, $params);

        $secureUrl = (string) ($response['secure_url'] ?? $response['url'] ?? '');
        $publicId = (string) ($response['public_id'] ?? '');

        return [
            'secure_url' => $secureUrl,
            'public_id' => $publicId,
            'url' => $secureUrl,
        ];
    }

    /**
     * Delete an asset from Cloudinary by its public ID or fallback URL.
     *
     * @param string|null $publicId
     * @param string|null $fallbackUrl
     * @return bool
     */
    public function deleteImage(?string $publicId = null, ?string $fallbackUrl = null): bool
    {
        $targetPublicId = $publicId;

        // If publicId is null, attempt to extract it from the fallback URL
        if (empty($targetPublicId) && !empty($fallbackUrl)) {
            $targetPublicId = $this->extractPublicIdFromUrl($fallbackUrl);
        }

        if (empty($targetPublicId)) {
            return false;
        }

        try {
            $result = $this->cloudinary->uploadApi()->destroy($targetPublicId, ['resource_type' => 'image']);
            $status = (string) ($result['result'] ?? '');
            return $status === 'ok' || $status === 'not found';
        } catch (\Throwable $e) {
            Log::warning("Failed to delete Cloudinary asset [{$targetPublicId}]: " . $e->getMessage(), [
                'public_id' => $targetPublicId,
                'fallback_url' => $fallbackUrl,
                'exception' => $e,
            ]);
            return false;
        }
    }

    /**
     * Extract Cloudinary public_id from a res.cloudinary.com URL.
     *
     * @param string|null $url
     * @return string|null
     */
    public function extractPublicIdFromUrl(?string $url): ?string
    {
        if (empty($url) || !str_contains($url, 'res.cloudinary.com')) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (empty($path)) {
            return null;
        }

        $uploadPos = strpos($path, '/upload/');
        if ($uploadPos === false) {
            return null;
        }

        $afterUpload = substr($path, $uploadPos + strlen('/upload/'));
        if (empty($afterUpload)) {
            return null;
        }

        $segments = explode('/', $afterUpload);
        $publicIdSegments = [];
        $foundVersion = false;

        foreach ($segments as $segment) {
            if (empty($segment)) {
                continue;
            }

            // Check if segment is version number (e.g. v1234567890 or v1)
            if (!$foundVersion && preg_match('/^v\d+$/', $segment)) {
                $foundVersion = true;
                continue;
            }

            // If before version, check if it's a transformation segment (e.g. c_fill,w_300, etc.)
            if (!$foundVersion && (str_contains($segment, ',') || preg_match('/^[a-z]{1,3}_/', $segment))) {
                continue;
            }

            $publicIdSegments[] = $segment;
        }

        if (empty($publicIdSegments)) {
            return null;
        }

        $rawPublicId = implode('/', $publicIdSegments);

        // Strip file extension (e.g. .jpg, .png, .webp)
        $publicId = preg_replace('/\.[^.\/]+$/', '', $rawPublicId);

        return !empty($publicId) ? rawurldecode($publicId) : null;
    }

    /**
     * Get underlying Cloudinary instance.
     */
    public function getCloudinary(): Cloudinary
    {
        return $this->cloudinary;
    }
}
