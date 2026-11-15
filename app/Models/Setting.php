<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

#[Fillable(['key', 'value'])]
class Setting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public static function isAvailable(): bool
    {
        return Schema::hasTable((new static)->getTable());
    }

    public static function valueOf(string $key): ?string
    {
        if (! static::isAvailable()) {
            return null;
        }

        $value = static::query()->whereKey($key)->value('value');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function setValue(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
