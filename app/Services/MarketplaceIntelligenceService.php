<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MarketplaceIntelligenceService
{
    /**
     * Executa a atualização das taxas usando IA com rotação de chaves.
     */
    public function updateRatesFromWeb()
    {
        // 1. Obtém uma chave válida (Rotativa)
        $keyData = $this->getAvailableKey('gemini');
        
        if (!$keyData) {
            Log::error("PrismaHUB AI: Nenhuma chave de API ativa disponível para consulta.");
            return false;
        }

        // 2. O Prompt Especialista (Engenharia de Prompt)
        $prompt = <<<TEXT
Atue como um Especialista em E-commerce e Auditoria Financeira no Brasil.
Sua tarefa é buscar na sua base de conhecimento as taxas de venda ATUALIZADAS (Vigentes em 2025/2026) para os seguintes marketplaces no Brasil: Mercado Livre e Shopee.

Regras Estritas:
1. Ignore taxas antigas. Foque nas políticas mais recentes.
2. Para Mercado Livre, considere a regra de "Abaixo de R$ 79,00 paga taxa fixa".
3. Retorne APENAS um JSON válido, sem markdown (```json), sem explicações.
4. Estrutura do JSON obrigatória:
[
  { "platform": "mercadolibre", "type": "classic", "commission": 13.0, "fixed": 6.00, "threshold": 79.00 },
  { "platform": "mercadolibre", "type": "premium", "commission": 18.0, "fixed": 6.00, "threshold": 79.00 },
  { "platform": "shopee", "type": "standard", "commission": 14.0, "fixed": 3.00, "threshold": 0.00 }
]

Seja preciso. Se houver taxa de transação além da comissão, some na comissão ou no fixo para simplificar.
TEXT;

        try {
            // 3. Chamada à API do Gemini
            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->post("[https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=](https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=){$keyData->api_key}", [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]]
                    ]
                ]);

            if ($response->failed()) {
                throw new \Exception("Gemini API Error: " . $response->body());
            }

            $jsonResponse = $response->json();
            $rawText = $jsonResponse['candidates'][0]['content']['parts'][0]['text'] ?? '';

            // Limpeza do JSON (caso a IA mande markdown)
            $cleanJson = str_replace(['```json', '```'], '', $rawText);
            $rates = json_decode($cleanJson, true);

            if (!is_array($rates)) {
                throw new \Exception("Falha ao decodificar JSON da IA");
            }

            // 4. Atualiza o Banco de Dados (Referência)
            foreach ($rates as $rate) {
                DB::table('marketplace_benchmark_rates')->updateOrInsert(
                    [
                        'platform' => $rate['platform'], 
                        'listing_type' => $rate['type']
                    ],
                    [
                        'commission_percent' => $rate['commission'],
                        'fixed_fee' => $rate['fixed'],
                        'fee_threshold' => $rate['threshold'],
                        'last_check_at' => now(),
                        'updated_via' => 'gemini_ai'
                    ]
                );
            }

            // Atualiza uso da chave
            DB::table('system_ai_keys')->where('id', $keyData->id)->update(['last_used_at' => now()]);
            
            return true;

        } catch (\Exception $e) {
            // Lógica de Falha: Incrementa erro e tenta recursivamente (opcional) ou para
            DB::table('system_ai_keys')->where('id', $keyData->id)->increment('error_count');
            Log::error("PrismaHUB AI Falha (Key ID {$keyData->id}): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sistema de Rotação de Chaves (Load Balancer Simples)
     * Pega uma chave aleatória que esteja ativa e com poucos erros.
     */
    private function getAvailableKey($provider = 'gemini')
    {
        return DB::table('system_ai_keys')
            ->where('provider', $provider)
            ->where('is_active', 1)
            ->where('error_count', '<', 10) // Circuit Breaker: se errar 10x, ignora
            ->inRandomOrder()
            ->first();
    }
}