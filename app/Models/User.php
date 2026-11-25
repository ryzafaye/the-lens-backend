<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The relationships that should always be eager loaded.
     *
     * @var list<string>
     */
    protected $with = ['role'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'role_id',
        'student_number',
        'full_name',
        'email',
        'password',
        'profile_picture_url',
        'profile_picture_public_id',
        'avatar',
        'avatar_url',
        'account_status',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'avatar_url',
        'avatar',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function uploadedImages(): HasMany
    {
        return $this->hasMany(ArticleImage::class, 'uploader_id');
    }

    public function headApprovedImages(): HasMany
    {
        return $this->hasMany(ArticleImage::class, 'head_approved_by');
    }

    public function eicApprovedImages(): HasMany
    {
        return $this->hasMany(ArticleImage::class, 'eic_approved_by');
    }

    public function financialRecords(): HasMany
    {
        return $this->hasMany(FinancialRecord::class, 'uploader_id');
    }

    /**
     * Determine if the user account is deactivated.
     */
    public function isDeactivated(): bool
    {
        return strtolower(trim((string) ($this->account_status ?? ''))) === 'deactivated';
    }

    /**
     * Determine if the user account is active.
     */
    public function isActive(): bool
    {
        return !$this->isDeactivated();
    }

    public function getAvatarAttribute()
    {
        return $this->attributes['profile_picture_url'] ?? $this->getAvatarUrlAttribute();
    }

    public function setAvatarAttribute($value)
    {
        $this->attributes['profile_picture_url'] = $value;
    }

    public function setAvatarUrlAttribute($value)
    {
        $this->attributes['profile_picture_url'] = $value;
    }

    /**
     * Optimizes Cloudinary image URLs with automatic format, quality, and sizing.
     */
    public static function optimizeCloudinaryUrl(?string $url, string $transformation = 'w_300,c_limit,f_auto,q_auto'): ?string
    {
        if (!$url || !str_contains($url, 'res.cloudinary.com') || !str_contains($url, '/upload/')) {
            return $url;
        }
        if (str_contains($url, 'f_auto') || str_contains($url, 'q_auto')) {
            return $url;
        }
        return str_replace('/upload/', '/upload/' . $transformation . '/', $url);
    }

    public function getAvatarUrlAttribute()
    {
        $url = $this->profile_picture_url;
        if (!$url) {
            return asset('images/member-placeholder.svg');
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return static::optimizeCloudinaryUrl($url, 'w_300,c_limit,f_auto,q_auto');
        }
        if (str_starts_with($url, 'storage/')) {
            return asset($url);
        }
        return asset('storage/' . $url);
    }
}
