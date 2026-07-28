<?php

namespace App\Services\Netshoes;

use Illuminate\Support\Facades\Http;

/**
 * Coleta de Buy Box na Netshoes a partir do SKU Netshoes (que é universal
 * entre sellers — todos anunciam o mesmo SKU no mesmo produto).
 *
 * Retorna: preço vencedor, loja vencedora, link do anúncio e nº de ofertas.
 *
 * O parsing usa uma CADEIA DE ESTRATÉGIAS, da mais estável para a mais frágil:
 *   1. JSON-LD (schema.org Product/Offer/AggregateOffer) — padrão e estável
 *   2. JSON embutido no HTML (__NEXT_DATA__ / __INITIAL_STATE__ / apollo)
 *   3. Regex direto no HTML (último recurso)
 *
 * Cada resposta informa qual estratégia funcionou (`strategy`), o que permite
 * diagnosticar e ajustar sem adivinhação quando o site muda de layout.
 *
 * Boas práticas de coleta: User-Agent real, timeout curto, no máximo 1 retry,
 * e um intervalo entre requisições (pausa) definido pelo chamador.
 */
class NetshoesBuyBoxScraper
{
    public const DEFAULTS = [
        'search_url' => 'https://www.netshoes.com.br/busca?q={code}',
        'timeout' => 20,
        'delay_ms' => 1500,
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
    ];

    /**
     * Consulta um SKU e devolve os dados de Buy Box.
     *
     * @return array{ok:bool,price:?float,seller:?string,url:?string,offers:?int,strategy:?string,http:?int,error:?string,html_len:int}
     */
    public function fetch(string $netshoesSku, array $opts = []): array
    {
        $opts = array_merge(self::DEFAULTS, array_filter($opts, fn ($v) => $v !== null && $v !== ''));
        $code = $this->productCode($netshoesSku);
        $url = str_replace(['{code}', '{sku}'], [rawurlencode($code), rawurlencode($netshoesSku)], $opts['search_url']);

        $out = [
            'ok' => false, 'price' => null, 'seller' => null, 'url' => null,
            'offers' => null, 'strategy' => null, 'http' => null, 'error' => null,
            'html_len' => 0, 'requested_url' => $url, 'code' => $code,
        ];

        try {
            $res = Http::withHeaders([
                    'User-Agent' => $opts['user_agent'],
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'pt-BR,pt;q=0.9,en;q=0.8',
                ])
                ->timeout((int) $opts['timeout'])
                ->retry(1, 800, throw: false)
                ->get($url);

            $out['http'] = $res->status();
            $html = (string) $res->body();
            $out['html_len'] = strlen($html);
            $out['url'] = (string) ($res->effectiveUri() ?? $url);

            if (!$res->successful()) {
                $out['error'] = 'HTTP ' . $res->status();
                return $out;
            }
            if ($html === '') {
                $out['error'] = 'Resposta vazia';
                return $out;
            }

            foreach (['parseJsonLd', 'parseEmbeddedJson', 'parseRegex'] as $strategy) {
                $hit = $this->{$strategy}($html);
                if ($hit && ($hit['price'] ?? null)) {
                    $out['price'] = $hit['price'];
                    $out['seller'] = $hit['seller'] ?? null;
                    $out['offers'] = $hit['offers'] ?? null;
                    if (!empty($hit['url'])) {
                        $out['url'] = $hit['url'];
                    }
                    $out['strategy'] = $strategy;
                    $out['ok'] = true;
                    return $out;
                }
            }

            $out['error'] = 'Preço não encontrado no HTML (layout pode ter mudado)';
        } catch (\Throwable $e) {
            $out['error'] = substr($e->getMessage(), 0, 250);
        }

        return $out;
    }

    /**
     * Código do produto a partir do SKU (remove o sufixo de tamanho).
     * Ex.: "39V-24AJ-205-43" -> "39V-24AJ-205"; "16037-ARESOL-ARENITO-38" -> "16037-ARESOL-ARENITO".
     * Sellers diferentes disputam o MESMO produto, então a busca é feita no
     * nível do produto (sem o tamanho).
     */
    public function productCode(string $sku): string
    {
        $sku = trim($sku);
        $parts = explode('-', $sku);
        if (count($parts) > 1) {
            $last = strtoupper(end($parts));
            // tamanho numérico (33..48) ou literal (P, M, G, GG, XG, PP, U, UN)
            if (preg_match('/^\d{1,3}$/', $last) || in_array($last, ['P', 'M', 'G', 'GG', 'XG', 'XGG', 'PP', 'U', 'UN'], true)) {
                array_pop($parts);
                return implode('-', $parts);
            }
        }
        return $sku;
    }

