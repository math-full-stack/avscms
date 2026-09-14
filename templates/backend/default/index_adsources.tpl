<!-- BEGIN PAGE CONTAINER-->
	<div class="page-content"> 
		<div class="content">
			<!-- BEGIN PAGE TITLE -->
			<div class="page-title">
				<i class="icon-custom-left"></i>
				<h3>Settings - <span class="semi-bold">Ad Sources</span></h3>
			</div>
			{include file="errmsg.tpl"}
			<!-- END PAGE TITLE -->
			<!-- BEGIN PLACE PAGE CONTENT HERE -->

			<div class="col-md-12">
				<div class="grid simple no-border">
					<div class="grid-body no-border">
						<div class="alert alert-info">
							<button class="close" data-dismiss="alert"></button>
							<i class="fa fa-sitemap"></i>
							Uma <b>fonte de anúncio</b> (network/provider) cadastrada e <b>ativa</b> é servida
							automaticamente em <b>todos os {$src_total_groups} grupos</b> de anúncio, mesclada
							com os <b>anúncios manuais</b> pela proporção <code>share</code> (porcentagem das
							impressões de cada slot). O embed (JSSDK/iframe) de exemplo vem do próprio painel
							do provider — cole aqui o snippet pronto.
						</div>
					</div>
				</div>
			</div>

			<div class="col-md-12">
				<div class="grid simple">
					<div class="grid-title no-border">
						<h4>{if $src_edit}Editar <span class="semi-bold">fonte</span>{else}Nova <span class="semi-bold">fonte</span>{/if}</h4>
					</div>
					<div class="grid-body no-border">
						<form class="form-no-horizontal-spacing" name="src_save" method="POST" action="index.php?m=adsources">
							<input type="hidden" name="src_id" value="{if $src_edit}{$src_edit.id}{else}0{/if}">
							<div class="row">
								<div class="col-xs-12 col-md-3">
									<label class="form-label">Nome</label>
									<input name="name" type="text" class="form-control" style="margin: 0 0 10px 0;" value="{if $src_edit}{$src_edit.name}{/if}" placeholder="Ex.: TrafficStars">
								</div>
								<div class="col-xs-12 col-md-3">
									<label class="form-label">Provider</label>
									<input name="provider" type="text" class="form-control" style="margin: 0 0 10px 0;" list="provider-list" value="{if $src_edit}{$src_edit.provider}{else}custom{/if}">
									<datalist id="provider-list">
										<option value="trafficstars">TrafficStars</option>
										<option value="adsterra">Adsterra</option>
										<option value="custom">Custom</option>
									</datalist>
								</div>
								<div class="col-xs-12 col-md-2">
									<label class="form-label">Share %</label>
									<input name="share" type="number" min="0" max="100" class="form-control" style="margin: 0 0 10px 0;" value="{if $src_edit}{$src_edit.share}{else}50{/if}">
								</div>
								<div class="col-xs-12 col-md-4">
									<label class="form-label">&nbsp;</label>
									<label class="checkbox check-success" style="margin: 0 0 10px 0;">
										<input type="checkbox" name="active" value="1" {if $src_edit}{if $src_edit.active == '1'}checked="checked"{/if}{else}checked="checked"{/if}> Ativa (serve em todos os grupos agora)
									</label>
								</div>
							</div>
							<div class="row">
								<div class="col-xs-12">
									<label class="form-label">HTML / Embed</label>
									<textarea name="html" rows="6" class="form-control" style="margin: 0 0 10px 0;" placeholder="<script src='https://...'>...</script>">{if $src_edit}{$src_edit.html}{/if}</textarea>
								</div>
							</div>
							<div class="row">
								<div class="col-xs-12">
									<button type="submit" name="ts_save" class="btn btn-success btn-cons">{if $src_edit}Salvar{else}Criar{/if}</button>
									{if $src_edit}<a href="index.php?m=adsources" class="btn btn-default">Cancelar</a>{/if}
								</div>
							</div>
						</form>
					</div>
				</div>
			</div>

			<div class="col-md-12">
				<div class="grid simple">
					<div class="grid-title no-border">
						<h4>Fontes <span class="semi-bold">cadastradas</span></h4>
					</div>
					<div class="grid-body no-border">
						{if $src_list}
						<table class="table no-more-tables m-0">
							<thead>
								<tr>
									<th>NOME</th>
									<th>PROVIDER</th>
									<th>SHARE</th>
									<th>IMPRESSÕES</th>
									<th>STATUS</th>
									<th>ACTION</th>
								</tr>
							</thead>
							<tbody>
								{section name=i loop=$src_list}
								<tr>
									<td class="{if $smarty.section.i.index mod 2 == 0}grey{else}white{/if}">{$src_list[i].name}</td>
									<td class="{if $smarty.section.i.index mod 2 == 0}grey{else}white{/if}"><span class="text-muted">{$src_list[i].provider}</span></td>
									<td class="{if $smarty.section.i.index mod 2 == 0}grey{else}white{/if}">{$src_list[i].share}%</td>
									<td class="{if $smarty.section.i.index mod 2 == 0}grey{else}white{/if}">{$src_list[i].impressions}</td>
									<td class="{if $smarty.section.i.index mod 2 == 0}grey{else}white{/if}">
										{if $src_list[i].active == '1'}<span class="text-success">Ativa</span>{else}<span class="text-danger">Suspensa</span>{/if}
									</td>
									<td class="action {if $smarty.section.i.index mod 2 == 0}grey{else}white{/if}">
										<div>
											<a class="btn btn-primary btn-xs btn-mini" href="index.php?m=adsources&e={$src_list[i].id}">EDIT</a>
											{if $src_list[i].active == '1'}
											<a class="btn btn-danger btn-xs btn-mini" href="index.php?m=adsources&a=suspend&ID={$src_list[i].id}">SUSPEND</a>
											{else}
											<a class="btn btn-success btn-xs btn-mini" href="index.php?m=adsources&a=activate&ID={$src_list[i].id}">ACTIVATE</a>
											{/if}
											<a class="btn btn-warning btn-xs btn-mini" href="index.php?m=adsources&a=delete&ID={$src_list[i].id}" onclick="return confirm('Remover esta fonte?');">DELETE</a>
										</div>
									</td>
								</tr>
								{/section}
							</tbody>
						</table>
						{else}
						<div class="row">
							<div class="col-xs-12">
								<div class="alert alert-info">
									<button class="close" data-dismiss="alert"></button>
									Nenhuma fonte cadastrada. Crie a primeira acima.
								</div>
							</div>
						</div>
						{/if}
					</div>
				</div>
			</div>

			<!-- END PLACE PAGE CONTENT HERE -->
		</div>
	</div>
	<!-- END PAGE CONTAINER -->