<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Creditor extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'phone_digits',
        'valid_id_path',
    ];

    protected $hidden = [
        'phone_digits',
    ];

    protected $appends = [
        'valid_id_url',
    ];

    public function getValidIdUrlAttribute(): ?string
    {
        $path = trim((string) ($this->attributes['valid_id_path'] ?? ''));

        if ($path === '') {
            return null;
        }

        if (
            str_starts_with($path, 'http://')
            || str_starts_with($path, 'https://')
            || str_starts_with($path, '/')
        ) {
            return $path;
        }

        $url = Storage::disk('public')->url($path);

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            $relative = parse_url($url, PHP_URL_PATH);

            return is_string($relative) && $relative !== '' ? $relative : $url;
        }

        return $url;
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
