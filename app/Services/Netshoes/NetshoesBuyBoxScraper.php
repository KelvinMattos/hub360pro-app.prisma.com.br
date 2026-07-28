<?php

namespace App\Services\Netshoes;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Coleta de Buy Box na Netshoes a partir do SKU Netshoes.
 *
 * ⚠️ ESTADO ATUAL: a Netshoes BLOQUEIA requisições server-side (403 Access
 * Denied na borda Akamai). Este coletor fica DESLIGADO por padrão e existe
 * para diagnóstico. A fonte oficial de preço de mercado é o relatório de Buy
 * Box do Seller Center / API autorizada / planilha (ver MarketPriceImport).
 * Não há — e não deve haver — tentativa de contornar o bloqueio.
 *
 * PREMISSAS VALIDADAS NO HTML REAL (PDP Netshoes):
 *  - A busca por SKU funciona: /busca?q={code}; a PDP é /p/{slug}-{code}.
 *  - NÃO existe __NEXT_DATA__ na página (estratégia removida).
 *  - O JSON-LD traz apenas AggregateOffer: {lowPrice, highPrice, offerCount}
 *    e NÃO traz o vendedor.
 *  - CRÍTICO: `lowPrice` é o preço à vista/PIX, NÃO o preço do anúncio.
 *    Ex.: lowPrice=125,47 (PIX) vs. preço real da Buy Box=154,90 (highPrice).
 *    Usar lowPrice grava ~19% abaixo do real em todo o catálogo — por isso o
 *    preço de mercado vem de `highPrice` e o lowPrice é devolvido à parte,
 *    apenas informativo.
 *  - `offerCount` conta faixas de preço, NÃO número de sellers — não deve ser
 *    usado como "quantos concorrentes".
 *  - O vendedor só aparece no texto renderizado: "Vendido por <loja>".
 */
class NetshoesBuyBoxScraper
{
    public const DEFAULTS = [
        'search_url' => 'https://www.netshoes.com.br/busca?q={code}',
        'timeout' => 20,
        'delay_ms' => 1500,
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
    ];

    /** Resultado vazio/padrão — nunca "meio preenchido". */
    private function base(string $url, string $code): array
    {
        return [
            'ok' => false,
            'status' => 'error',   // ok | blocked | not_found | no_price | error
            'http' => null,
            'price' => null,       // preço do anúncio (highPrice) — o preço de mercado
            'pix_price' => null,   // lowPrice (à vista/PIX) — NÃO é preço de mercado
            'seller' => null,
            'url' => null,
            'offers' => null,      // offerCount (faixas de preço, não sellers)
            'strategy' => null,
            'error' => null,
            'html_len' => 0,
            'requested_url' => $url,
            'code' => $code,
        ];
    }

    /**
     * Consulta um SKU. Só devolve ok=true com HTTP 200 E preço extraído —
     * qualquer outro caso é reportado com o status real (nunca grava preço).
     */
    public function fetch(string $netshoesSku, array $opts = []): array
    {
        $opts = array_merge(self::DEFAULTS, array_filter($opts, fn ($v) => $v !== null && $v !== ''));
        $code = $this->productCode($netshoesSku);
        $url = str_replace(['{code}', '{sku}'], [rawurlencode($code), rawurlencode($netshoesSku)], $opts['search_url']);

        $out = $this->base($url, $code);

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

            // Bloqueio de borda (Akamai) — distinto de "não encontrado".
            if (in_array($res->status(), [401, 403, 405, 406, 429], true)
                || stripos($html, 'Access Denied') !== false
                || stripos($html, 'akamai') !== false && $res->status() >= 400) {
                $out['status'] = 'blocked';
                $out['error'] = "Bloqueado pelo site (HTTP {$res->status()}). A Netshoes não permite coleta server-side.";
                Log::warning('[netshoes-scraper] bloqueado', ['sku' => $netshoesSku, 'http' => $res->status(), 'url' => $url]);
                return $out;
            }

            if ($res->status() === 404) {
                $out['status'] = 'not_found';
                $out['error'] = 'Produto não encontrado (HTTP 404).';
                return $out;
            }

            if (!$res->successful()) {
                $out['status'] = 'error';
                $out['error'] = 'HTTP ' . $res->status();
                Log::warning('[netshoes-scraper] resposta não-200', ['sku' => $netshoesSku, 'http' => $res->status()]);
                return $out;
            }

            if ($html === '') {
                $out['status'] = 'error';
                $out['error'] = 'Resposta vazia (HTTP 200 sem corpo).';
                return $out;
            }

            // --- extração (só a partir daqui, com HTTP 200 garantido) ---
            $ld = $this->parseJsonLd($html);
            $seller = $this->extractSeller($html);

            if ($ld && ($ld['price'] ?? null)) {
                $out['price'] = $ld['price'];        // highPrice = preço do anúncio
                $out['pix_price'] = $ld['pix_price'] ?? null;
                $out['offers'] = $ld['offers'] ?? null;
                $out['url'] = $ld['url'] ?? $out['url'];
                $out['strategy'] = 'jsonld_aggregate';
            } else {
                $rx = $this->parseRegex($html);
                if ($rx && ($rx['price'] ?? null)) {
                    $out['price'] = $rx['price'];
                    $out['url'] = $rx['url'] ?? $out['url'];
                    $out['strategy'] = 'regex';
                }
            }

            $out['seller'] = $seller;

            if ($out['price'] === null) {
                $out['status'] = 'no_price';
                $out['error'] = 'HTTP 200, mas nenhum preço encontrado no HTML (layout pode ter mudado).';
                Log::warning('[netshoes-scraper] sem preço', ['sku' => $netshoesSku, 'html_len' => $out['html_len']]);
                return $out;
            }

            $out['ok'] = true;
            $out['status'] = 'ok';
        } catch (\Throwable $e) {
            $out['status'] = 'error';
            $out['error'] = substr($e->getMessage(), 0, 250);
            Log::warning('[netshoes-scraper] exceção', ['sku' => $netshoesSku, 'erro' => $out['error']]);
        }

