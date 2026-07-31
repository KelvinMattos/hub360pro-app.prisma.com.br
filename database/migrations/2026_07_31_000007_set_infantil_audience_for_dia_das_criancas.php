<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Incidente: o kit sugerido pro Dia das Crianças trazia produtos masculino/
 * feminino de adulto — essa data também precisa de filtro de público, só
 * que por INCLUSÃO (exige marcador infantil/juvenil no título), diferente
 * de Mães/Pais (exclusão por gênero oposto). Ver CampaignController::AUDIENCE_RULES.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('commercial_dates') || !Schema::hasColumn('commercial_dates', 'audience')) {
            return;
        }

        DB::table('commercial_dates')->where('source', 'seed')->where('title', 'Dia das Crianças')->update(['audience' => 'infantil']);
    }

    public function down(): void
    {
        if (!Schema::hasTable('commercial_dates') || !Schema::hasColumn('commercial_dates', 'audience')) {
            return;
        }

        DB::table('commercial_dates')->where('source', 'seed')->where('title', 'Dia das Crianças')->where('audience', 'infantil')->update(['audience' => null]);
    }
};
