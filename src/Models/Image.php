<?php

namespace Futurello\MoodBoard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class Image extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name', 'original_name', 'path', 'mime_type',
        'size', 'width', 'height', 'hash'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid();
            }
        });

        static::deleting(function ($image) {
            // Удаляем файл при удалении записи
            if (Storage::disk('s3')->exists($image->path)) {
                Storage::disk('s3')->delete($image->path);
            }
        });
    }

    public function getUrlAttribute()
    {
        return route('images.file', $this->id);
    }

    public function getPublicUrlAttribute()
    {
        return Storage::disk('s3')->url($this->path);
    }
}
