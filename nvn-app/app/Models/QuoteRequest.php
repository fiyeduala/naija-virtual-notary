<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuoteRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['document_types' => 'array'];
    }
}
