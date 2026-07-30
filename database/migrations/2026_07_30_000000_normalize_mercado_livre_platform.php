<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normaliza integrations.platform para Mercado Livre. O OAuth gravava a
 * credencial por conta como 'mercadolivre' enquanto o restante do fluxo
 * (config de chaves, refresh de token, sync de produtos) usava
 * 'mercadolibre' — mesma integração, duas grafias, metade do sistema não
 * enxergava a outra metade das contas conectadas.
 *
 * Só normaliza dado — não apaga nem recria nenhuma linha.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('integrations') || !Schema::hasColumn('integrations', 'platform')) {
            return;
        }

        DB::table('integrations')
            ->whereIn('platform', ['mercadolivre', 'mercado_livre'])
            ->update(['platform' => 'mercadolibre']);
    }

    public function down(): void
    {
        // Normalização de dado não tem "desfazer" seguro (não sabemos qual
        // grafia original cada linha tinha) — down() é deliberadamente vazio.
    }
};
