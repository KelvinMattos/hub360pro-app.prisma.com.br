<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class TaxRule extends Model
{
    protected $guarded = [];

    protected static function booted()
    {
        static::addGlobalScope('company', function (Builder $builder) {
            if (Auth::check() && !Auth::user()->isMaster()) {
                $builder->where('company_id', Auth::user()->company_id);
            }
        });

        static::creating(function ($model) {
            if (Auth::check() && !Auth::user()->isMaster()) {
                $model->company_id = Auth::user()->company_id;
            }
        });
    }
}