        return $out;
    }

    /**
     * Código do produto a partir do SKU (remove o sufixo de tamanho).
     * Ex.: "39V-24AJ-205-43" -> "39V-24AJ-205"; "I6E-7247-060" fica igual.
     */
    public function productCode(string $sku): string
    {
        $sku = trim($sku);
        $parts = explode('-', $sku);
        if (count($parts) > 3) {
            $last = strtoupper(end($parts));
            if (preg_match('/^\d{1,3}$/', $last) || in_array($last, ['P', 'M', 'G', 'GG', 'XG', 'XGG', 'PP', 'U', 'UN'], true)) {
                array_pop($parts);
                return implode('-', $parts);
            }
        }
        return $sku;
    }

    /* ============================ extração ============================ */

    /**
     * JSON-LD (AggregateOffer). Devolve highPrice como preço de mercado e
     * lowPrice separado como preço PIX — jamais o contrário.
     */
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
            $nodes = isset($data['@graph']) && is_array($data['@graph'])
                ? $data['@graph']
                : (array_is_list($data) ? $data : [$data]);

            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $type = $node['@type'] ?? null;
                $type = is_array($type) ? implode(',', $type) : (string) $type;
                if (stripos($type, 'Product') === false) {
                    continue;
                }
                $offers = $node['offers'] ?? null;
                if (!is_array($offers)) {
                    continue;
                }

                $high = $this->toFloat($offers['highPrice'] ?? null);
                $low = $this->toFloat($offers['lowPrice'] ?? null);
                $single = $this->toFloat($offers['price'] ?? null);

                // Preço do anúncio: highPrice quando existe (lowPrice é PIX).
                $price = $high ?? $single;
                if (!$price) {
                    continue;
                }

                return [
                    'price' => $price,
                    'pix_price' => ($low !== null && $high !== null && $low < $high) ? $low : null,
                    'offers' => isset($offers['offerCount']) ? (int) $offers['offerCount'] : null,
                    'url' => $node['url'] ?? null,
                ];
            }
        }
        return null;
    }

    /**
     * Vendedor a partir do texto renderizado ("Vendido por X", "Vendido e
     * entregue por X"), parando antes de "Enviado por".
     */
    public function extractSeller(string $html): ?string
    {
        $text = preg_replace('/\s+/u', ' ', strip_tags($html));
        if (!is_string($text) || $text === '') {
            return null;
        }
        $patterns = [
            '/Vendido\s+e\s+entregue\s+por\s*:?\s*(.{2,60}?)\s*(?:Enviado|Entregue|Garantia|\||\.|$)/iu',
            '/Vendido\s+por\s*:?\s*(.{2,60}?)\s*(?:Enviado|Entregue|Garantia|\||\.|$)/iu',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $text, $m)) {
                $s = trim($m[1], " \t\n\r\0\x0B:-–—");
                if ($s !== '' && mb_strlen($s) >= 2) {
                    return $s;
                }
            }
        }
        return null;
    }

    /** Último recurso: preço no HTML. Nunca capta "a partir de"/PIX. */
    private function parseRegex(string $html): ?array
    {
        $price = null;
        $url = null;

        foreach ([
            '#itemprop=["\']price["\'][^>]*content=["\']([\d.,]+)["\']#i',
            '#"(?:highPrice|listPrice|sellingPrice)"\s*:\s*"?([\d.,]+)"?#i',
        ] as $p) {
            if (preg_match($p, $html, $m)) {
                $price = $this->toFloat($m[1]);
                if ($price) {
                    break;
                }
            }
        }
        if (preg_match('#<meta[^>]+property=["\']og:url["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $m)) {
            $url = $m[1];
        }

        return $price ? ['price' => $price, 'url' => $url] : null;
    }

    /** Converte "1.234,56" / "1234.56" / 1234.56 em float. */
    private function toFloat($v): ?float
    {
        if ($v === null || is_bool($v) || is_array($v)) {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v > 0 ? (float) $v : null;
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
