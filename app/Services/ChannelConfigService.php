<?php

namespace App\Services;

use App\Http\Controllers\Pricing\CalculoPromoController;
use App\Models\ChannelSetting;
use App\Models\PricingSetting;
use Illuminate\Support\Facades\Schema;

/**
 * Fonte única da configuração de precificação por empresa.
 *
 * Entrega a config no MESMO formato de CalculoPromoController::defaultConfig()
 * (para os frontends não mudarem), mas persistida por empresa. Faz seed dos
 * defaults na primeira vez e degrada com segurança para o default do código
 * caso as tabelas ainda não existam (migrations pendentes).
 */
class ChannelConfigService
{
    /** Retorna a config efetiva da empresa, no formato de defaultConfig(). */
    public function forCompany(?int $companyId): array
    {
        $default = CalculoPromoController::defaultConfig();

        if (!$companyId || !$this->tablesReady()) {
            return $default;
        }

        try {
            $this->ensureSeeded($companyId, $default);

            $ps = PricingSetting::where('company_id', $companyId)->first();
            $channels = ChannelSetting::where('company_id', $companyId)
                ->orderBy('sort_order')->get();

            if (!$ps || $channels->isEmpty()) {
                return $default;
            }

            return [
                'imposto' => $ps->imposto,
                'mc' => $ps->mc,
                'descAtualDefault' => $ps->desc_atual_default,
                'descEquilDefault' => $ps->desc_equil_default,
                'rounding' => $ps->rounding,
                'channels' => $channels->map(fn ($c) => [
                    'id' => $c->channel_key,
                    'label' => $c->label,
                    'origem' => $c->origem,
                    'col' => $c->col,
                    'comissao' => $c->comissao,
                    'temFaixa' => $c->tem_faixa,
                    'markup' => $c->markup,
                    'descAtual' => $c->desc_atual,
                    'descEquil' => $c->desc_equil,
                    'active' => $c->active,
                    'warn' => null,
                ])->values()->all(),
            ];
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /** Atualiza os parâmetros globais. */
    public function updateGlobals(int $companyId, array $data): void
    {
        if (!$this->tablesReady()) return;
        PricingSetting::updateOrCreate(['company_id' => $companyId], [
            'imposto' => $data['imposto'] ?? 8,
            'mc' => $data['mc'] ?? 11,
            'desc_atual_default' => $data['descAtualDefault'] ?? 20,
            'desc_equil_default' => $data['descEquilDefault'] ?? 10,
            'rounding' => $data['rounding'] ?? 0.90,
        ]);
    }

    /** Atualiza a tabela de canais (lista completa). */
    public function updateChannels(int $companyId, array $channels): void
    {
        if (!$this->tablesReady()) return;
        foreach (array_values($channels) as $i => $ch) {
            $key = $ch['id'] ?? $ch['channel_key'] ?? null;
            if (!$key) continue;
            ChannelSetting::updateOrCreate(
                ['company_id' => $companyId, 'channel_key' => $key],
                [
                    'label' => $ch['label'] ?? $key,
                    'origem' => $ch['origem'] ?? 'pvatual',
                    'col' => $ch['col'] ?? null,
                    'comissao' => $ch['comissao'] ?? 0,
                    'tem_faixa' => $ch['temFaixa'] ?? 'none',
                    'markup' => $ch['markup'] ?? 23.433,
                    'desc_atual' => $ch['descAtual'] ?? 20,
                    'desc_equil' => $ch['descEquil'] ?? 10,
                    'active' => $ch['active'] ?? true,
                    'sort_order' => $i,
                ]
            );
        }
    }

    /** Restaura os defaults do código para a empresa. */
    public function resetToDefault(int $companyId): void
    {
        if (!$this->tablesReady()) return;
        ChannelSetting::where('company_id', $companyId)->delete();
        PricingSetting::where('company_id', $companyId)->delete();
        $this->ensureSeeded($companyId, CalculoPromoController::defaultConfig());
    }

    private function ensureSeeded(int $companyId, array $default): void
    {
        if (!PricingSetting::where('company_id', $companyId)->exists()) {
            PricingSetting::create([
                'company_id' => $companyId,
                'imposto' => $default['imposto'],
                'mc' => $default['mc'],
                'desc_atual_default' => $default['descAtualDefault'],
                'desc_equil_default' => $default['descEquilDefault'],
                'rounding' => $default['rounding'],
            ]);
        }

        if (!ChannelSetting::where('company_id', $companyId)->exists()) {
            foreach ($default['channels'] as $i => $ch) {
                ChannelSetting::create([
                    'company_id' => $companyId,
                    'channel_key' => $ch['id'],
                    'label' => $ch['label'],
                    'origem' => $ch['origem'],
                    'col' => $ch['col'],
                    'comissao' => $ch['comissao'],
                    'tem_faixa' => $ch['temFaixa'],
                    'markup' => $ch['markup'],
                    'desc_atual' => $ch['descAtual'],
                    'desc_equil' => $ch['descEquil'],
                    'active' => true,
                    'sort_order' => $i,
                ]);
            }
        }
    }

    private function tablesReady(): bool
    {
        try {
            return Schema::hasTable('pricing_settings') && Schema::hasTable('channel_settings');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
