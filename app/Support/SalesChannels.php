<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Canais do relatório "Diário de Vendas" (uma aba por canal) e "Geral"
 * (dashboard mensal/anual) que o cliente mantinha manualmente em planilha.
 *
 * A chave canônica aqui é a granularidade de CONTA/CANAL de venda usada
 * nesses dois relatórios — não é a mesma coisa que o `channel_key` de
 * ChannelSetting/CalculoPromo (que é granularidade de FAIXA DE COMISSÃO
 * pra simulação de preço). Mercado Livre, por exemplo, aparece ali dividido
 * por comissão (ml_classico/ml_premium) e aqui por CONTA (matriz/filial) —
 * são dimensões diferentes do mesmo canal, cada tela usa a que faz sentido
 * pra sua pergunta.
 */
class SalesChannels
{
    /** Ordem de exibição = ordem das abas na planilha original do cliente. */
    public const LABELS = [
        'mercado_livre_matriz' => 'Mercado Livre - Matriz',
        'mercado_livre_filial' => 'Mercado Livre - Filial',
        'mercado_livre' => 'Mercado Livre',
        'netshoes' => 'Netshoes',
        'netshoes_full' => 'Netshoes FULL',
        'centauro' => 'Centauro',
        'site' => 'Site',
        'amazon' => 'Amazon',
        'shopee' => 'Shopee',
        'renner' => 'Renner',
        'magalu' => 'Magalu',
        'casas_bahia' => 'Grupo Casas Bahia',
        'dafiti' => 'Dafiti',
        'shop_coopera' => 'Shop Coopera',
        'fba_amazon' => 'FBA Amazon',
        'outros' => 'Outros / Não identificado',
    ];

    /**
     * Canais que, somados, formam o "Mercado Livre" consolidado do dashboard Geral.
     * `mercado_livre` (genérico) entra aqui também: é o balde de pedidos importados
     * automaticamente cuja conta (Matriz/Filial) não deu pra identificar — ainda assim
     * é venda do Mercado Livre e precisa contar no total, só não aparece separado por
     * conta na aba "Conta Mercado Livre".
     */
    public const MERCADO_LIVRE_GROUP = ['mercado_livre_matriz', 'mercado_livre_filial', 'mercado_livre'];

    public static function label(string $channel): string
    {
        return self::LABELS[$channel] ?? $channel;
    }

    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    public static function isValid(string $channel): bool
    {
        return isset(self::LABELS[$channel]);
    }

    /**
     * Identifica o canal canônico a partir do nome da aba da planilha
     * (ex.: "MERCADO LIVRE - MATRIZ (MAR-26)", "NETSHOES - (MAR-26)").
     * Normaliza acento/caixa e casa por palavra-chave — nunca por posição,
     * pra sobreviver a pequenas variações de nome mês a mês (CLAUDE.md §2.4:
     * parser defensivo). Retorna null se a aba não for reconhecida (o
     * importador ignora essa aba em vez de gravar num canal fabricado).
     *
     * Ordem importa: termos mais específicos (FULL, FBA) são checados antes
     * dos genéricos (NETSHOES, AMAZON) pra não caírem no canal errado.
     */
    public static function fromSheetName(string $sheetName): ?string
    {
        $n = mb_strtoupper(Str::ascii($sheetName));

        return match (true) {
            str_contains($n, 'MERCADO LIVRE') && str_contains($n, 'MATRIZ') => 'mercado_livre_matriz',
            str_contains($n, 'MERCADO LIVRE') && str_contains($n, 'FILIAL') => 'mercado_livre_filial',
            str_contains($n, 'NETSHOES') && str_contains($n, 'FULL') => 'netshoes_full',
            str_contains($n, 'NETSHOES') => 'netshoes',
            str_contains($n, 'CENTAURO') => 'centauro',
            str_contains($n, 'SITE') => 'site',
            str_contains($n, 'AMAZON') && str_contains($n, 'FBA') => 'fba_amazon',
            str_contains($n, 'FBA') => 'fba_amazon',
            str_contains($n, 'AMAZON') => 'amazon',
            str_contains($n, 'SHOPEE') => 'shopee',
            str_contains($n, 'RENNER') => 'renner',
            str_contains($n, 'MAGALU') || str_contains($n, 'MAGAZINE LUIZA') => 'magalu',
            str_contains($n, 'VIA VAREJO') || str_contains($n, 'CASAS BAHIA') => 'casas_bahia',
            str_contains($n, 'DAFITI') => 'dafiti',
            str_contains($n, 'SHOP COOPERA') || str_contains($n, 'SHOPCOOPERA') => 'shop_coopera',
            // Mercado Livre sem indicação de conta (Matriz/Filial) — só cai aqui
            // depois de checar os dois casos específicos acima.
            str_contains($n, 'MERCADO LIVRE') || str_contains($n, 'MERCADOLIVRE') => 'mercado_livre',
            default => null,
        };
    }

    /**
     * Mesmo casamento por palavra-chave de {@see fromSheetName()}, mas usado
     * pra texto livre gravado em `orders.selling_channel` pelos importadores
     * nativos por canal (cada um grava o que faz sentido pro seu export —
     * "Mercado Livre", "Magazine Luiza", "Shopee" etc., ver
     * OrderChannelImportController/NetshoesImportController/MagazordImportController),
     * usado pela Central de Desempenho por Canal pra classificar pedidos
     * automaticamente sem depender de upload manual (pedido do cliente
     * 05/08/2026: "não há lógica em importar manualmente uma informação que
     * outras planilhas já informaram").
     */
    public static function fromFreeText(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        return self::fromSheetName($raw);
    }
}
