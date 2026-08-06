<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestAttachment extends Model
{
    protected $fillable = [
        'request_id', 'path', 'original_name', 'mime_type', 'size_bytes', 'uploaded_by',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }
}
