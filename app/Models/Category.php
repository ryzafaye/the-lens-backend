<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Category extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
    ];

    protected static function booted(): void
    {
        static::saved(function () {
            static::clearCategoryCaches();
        });

        static::deleted(function () {
            static::clearCategoryCaches();
        });
    }

    public static function clearCategoryCaches(): void
    {
        try {
            Cache::forget('public_home_data_web');
            Cache::forget('public_home_data_api');
            Cache::forget('public_categories_all');
            Cache::forget('all_categories');
            Cache::forget('all_categories_map_id_name');
        } catch (\Throwable $e) {}
    }

    public function articles()
    {
        return $this->hasMany(Article::class);
    }

    public function __toString()
    {
        return $this->name ?? '';
    }
}
