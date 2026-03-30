<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
        'company_id', 'name', 'origin_channel', 'doc_type', 'doc_number', 'external_id',
        'email', 'phone', 'city', 'state',
        'orders_count', 'total_spent', 'last_purchase_date'
    ];

    protected $casts = [
        'last_purchase_date' => 'datetime',
        'total_spent' => 'decimal:2'
    ];

    public function orders() {
        return $this->hasMany(Order::class)->orderBy('date_created', 'desc');
    }

    public function getStatusLabelAttribute() {
        if ($this->orders_count > 1) {
            return '<span class="bg-purple-500/10 text-purple-400 px-2 py-1 rounded text-[10px] uppercase border border-purple-500/20 font-bold tracking-wider">Recorrente</span>';
        }
        return '<span class="bg-emerald-500/10 text-emerald-400 px-2 py-1 rounded text-[10px] uppercase border border-emerald-500/20 font-bold tracking-wider">Novo</span>';
    }

    public function getChannelBadgeAttribute() {
        $channel = strtolower($this->origin_channel ?? 'unknown');
        $config = match($channel) {
            'mercadolibre' => ['icon' => 'fa-handshake', 'bg' => 'bg-yellow-500/10', 'text' => 'text-yellow-500', 'border' => 'border-yellow-500/20', 'label' => 'Mercado Livre'],
            'shopee' => ['icon' => 'fa-bag-shopping', 'bg' => 'bg-orange-500/10', 'text' => 'text-orange-500', 'border' => 'border-orange-500/20', 'label' => 'Shopee'],
            'bling' => ['icon' => 'fa-cube', 'bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-500', 'border' => 'border-emerald-500/20', 'label' => 'Bling'],
            'unknown' => ['icon' => 'fa-circle-question', 'bg' => 'bg-slate-700', 'text' => 'text-slate-400', 'border' => 'border-slate-600', 'label' => 'Indefinido'],
            default => ['icon' => 'fa-globe', 'bg' => 'bg-slate-700', 'text' => 'text-slate-300', 'border' => 'border-slate-600', 'label' => ucfirst($channel)]
        };
        return "<span class='{$config['bg']} {$config['text']} {$config['border']} border px-2 py-0.5 rounded text-[10px] uppercase font-bold flex items-center gap-1 w-fit whitespace-nowrap'><i class='fa-solid {$config['icon']}'></i> {$config['label']}</span>";
    }
    
    public function getFormattedDocAttribute() {
        if (!$this->doc_number) return 'Sem Documento';
        return "{$this->doc_type}: {$this->doc_number}";
    }
}