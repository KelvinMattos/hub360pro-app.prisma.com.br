<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ListingSyncService
{
    /**
     * Sincroniza campos específicos de um anúncio template para múltiplos alvos.
     */
    public function syncFieldFromTemplate(array $targetIds, int $templateId, string $field)
    {
        $template = Product::findOrFail($templateId);
        $targets = Product::whereIn('id', $targetIds)->get();

        return DB::transaction(function () use ($template, $targets, $field) {
            $updatedCount = 0;

            foreach ($targets as $target) {
                switch ($field) {
                    case 'title':
                        $target->title = $template->title;
                        break;
                    case 'description':
                        $target->description = $template->description;
                        break;
                    case 'images':
                        $target->image_url = $template->image_url;
                        // Sincronizar galeria (ProductMedia)
                        $this->syncGallery($template, $target);
                        break;
                    case 'prices':
                        $target->sale_price = $template->sale_price;
                        $target->cost_price = $template->cost_price;
                        break;
                }

                if ($target->isDirty()) {
                    $target->save();
                    $updatedCount++;
                }
            }

            return $updatedCount;
        });
    }

    protected function syncGallery($template, $target)
    {
        // Remove imagens antigas do alvo e copia do template
        $target->medias()->delete();

        foreach ($template->medias as $media) {
            $target->medias()->create([
                'url' => $media->url,
                'position' => $media->position,
                'type' => $media->type
            ]);
        }
    }
}
