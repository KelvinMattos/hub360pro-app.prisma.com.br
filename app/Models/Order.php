<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $appends = [
        'safe_doc_number', 
        'safe_billing_name', 
        'safe_doc_type', 
        'channel_icon', 
        'status_color', 
        'status_label'
    ];

    protected $fillable = [
        'company_id', 'integration_id', 'customer_id',
        'external_id', 'pack_id', 'shipping_id', 'selling_channel',
        'customer_name', 'customer_doc', 'customer_email', 'buyer_nickname',
        'billing_doc_type', 'billing_doc_number', 'billing_name', 
        'billing_legal_name', 'billing_ie', 'taxpayer_type', 'billing_info_json',
        'billing_address_line', 'billing_number', 'billing_neighborhood', 'billing_city', 'billing_state', 'billing_zip',
        'shipping_address_line', 'shipping_number', 'shipping_neighborhood', 'shipping_city', 
        'shipping_state', 'shipping_zip', 'shipping_country', 'shipping_comment',
        'logistic_type', 'shipping_mode',
        'json_order', 'json_shipment', 'json_payments',
        'total_amount', 'total_paid_amount', 'status', 'payment_status', 'payment_method', 'date_created', 'captured_at',
        'cost_products', 'cost_tax_fiscal', 'cost_operational', 'contribution_margin', 'net_profit',
        'cost_fee_commission', 'cost_fee_fixed', 'cost_fee_shipping', 'cost_fee_ads', 'cost_fee_taxes',
        'cost_tax_platform', 'cost_shipping_seller'
    ];

    protected $casts = [
        'date_created' => 'datetime',
        'billing_info_json' => 'array',
        'json_order' => 'array',
        'json_shipment' => 'array',
        'json_payments' => 'array',
        'cost_fee_commission' => 'decimal:2', 'cost_fee_fixed' => 'decimal:2', 
        'cost_fee_shipping' => 'decimal:2', 'cost_fee_ads' => 'decimal:2', 
        'cost_fee_taxes' => 'decimal:2', 'cost_tax_platform' => 'decimal:2',
        'cost_shipping_seller' => 'decimal:2', 'total_amount' => 'decimal:2', 
        'net_profit' => 'decimal:2', 'contribution_margin' => 'decimal:2', 
        'cost_tax_fiscal' => 'decimal:2', 'cost_products' => 'decimal:2', 
        'cost_operational' => 'decimal:2'
    ];

    public function integration() { return $this->belongsTo(Integration::class); }
    public function items() { return $this->hasMany(OrderItem::class); }
    public function customer() { return $this->belongsTo(Customer::class); }

    public function transactions(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Transaction::class, 'origin');
    }

    public function stockMovements(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    public function getSafeDocNumberAttribute() {
        return $this->billing_doc_number ?? ($this->billing_info_json['identification']['number'] ?? '--');
    }
    public function getSafeBillingNameAttribute() {
        return $this->billing_name ?? ($this->billing_info_json['name'] ?? $this->customer_name);
    }
    public function getSafeDocTypeAttribute() {
        return $this->billing_doc_type ?? ($this->billing_info_json['identification']['type'] ?? 'DOC');
    }
    public function getChannelIconAttribute() {
        $platform = $this->integration?->platform ?? 'unknown';
        return match($platform) {
            'mercadolibre' => ['icon' => 'fa-handshake', 'color' => 'text-yellow-400'],
            'shopee' => ['icon' => 'fa-bag-shopping', 'color' => 'text-orange-500'],
            default => ['icon' => 'fa-link', 'color' => 'text-slate-400']
        };
    }
    public function getStatusColorAttribute() {
        return match($this->status) {
            'approved', 'paid' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
            'shipped' => 'bg-blue-500/10 text-blue-400 border-blue-500/20',
            'delivered' => 'bg-slate-700 text-slate-300 border-slate-600',
            'cancelled' => 'bg-red-500/10 text-red-400 border-red-500/20',
            default => 'bg-slate-800 text-slate-400 border-slate-700'
        };
    }
    public function getStatusLabelAttribute() {
        return match($this->status) {
            'approved' => 'Aprovado', 'paid' => 'Pago', 'shipped' => 'Enviado',
            'delivered' => 'Entregue', 'cancelled' => 'Cancelado', default => ucfirst($this->status)
        };
    }
}
