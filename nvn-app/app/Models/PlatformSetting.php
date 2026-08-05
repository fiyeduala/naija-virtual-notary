<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value', 'cast'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $record = static::where('key', $key)->first();

        if (! $record) {
            return $default;
        }

        return match ($record->cast) {
            'integer' => (int) $record->value,
            'boolean' => (bool) $record->value,
            default   => $record->value,
        };
    }

    public static function set(string $key, mixed $value, string $cast = 'string'): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => (string) $value, 'cast' => $cast]
        );
    }
}
