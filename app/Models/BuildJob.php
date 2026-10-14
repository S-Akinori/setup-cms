<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['status', 'reason', 'payload', 'error', 'finished_at'])]
class BuildJob extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['payload' => 'array', 'finished_at' => 'datetime'];
    }
}
