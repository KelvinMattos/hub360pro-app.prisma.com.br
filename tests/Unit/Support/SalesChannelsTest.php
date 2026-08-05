<?php

namespace Tests\Unit\Support;

use App\Support\SalesChannels;
use Tests\TestCase;

/**
 * Pedido do cliente (05/08/2026): a coluna "Origem Pedido" do relatório
 * geral Magazord traz "MANUAL" pra vendas online feitas por vendedor em
 * atendimento — ainda é canal Site, não um canal à parte.
 */
class SalesChannelsTest extends TestCase
{
    public function test_manual_classifica_como_site(): void
    {
        $this->assertSame('site', SalesChannels::fromFreeText('MANUAL'));
        $this->assertSame('site', SalesChannels::fromFreeText('manual'));
        $this->assertSame('site', SalesChannels::fromFreeText(' Manual '));
    }

    public function test_texto_livre_dos_importadores_nativos_e_reconhecido(): void
    {
        $this->assertSame('shopee', SalesChannels::fromFreeText('Shopee'));
        $this->assertSame('centauro', SalesChannels::fromFreeText('Centauro'));
        $this->assertSame('renner', SalesChannels::fromFreeText('Renner'));
        $this->assertSame('magalu', SalesChannels::fromFreeText('Magazine Luiza'));
        $this->assertSame('site', SalesChannels::fromFreeText('Site'));
    }

    public function test_mercado_livre_sem_conta_cai_no_balde_generico(): void
    {
        $this->assertSame('mercado_livre', SalesChannels::fromFreeText('Mercado Livre'));
        $this->assertSame('mercado_livre_matriz', SalesChannels::fromFreeText('Mercado Livre - Matriz'));
    }

    public function test_canal_desconhecido_retorna_null(): void
    {
        $this->assertNull(SalesChannels::fromFreeText('Canal Que Ninguém Mapeou'));
        $this->assertNull(SalesChannels::fromFreeText(null));
        $this->assertNull(SalesChannels::fromFreeText(''));
    }
}
