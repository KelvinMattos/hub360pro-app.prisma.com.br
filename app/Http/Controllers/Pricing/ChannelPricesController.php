<?php

namespace App\Http\Controllers\Pricing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

/**
 * Preços por Canal — tela dedicada pra ver o preço de cada produto em cada
 * canal em que ele é vendido, lado a lado. Mesma fonte de dado do Cálculo
 * Promo (products.channel_prices) + o campo dedicado da Netshoes
 * (products.netshoes_price), que sincroniza tanto pelo import dedicado
 * (Importações Netshoes → Preços) quanto pela planilha Magazord (coluna
 * "Netshoes" preenchida).
 */
class ChannelPricesController extends Controller
{
    private const CHANNELS = [
        'Site', 'Mercado Livre', 'Amazon', 'Netshoes', 'Shopee', 'Magalu', 'Centauro', 'Dafiti', 'Via Varejo',
    ];

    public function index(Request $request)
    {
        $companyId = Auth::user()?->company_id;

        $hasChannelPrices = Schema::hasColumn('products', 'channel_prices');
        $hasNetshoesPrice = Schema::hasColumn('products', 'netshoes_price');
        $hasBrand = Schema::hasColumn('products', 'brand');

        $select = ['id', 'sku', 'title', 'stock_quantity', 'sale_price', 'status'];
        if ($hasBrand) $select[] = 'brand';
        if ($hasChannelPrices) $select[] = 'channel_prices';
        if ($hasNetshoesPrice) $select[] = 'netshoes_price';

        $query = DB::table('products')
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereNotNull('sku')
            ->where('sku', '!=', '');

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('sku', 'like', $like)->orWhere('title', 'like', $like);
            });
        }

        $onlyChannel = trim((string) $request->query('only_channel', ''));

        $products = $query->select($select)->orderBy('title')->get();

        $rows = $products->map(function ($p) use ($hasChannelPrices, $hasNetshoesPrice, $hasBrand) {
            $cp = [];
            if ($hasChannelPrices) {
                $decoded = json_decode($p->channel_prices ?? '', true);
                if (is_array($decoded)) $cp = $decoded;
            }

            $prices = [];
            foreach (self::CHANNELS as $ch) {
                $v = $cp[$ch] ?? null;
                $prices[$ch] = ($v !== null && (float) $v > 0) ? round((float) $v, 2) : null;
            }

            // Netshoes: também aceita netshoes_price (import dedicado Importações
            // Netshoes → Preços), que não passa pela planilha Magazord.
            if ($prices['Netshoes'] === null && $hasNetshoesPrice && (float) ($p->netshoes_price ?? 0) > 0) {
                $prices['Netshoes'] = round((float) $p->netshoes_price, 2);
            }

            // Site: preço base do catálogo quando não há channel_prices específico.
            if ($prices['Site'] === null && (float) ($p->sale_price ?? 0) > 0) {
                $prices['Site'] = round((float) $p->sale_price, 2);
            }

            return [
                'id' => $p->id,
                'sku' => $p->sku,
                'title' => $p->title,
                'brand' => $hasBrand ? ($p->brand ?? null) : null,
                'stock' => (int) ($p->stock_quantity ?? 0),
                'prices' => $prices,
                'channels_linked' => collect($prices)->filter(fn ($v) => $v !== null)->count(),
            ];
        });

        if ($onlyChannel !== '' && in_array($onlyChannel, self::CHANNELS, true)) {
            $rows = $rows->filter(fn ($r) => $r['prices'][$onlyChannel] !== null)->values();
        }

        $total = $rows->count();
        $noLinkCount = $rows->filter(fn ($r) => $r['channels_linked'] === 0)->count();

        $perPage = 50;
        $page = max(1, (int) $request->query('page', 1));
        $lastPage = max(1, (int) ceil($total / $perPage));

        return Inertia::render('Pricing/ChannelPrices', [
            'channels' => self::CHANNELS,
            'rows' => $rows->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            'stats' => ['total' => $total, 'sem_vinculo' => $noLinkCount],
            'filters' => ['q' => $search, 'only_channel' => $onlyChannel],
            'pagination' => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'lastPage' => $lastPage],
            'has_channel_prices' => $hasChannelPrices,
        ]);
    }
}
