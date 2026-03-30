<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'order_id',
        'product_id', // Chave estrangeira crucial
        'product_title',
        'sku',
        'quantity',
        'unit_price',
        'unit_cost',
        'full_unit_price',
        'currency_id',
        'external_item_id',
        'external_variation_id',
        'cost_price',
        'net_margin'
    ];

    // Relação com o Pedido Pai
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    // Relação com o Produto do Catálogo (O Vínculo que você pediu)
    public function product()
    {
        return $this->belongsTo(Product::class , 'product_id');
    }
}