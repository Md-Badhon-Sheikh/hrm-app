<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class Files
{
    /** Store an uploaded file on the public disk and return its relative path. */
    public static function store(?UploadedFile $file, string $folder): ?string
    {
        if (! $file || ! $file->isValid()) {
            return null;
        }

        return $file->store($folder, 'public');
    }

    /** Absolute URL for a stored path (or pass-through for full URLs). */
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return asset('storage/'.ltrim($path, '/'));
    }
}
