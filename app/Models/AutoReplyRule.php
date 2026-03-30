<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutoReplyRule extends Model
{
    protected $fillable = ['company_id', 'name', 'keywords', 'reply_text', 'is_active', 'priority'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
