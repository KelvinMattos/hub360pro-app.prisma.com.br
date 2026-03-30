<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'company_id', 'name', 'email', 'password', 'is_super_admin'
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'is_super_admin' => 'boolean',
    ];

    // Relação: Usuário pertence a uma Empresa
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    // Helper: É o dono do sistema?
    public function isMaster()
    {
        return $this->is_super_admin === true;
    }
}