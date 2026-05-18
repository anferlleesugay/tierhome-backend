<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatbotLog extends Model
{
    //
    protected $fillable = [
    'user_id',
    'question',
    'response'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
