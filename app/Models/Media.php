<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['disk', 'path', 'original_name', 'mime_type', 'size', 'width', 'height', 'alt_text', 'sha256', 'uploaded_by'])]
class Media extends Model
{
    use HasUlids;
}
