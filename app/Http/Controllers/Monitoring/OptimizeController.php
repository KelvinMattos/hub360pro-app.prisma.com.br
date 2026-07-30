<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Services\ChannelConfigService;
use App\Services\MarketOptimizerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

/**
 * Otimizar: oportunidades de preço organizadas por canal, sempre respeitando
 * o piso de margem saudável de cada canal (custo + comissão do canal +
 * imposto + margem mínima da marca/global) — a mesma trava do RepricingEngine.
 */
class OptimizeController extends Controller
{
    public function __construct(
        private MarketOptimizerService $opt,
        private ChannelConfigService $channelConfig
    ) {
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->company_id) {
            return redirect()->route('login');
        }
        $companyId = $user->company_id;
        $minMargin = (float) $request->query('min_margin', 10);

        return Inertia::render('Monitoring/Optimize', [
            'summary' => $this->opt->optimizeSummary($companyId),
            'porCanal' => $this->opt->opportunitiesByChannel($companyId, $minMargin)['channels'],
            'otimizacoes' => $this->opt->recentOptimizations($companyId),
            'min_margin' => $minMargin,
        ]);
    }

    /**
     * Aplica o preço sugerido/editado para UM canal específico do produto.
     * Netshoes grava no campo dedicado (netshoes_price); Site grava no preço
     * base (sale_price); os demais canais gravam em channel_prices (JSON) —
     * a mesma estrutura usada pelo Cálculo Promo e por Preços por Canal.
     */
    public function apply(Request $request, int $product)
    {
        $user = Auth::user();
        if (!$user || !$user->company_id) {
            return redirect()->route('login');
        }

        $data = $request->validate([
            'price' => ['required', 'numeric', 'min:0.01'],
            'channel' => ['required', 'string'],
        ]);

        $config = $this->channelConfig->forCompany($user->company_id);
        $channel = collect($config['channels'] ?? [])->firstWhere('id', $data['channel']);
        if (!$channel) {
            return back()->with('error', 'Canal desconhecido.');
        }

        $q = DB::table('products')->where('id', $product);
        if (Schema::hasColumn('products', 'company_id')) {
            $q->where('company_id', $user->company_id);
        }

        $price = round((float) $data['price'], 2);
        $col = $channel['col'] ?? (($channel['id'] === 'centauro') ? 'Centauro' : null);
        $isSite = $channel['id'] === 'site';
        $isNetshoes = $channel['id'] === 'netshoes';

        if ($isNetshoes && Schema::hasColumn('products', 'netshoes_price')) {
            $payload = ['netshoes_price' => $price];
            if (Schema::hasColumn('products', 'netshoes_synced_at')) {
                $payload['netshoes_synced_at'] = now();
            }
            $q->update($payload);
        } elseif ($isSite && Schema::hasColumn('products', 'sale_price')) {
            $q->update(['sale_price' => $price]);
        } elseif ($col && Schema::hasColumn('products', 'channel_prices')) {
            $current = (clone $q)->value('channel_prices');
            $cp = json_decode($current ?? '', true);
            if (!is_array($cp)) $cp = [];
            $cp[$col] = $price;
            $q->update(['channel_prices' => json_encode($cp, JSON_UNESCAPED_UNICODE)]);
        } else {
            return back()->with('error', 'Não há onde gravar o preço deste canal.');
        }

        return back()->with('success', "Preço de {$channel['label']} atualizado para R$ " . number_format($price, 2, ',', '.') . '. Lembre-se de replicar no canal de verdade.');
    }
}
