<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Inquiry extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'email', 'phone', 'service_interest', 'message', 'read_at'];

    protected $casts = [
        'read_at' => 'datetime',
    ];
}