    /* ============================ estratégias ============================ */

    /** 1) JSON-LD schema.org — a fonte mais estável quando existe. */
    private function parseJsonLd(string $html): ?array
    {
        if (!preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m)) {
            return null;
        }

        foreach ($m[1] as $raw) {
            $data = json_decode(trim($raw), true);
            if (!is_array($data)) {
                continue;
            }
            foreach ($this->flattenGraph($data) as $node) {
                $type = $node['@type'] ?? null;
                $type = is_array($type) ? implode(',', $type) : (string) $type;
                if (stripos($type, 'Product') === false) {
                    continue;
                }
                $offers = $node['offers'] ?? null;
                if (!$offers) {
                    continue;
                }
                $hit = $this->fromOffers($offers);
                if ($hit) {
                    $hit['url'] = $hit['url'] ?? ($node['url'] ?? null);
                    return $hit;
                }
            }
        }
        return null;
    }

    /** Achata @graph / listas para varrer todos os nós. */
    private function flattenGraph(array $data): array
    {
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            return $data['@graph'];
        }
        // lista de nós ou nó único
        return array_is_list($data) ? $data : [$data];
    }

    /** Extrai preço/seller/contagem de um bloco offers (Offer ou AggregateOffer). */
    private function fromOffers($offers): ?array
    {
        if (!is_array($offers)) {
            return null;
        }

        // AggregateOffer: menor preço + nº de ofertas
        $type = $offers['@type'] ?? null;
        $type = is_array($type) ? implode(',', $type) : (string) $type;
        if (stripos($type, 'AggregateOffer') !== false) {
            $price = $this->toFloat($offers['lowPrice'] ?? $offers['price'] ?? null);
            $count = isset($offers['offerCount']) ? (int) $offers['offerCount'] : null;
            $seller = $this->sellerName($offers['seller'] ?? $offers['offeredBy'] ?? null);

            // Alguns sites aninham a lista real dentro de "offers"
            if (isset($offers['offers']) && is_array($offers['offers'])) {
                $best = $this->bestOfferFromList($offers['offers']);
                if ($best) {
                    return [
                        'price' => $best['price'] ?? $price,
                        'seller' => $best['seller'] ?? $seller,
                        'offers' => $count ?? $best['offers'] ?? null,
                        'url' => $best['url'] ?? null,
                    ];
                }
            }
            if ($price) {
                return ['price' => $price, 'seller' => $seller, 'offers' => $count, 'url' => $offers['url'] ?? null];
            }
            return null;
        }

        // Lista de Offers -> pega a mais barata (a que ganha a Buy Box)
        if (array_is_list($offers)) {
            return $this->bestOfferFromList($offers);
        }

        // Offer único
        $price = $this->toFloat($offers['price'] ?? $offers['lowPrice'] ?? null);
        if (!$price) {
            return null;
        }
        return [
            'price' => $price,
            'seller' => $this->sellerName($offers['seller'] ?? $offers['offeredBy'] ?? null),
            'offers' => 1,
            'url' => $offers['url'] ?? null,
        ];
    }

    private function bestOfferFromList(array $list): ?array
    {
        $best = null;
        $n = 0;
        foreach ($list as $o) {
            if (!is_array($o)) {
                continue;
            }
            $p = $this->toFloat($o['price'] ?? $o['lowPrice'] ?? null);
            if (!$p) {
                continue;
            }
            $n++;
            if ($best === null || $p < $best['price']) {
                $best = [
                    'price' => $p,
                    'seller' => $this->sellerName($o['seller'] ?? $o['offeredBy'] ?? null),
                    'url' => $o['url'] ?? null,
                ];
            }
        }
        if ($best) {
            $best['offers'] = $n;
        }
        return $best;
    }

    private function sellerName($seller): ?string
    {
        if (is_string($seller)) {
            return trim($seller) ?: null;
        }
        if (is_array($seller)) {
            $n = $seller['name'] ?? $seller['legalName'] ?? null;
            return is_string($n) ? (trim($n) ?: null) : null;
        }
        return null;
    }

    /** 2) JSON embutido (Next.js/Nuxt/Apollo) — busca profunda por chaves conhecidas. */
    private function parseEmbeddedJson(string $html): ?array
    {
        $blobs = [];
        $patterns = [
            '#<script[^>]*id=["\']__NEXT_DATA__["\'][^>]*>(.*?)</script>#is',
            '#window\.__INITIAL_STATE__\s*=\s*(\{.*?\});?\s*</script>#is',
            '#window\.__APOLLO_STATE__\s*=\s*(\{.*?\});?\s*</script>#is',
            '#window\.__PRELOADED_STATE__\s*=\s*(\{.*?\});?\s*</script>#is',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $html, $m)) {
                $blobs[] = $m[1];
            }
        }

        foreach ($blobs as $raw) {
            $data = json_decode(trim($raw), true);
            if (!is_array($data)) {
                continue;
            }
            $found = $this->deepFindOffer($data);
            if ($found && ($found['price'] ?? null)) {
                return $found;
            }
        }
        return null;
    }

    /** Varredura recursiva procurando um par preço + vendedor. */
    private function deepFindOffer(array $node, int $depth = 0): ?array
    {
        if ($depth > 12) {
            return null;
        }

        $priceKeys = ['price', 'salePrice', 'finalPrice', 'bestPrice', 'currentPrice', 'lowPrice', 'priceValue'];
        $sellerKeys = ['sellerName', 'seller_name', 'seller', 'sellerid', 'storeName', 'store', 'partner', 'partnerName'];

        $price = null;
        $seller = null;
        foreach ($priceKeys as $k) {
            foreach ($node as $key => $val) {
                if (strcasecmp((string) $key, $k) === 0) {
                    $p = $this->toFloat($val);
                    if ($p && ($price === null || $p < $price)) {
                        $price = $p;
                    }
                }
            }
        }
        foreach ($sellerKeys as $k) {
            foreach ($node as $key => $val) {
                if (strcasecmp((string) $key, $k) === 0) {
                    $s = $this->sellerName($val);
                    if ($s !== null && $seller === null) {
                        $seller = $s;
                    }
                }
            }
        }
        if ($price && $seller) {
            return ['price' => $price, 'seller' => $seller, 'offers' => null, 'url' => $node['url'] ?? null];
        }

        $partial = $price ? ['price' => $price, 'seller' => null, 'offers' => null, 'url' => null] : null;

        foreach ($node as $val) {
            if (is_array($val)) {
                $deep = $this->deepFindOffer($val, $depth + 1);
                if ($deep && ($deep['price'] ?? null) && ($deep['seller'] ?? null)) {
                    return $deep; // preferimos um par completo
                }
                if ($deep && $partial === null) {
                    $partial = $deep;
                }
            }
        }
        return $partial;
    }

    /** 3) Regex no HTML — último recurso. */
    private function parseRegex(string $html): ?array
    {
        $price = null;
        $seller = null;
        $url = null;

        foreach ([
            '#"(?:lowPrice|bestPrice|salePrice|finalPrice|price)"\s*:\s*"?([\d.,]+)"?#i',
            '#itemprop=["\']price["\'][^>]*content=["\']([\d.,]+)["\']#i',
            '#R\$\s*([\d.]+,\d{2})#',
        ] as $p) {
            if (preg_match($p, $html, $m)) {
                $price = $this->toFloat($m[1]);
                if ($price) {
                    break;
                }
            }
        }
        foreach ([
            '#"(?:sellerName|storeName|partnerName)"\s*:\s*"([^"]{2,80})"#i',
            '#vendido\s+e\s+entregue\s+por[:\s]*</?[^>]*>?\s*([^<\n]{2,60})#i',
        ] as $p) {
            if (preg_match($p, $html, $m)) {
                $seller = trim(strip_tags($m[1]));
                if ($seller !== '') {
                    break;
                }
            }
        }
        if (preg_match('#<meta[^>]+property=["\']og:url["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $m)) {
            $url = $m[1];
        }

        return $price ? ['price' => $price, 'seller' => $seller, 'offers' => null, 'url' => $url] : null;
    }

    /** Converte "1.234,56" / "1234.56" / 1234.56 em float. */
    private function toFloat($v): ?float
    {
        if ($v === null || is_bool($v) || is_array($v)) {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            $f = (float) $v;
            // muitos payloads trazem centavos como inteiro (ex.: 19990 = 199,90)
            return $f > 0 ? $f : null;
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        $s = preg_replace('/[^\d.,]/', '', $s);
        $hasComma = str_contains($s, ',');
        $hasDot = str_contains($s, '.');
        if ($hasComma && $hasDot) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif ($hasComma) {
            $s = str_replace(',', '.', $s);
        }
        return is_numeric($s) && (float) $s > 0 ? (float) $s : null;
    }

    /** Normaliza nome de loja para comparação (acentos/caixa/pontuação). */
    public static function normalizeSeller(?string $name): string
    {
        if (!$name) {
            return '';
        }
        $n = mb_strtolower(trim($name));
        $n = strtr($n, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'ê' => 'e', 'è' => 'e', 'í' => 'i', 'ì' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        return preg_replace('/[^a-z0-9]/', '', $n) ?? '';
    }
}
