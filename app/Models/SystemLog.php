<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemLog extends Model
{
    protected $fillable = ['company_id', 'channel', 'level', 'message', 'context'];
    protected $casts = ['context' => 'array', 'created_at' => 'datetime'];

    public function company() {
        return $this->belongsTo(Company::class);
    }
}