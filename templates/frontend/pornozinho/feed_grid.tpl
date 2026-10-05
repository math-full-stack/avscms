{* Corpo de um grid de feed: linhas de cards + faixa de anúncio intercalada.
   Fonte ÚNICA da geometria do feed da home — usada por index.tpl (#novos-feed e
   #home-feed) e por include/ajax/novos_feed.php / home_feed.php (scroll infinito).
   Antes a cadência estava duplicada e divergente (12 cards no index, 8 no AJAX).

   Parâmetros (grid_* têm default, os demais são obrigatórios):
     videos        lista de vídeos
     group         grupo de anúncio (index_feed)
     grid_offset   posição global 0-based do 1º card; no scroll infinito use o
                   OFFSET da query para as linhas fecharem alinhadas com a página
                   anterior (default 0)
     grid_cols     classes Bootstrap da coluna (default col-lg-3 = 4 por linha)
     grid_show_tags  default 1
     grid_per_row  cards por linha (default 4)
     grid_ad_rows  linhas entre faixas (default 3 -> 1 faixa a cada 12 cards)

   Contrato: a faixa (ad_feed.tpl) é SEMPRE irmã de .row.content-row, nunca filha.
   Como .row é display:flex e tem margem negativa, uma faixa dentro dela viraria
   item de flex, ficaria 12px mais larga que as colunas e desalinhada.

   Posição da faixa: ela entra ANTES da linha que abre a cada 12 cards (card
   global 13, 25, 37...), nunca depois da última linha do lote. Com
   items_per_front_page = 12 a regra "depois do 12º" caía sempre no fim da
   página — ou seja, entre a última linha e o botão "Exibir mais", como um vão
   em branco. Emitindo antes da linha, a faixa fica de fato ENTRE linhas de
   vídeo.

   `grid_band` (default 1) desliga a faixa — kill-switch manual, ex.:
   /ajax/novos_feed?page=2&band=0.

   Atenção: a tag do anúncio (<script> + <ins>) só roda se o HTML for inserido
   pelo navegador, não via innerHTML. O lote do scroll infinito passa por
   xb-home-feed.js, que recria os scripts (runScripts) justamente para as faixas
   das páginas seguintes renderizarem.

   Quando grid_offset não fecha a linha, o 1º grupo sai embrulhado em
   .xb-feed-cont e o JS do feed move esse conteúdo para a última linha já
   renderizada em vez de criar uma linha nova. *}
{if !isset($grid_offset)}{assign var=grid_offset value=0}{/if}
{if !isset($grid_per_row)}{assign var=grid_per_row value=4}{/if}
{if !isset($grid_ad_rows)}{assign var=grid_ad_rows value=3}{/if}
{if !isset($grid_cols)}{assign var=grid_cols value='col-12 col-sm-6 col-md-4 col-lg-3'}{/if}
{if !isset($grid_show_tags)}{assign var=grid_show_tags value=1}{/if}
{if !isset($grid_band)}{assign var=grid_band value=1}{/if}
{assign var=grid_ad_span value=$grid_per_row*$grid_ad_rows}
{assign var=grid_slot value=0}
{section name=fg loop=$videos}
	{assign var=grid_pos value=$grid_offset+$smarty.section.fg.iteration}
	{assign var=grid_slot value=$grid_pos%$grid_per_row}
	{if $grid_slot == 1 || $smarty.section.fg.iteration == 1}
		{if $smarty.section.fg.iteration == 1 && $grid_offset%$grid_per_row != 0}
	<div class="xb-feed-cont">
		{else}
			{if $grid_band && $grid_slot == 1 && $grid_pos > 1 && ($grid_pos-1)%$grid_ad_span == 0}
	{include file='ad_feed.tpl' group=$group}
			{/if}
	<div class="row content-row">
		{/if}
	{/if}
	{if $grid_offset == 0 && $smarty.section.fg.iteration <= 6}{assign var=grid_fp value='high'}{else}{assign var=grid_fp value='lazy'}{/if}
	{include file='video_card.tpl' v=$videos[fg] card_cols=$grid_cols show_tags=$grid_show_tags fetchpriority=$grid_fp}
	{if $grid_slot == 0}
	</div>
	{/if}
{/section}
{if $grid_slot != 0}
</div>
{/if}
