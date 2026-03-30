<?php

namespace App\Services;

use App\Models\Product;

class QualityAuditService
{
    /**
     * Avalia a qualidade de um anúncio (Raio-X) e retorna um score de 0 a 10.
     */
    public function audit(Product $product): array
    {
        $score = 10;
        $improvements = [];

        // 1. Título (Ideal: 40-60 caracteres)
        $titleLen = mb_strlen($product->title);
        if ($titleLen < 30) {
            $score -= 2;
            $improvements[] = 'Título muito curto (ideal: 40-60 caracteres)';
        } elseif ($titleLen > 60) {
            $score -= 1;
            $improvements[] = 'Título muito longo (pode ser cortado na busca)';
        }

        // 2. Imagens (Mínimo: 3)
        $imageCount = $product->medias()->count();
        if ($imageCount < 1) {
            $score -= 5; // Crítico
            $improvements[] = 'Sem imagens no anúncio';
        } elseif ($imageCount < 3) {
            $score -= 2;
            $improvements[] = 'Poucas imagens (mínimo recomendado: 3)';
        }

        // 3. Descrição
        if (empty($product->description) || mb_strlen($product->description) < 100) {
            $score -= 2;
            $improvements[] = 'Descrição pobre ou inexistente';
        }

        // 4. Atributos (json_data)
        if (empty($product->json_data) || count($product->json_data) < 5) {
            $score -= 1;
            $improvements[] = 'Ficha técnica incompleta';
        }

        return [
            'score' => max(0, $score),
            'improvements' => $improvements,
            'label' => $this->getLabel($score)
        ];
    }

    protected function getLabel(int $score): string
    {
        if ($score >= 8) return 'Excelente';
        if ($score >= 5) return 'Regular';
        return 'Crítico';
    }
}
