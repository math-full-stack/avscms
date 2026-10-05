{* Faixa de anúncio full-width intercalada no grid de vídeos (entre as linhações).
   Recebe `group` com o nome do grupo (ex.: index_feed, videos_feed).

   Quem chama: apenas feed_grid.tpl, que é a geometria única do feed — home
   (index.tpl #novos-feed/#home-feed), listagem (videos.tpl) e scroll infinito
   (include/ajax/novos_feed.php e home_feed.php). O feed de shorts tem sistema
   próprio (include/ajax/shorts_feed.php).

   CONTRATO: este bloco é IRMÃO de .row.content-row, nunca filho. `.row` é
   display:flex e ainda tem margem negativa; uma faixa dentro dela vira item de
   flex, fica 12px mais larga que as colunas e desalinha o grid. Por isso quem
   emite a faixa precisa fechar a linha antes (ver feed_grid.tpl).

   Sem anúncio NÃO renderiza nada: o placeholder "PATROCINADORES / <GRUPO>"
   serve para as laterais (slots do dono do site), mas no meio da grade de
   vídeos ele aparece para o visitante como caixa em branco. Grupo sem anúncio
   ativo simplesmente não tem faixa.

   `{if $adv && $adv.ad}` (e não `$adv.ad` direto) evita warning de PHP quando
   insert_adv() devolve false (anúncios desligados no config) ou null (grupo
   inexistente). *}
{insert name=adv assign=adv group=$group}
{if $adv && $adv.ad}
<div class="xb-feed-ad">
	<div class="ad-content">
		{$adv.ad}
	</div>
</div>
{/if}
