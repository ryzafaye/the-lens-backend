<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ArticleImageService
{
    protected ?ImageUploadService $imageUploadService;

    public function __construct(?ImageUploadService $imageUploadService = null)
    {
        if ($imageUploadService !== null) {
            $this->imageUploadService = $imageUploadService;
        } else {
            try {
                $this->imageUploadService = app(ImageUploadService::class);
            } catch (\Throwable $e) {
                $this->imageUploadService = null;
            }
        }
    }

    /**
     * Store and register an editorial visual asset.
     */
    public function storeImage(
        User $uploader,
        UploadedFile|string $fileOrData,
        string $imageType = 'photo',
        ?string $title = null,
        ?string $caption = null
    ): ArticleImage {
        $fileUrl = '';
        $publicId = null;

        // 1. Attempt Cloudinary upload first
        if ($this->imageUploadService !== null) {
            try {
                $folder = 'the-lens/' . Str::plural($imageType);
                $uploadRes = $this->imageUploadService->uploadImage($fileOrData, $folder);
                if (!empty($uploadRes['secure_url'])) {
                    $fileUrl = (string) $uploadRes['secure_url'];
                    $publicId = (string) ($uploadRes['public_id'] ?? null);
                }
            } catch (\Throwable $e) {
                Log::warning('Cloudinary upload fallback to local storage: ' . $e->getMessage());
            }
        }

        // 2. Fallback to local storage if Cloudinary is offline or unavailable
        if (empty($fileUrl)) {
            $fileUrl = $this->resolveFileUrlLocally($fileOrData, $imageType);
        }

        $uploader->loadMissing('role');
        $roleName = $uploader->role?->role_name ?? '';

        $isSectionHead = in_array($roleName, [
            'Cartoonist Head',
            'Associate Cartoonist Head',
            'Chief Cartoonist',
            'Photojournalist Head',
            'Associate Media and Public Communications Head',
            'Media and Public Communications Head',
            'M&PC Head',
            'Associate M&PC Head',
        ], true);

        // Section Heads bypass head desk and submit directly to EIC
        $status = $isSectionHead ? 'pending_eic' : 'pending_head';
        $headApprovedBy = $isSectionHead ? $uploader->id : null;

        if (empty($title) && $fileOrData instanceof UploadedFile) {
            $rawName = trim((string) pathinfo($fileOrData->getClientOriginalName(), PATHINFO_FILENAME));
            $title = $rawName !== '' ? $rawName : null;
        }

        return ArticleImage::create([
            'uploader_id' => $uploader->id,
            'title' => $title ?: ($imageType === 'cartoon' ? 'Editorial Cartoon' : 'Editorial Photo'),
            'image_type' => $imageType,
            'status' => $status,
            'head_approved_by' => $headApprovedBy,
            'file_url' => $fileUrl,
            'public_id' => $publicId,
            'is_cover' => false,
        ]);
    }

    /**
     * Endorse image from Section Head to EIC.
     */
    public function endorseByHead(ArticleImage $image, User $head): ArticleImage
    {
        $image->update([
            'status' => 'pending_eic',
            'head_approved_by' => $head->id,
        ]);

        return $image;
    }

    /**
     * Return visual asset to artist for revision.
     */
    public function returnForRevision(ArticleImage $image, User $reviewer, string $remarks): ArticleImage
    {
        $image->update([
            'status' => 'revision',
            'remarks' => $remarks,
        ]);

        return $image;
    }

    /**
     * Resubmit visual asset returned for revision.
     */
    public function resubmitImage(
        ArticleImage $image,
        User $user,
        UploadedFile|string|null $fileOrData = null,
        ?string $title = null
    ): ArticleImage {
        // 1. If replacement file is provided, upload and update file_url and public_id
        if ($fileOrData) {
            $fileUrl = '';
            $publicId = null;

            if ($this->imageUploadService !== null) {
                try {
                    $folder = 'the-lens/' . Str::plural($image->image_type ?: 'media');
                    $uploadResult = $this->imageUploadService->uploadImage($fileOrData, $folder);
                    $fileUrl = $uploadResult['url'] ?? '';
                    $publicId = $uploadResult['public_id'] ?? null;
                } catch (\Throwable $e) {
                    \Log::warning('Cloudinary resubmit upload failed, falling back to local: ' . $e->getMessage());
                }
            }

            if (empty($fileUrl)) {
                $fileUrl = $this->resolveFileUrlLocally($fileOrData, $image->image_type ?: 'photo');
            }

            // Cleanup old Cloudinary asset if replaced
            if ($image->public_id && $this->imageUploadService !== null && $publicId && $publicId !== $image->public_id) {
                try {
                    $this->imageUploadService->deleteImage($image->public_id);
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            $image->file_url = $fileUrl;
            $image->public_id = $publicId;
        }

        // 2. Update title if provided
        if (!empty($title)) {
            $image->title = $title;
        }

        // 3. Determine new pending status
        $normRole = strtolower(trim($user->role?->role_name ?? ''));
        $isSectionHead = str_contains($normRole, 'head') || str_contains($normRole, 'chief');
        if ($isSectionHead || str_contains($normRole, 'mpc') || str_contains($normRole, 'public communication')) {
            $image->status = 'pending_eic';
        } else {
            $image->status = 'pending';
        }

        // 4. Clear revision remarks
        $image->remarks = null;
        $image->save();

        return $image;
    }

    /**
     * EIC approval of artwork into the publication library.
     */
    public function approveByEic(ArticleImage $image, User $eic): ArticleImage
    {
        $image->update([
            'status' => 'approved',
            'eic_approved_by' => $eic->id,
        ]);

        return $image;
    }

    /**
     * Assign image as the official cover of an article.
     */
    public function assignCoverToArticle(Article $article, int|string $imageId): ArticleImage
    {
        $image = ArticleImage::findOrFail($imageId);

        // Release any previous cover attached to this article back to the available approved pool
        ArticleImage::where('article_id', $article->id)
            ->where('id', '!=', $image->id)
            ->update([
                'article_id' => null,
                'is_cover' => false,
                'status' => 'approved',
            ]);

        $image->update([
            'article_id' => $article->id,
            'is_cover' => true,
            'status' => 'published',
        ]);

        return $image;
    }

    /**
     * Format ArticleImage model into standard dataset array for Views & JavaScript.
     *
     * @return array<string, mixed>
     */
    public function formatForView(ArticleImage $image): array
    {
        $displayStatus = match (strtolower(trim($image->status))) {
            'pending', 'pending_head', 'pending with head', 'pending review' => 'Pending with Head',
            'pending_eic', 'pending eic review' => 'Pending EIC Review',
            'approved', 'eic approved', 'approved_for_mpc', 'approved for mpc', 'eic_approved' => 'EIC Approved',
            'revision', 'for revision', 'returned for revision' => 'For Revision',
            'published' => 'Published',
            default => 'Pending with Head',
        };

        $headName = $image->headApprover?->full_name;
        $headRole = $image->headApprover?->role?->role_name ?? 'Section Head';
        $artistName = $image->uploader?->full_name ?? 'Editorial Contributor';
        $formattedDate = $image->created_at ? $image->created_at->format('M d, Y') : date('M d, Y');

        return [
            'id' => (string) $image->id,
            'title' => $image->title ?? ($image->image_type === 'cartoon' ? 'Editorial Cartoon' : 'Editorial Photo'),
            'caption' => '',
            'type' => $image->image_type,
            'image_type' => $image->image_type,
            'coverType' => $image->image_type,
            'artist' => $artistName,
            'artist_name' => $artistName,
            'author' => $artistName,
            'uploader_name' => $artistName,
            'userId' => (string) $image->uploader_id,
            'studentNumber' => $image->uploader?->student_number ?? '',
            'uploaderRole' => $image->uploader?->role?->role_name ?? 'Contributor',
            'image' => $image->file_url,
            'file_url' => $image->file_url,
            'image_url' => $image->file_url,
            'url' => $image->file_url,
            'public_id' => $image->public_id,
            'date' => $formattedDate,
            'formattedDate' => $formattedDate,
            'rawDate' => $image->created_at ? $image->created_at->toIso8601String() : now()->toIso8601String(),
            'status' => $displayStatus,
            'endorsedBy' => $headName ? "{$headName} ({$headRole})" : '',
            'endorsedDate' => $image->head_approved_by && $image->updated_at ? $image->updated_at->format('M d, Y') : '',
            'approvedBy' => $image->eicApprover ? ($image->eicApprover->full_name . ' (Editor-in-Chief)') : '',
            'remarks' => $image->remarks ?? '',
            'connectedArticleId' => (string) ($image->article_id ?? ''),
            'connectedArticleTitle' => $image->article?->title ?? '',
            'isCover' => (bool) $image->is_cover,
        ];
    }

    /**
     * Resolve file upload or data URL to a local accessible asset URL (fallback).
     */
    private function resolveFileUrlLocally(UploadedFile|string $fileOrData, string $imageType): string
    {
        // 1. If an actual UploadedFile is provided
        if ($fileOrData instanceof UploadedFile) {
            $folderName = 'uploads/' . Str::plural($imageType);
            $extension = $fileOrData->getClientOriginalExtension() ?: 'png';
            $fileName = Str::uuid()->toString() . '.' . $extension;

            try {
                $path = Storage::disk('public')->putFileAs($folderName, $fileOrData, $fileName);
                return Storage::disk('public')->url($path);
            } catch (\Throwable) {
                $destinationPath = rtrim(str_replace('/', DIRECTORY_SEPARATOR, public_path($folderName)), DIRECTORY_SEPARATOR);
                if (!File::isDirectory($destinationPath)) {
                    File::makeDirectory($destinationPath, 0777, true, true);
                }
                @copy($fileOrData->getRealPath(), $destinationPath . DIRECTORY_SEPARATOR . $fileName);
                return asset($folderName . '/' . $fileName);
            }
        }

        // 2. If a Base64 data URL string is provided
        if (is_string($fileOrData) && str_starts_with($fileOrData, 'data:image/')) {
            $folderName = 'uploads/' . Str::plural($imageType);
            $destinationPath = rtrim(str_replace('/', DIRECTORY_SEPARATOR, public_path($folderName)), DIRECTORY_SEPARATOR);

            if (!File::isDirectory($destinationPath)) {
                File::makeDirectory($destinationPath, 0777, true, true);
            }

            // Extract mime type and base64 payload
            if (preg_match('/^data:image\/(\w+);base64,/', $fileOrData, $matches)) {
                $ext = $matches[1] === 'svg+xml' ? 'svg' : ($matches[1] === 'jpeg' ? 'jpg' : $matches[1]);
                $data = substr($fileOrData, strpos($fileOrData, ',') + 1);
                $decoded = base64_decode($data);
                if ($decoded !== false) {
                    $fileName = Str::uuid()->toString() . '.' . $ext;
                    File::put($destinationPath . '/' . $fileName, $decoded);
                    return asset($folderName . '/' . $fileName);
                }
            }

            // Fallback: store data URL directly
            return $fileOrData;
        }

        // 3. String URL already
        return (string) $fileOrData;
    }
}
