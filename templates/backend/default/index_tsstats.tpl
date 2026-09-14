<!-- BEGIN PAGE CONTAINER-->
	<div class="page-content"> 
		<div class="content">
			<!-- BEGIN PAGE TITLE -->
			<div class="page-title">
				<i class="icon-custom-left"></i>
				<h3>Settings - <span class="semi-bold">TrafficStars</span></h3>
			</div>
			{include file="errmsg.tpl"}
			<!-- END PAGE TITLE -->
			<!-- BEGIN PLACE PAGE CONTENT HERE -->

			{if !$ts_key_configured}
			<div class="col-md-12">
				<div class="alert alert-warning">
					<button class="close" data-dismiss="alert"></button>
					<i class="fa fa-warning"></i> 
					<strong>TS_API_KEY não configurada.</strong> Gere uma API key em
					<code>admin.trafficstars.com/profile/</code> e defina <code>TS_API_KEY</code> no <code>.env</code>
					para ativar a automação (cron <code>scripts/ts_sync.php</code> e saldo/relatórios).
				</div>
			</div>
			{/if}

			{if $ts_balance !== NULL}
			<div class="col-md-12">
				<div class="grid simple no-border">
					<div class="grid-body no-border">
						<div class="row">
							<div class="col-xs-6 col-md-2">
								<div class="info-tiles t-green"><i class="fa fa-money"></i><div class="tiles-heading">Saldo publisher</div><div class="tiles-title">$ {$ts_balance}</div></div>
							</div>
						</div>
					</div>
				</div>
			</div>
			{/if}

			<div class="col-md-12">
				<div class="grid simple">
					<div class="grid-title no-border">
						<h4>Vincular <span class="semi-bold">spot</span></h4>
					</div>
					<div class="grid-body no-border">
						<form class="form-no-horizontal-spacing" name="ts_link" method="POST" action="index.php?m=tsstats">
							<div class="row">
								<div class="col-xs-12 col-md-3">
									<label class="form-label">Grupo (slot interno)</label>
									<select name="advgrp_id" class="form-control" style="margin: 0 0 10px 0;">
										<option value="0">Selecionar grupo...</option>
										{section name=i loop=$ts_groups}
										<option value="{$ts_groups[i].advgrp_id}">{$ts_groups[i].advgrp_name} ({$ts_groups[i].adv_width}x{$ts_groups[i].adv_height})</option>
										{/section}
									</select>
								</div>
								<div class="col-xs-12 col-md-2">
									<label class="form-label">Spot ID (TS)</label>
									<input name="ts_spot_id" type="text" class="form-control" style="margin: 0 0 10px 0;" placeholder="12345">
								</div>
								<div class="col-xs-12 col-md-3">
									<label class="form-label">Nome do spot</label>
									<input name="spot_name" type="text" class="form-control" style="margin: 0 0 10px 0;" placeholder="index_feed">
								</div>
								<div class="col-xs-12 col-md-2">
									<label class="form-label">&nbsp;</label>
									<label class="checkbox check-success" style="margin: 0 0 10px 0;">
										<input type="checkbox" name="active" value="1" checked="checked"> Ativo
									</label>
								</div>
								<div class="col-xs-12 col-md-2">
									<label class="form-label">&nbsp;</label>
									<button type="submit" name="ts_link" class="btn btn-success btn-cons btn-block">Vincular</button>
								</div>
							</div>
						</form>
					</div>
				</div>
			</div>

			<div class="col-md-12">
				<div class="grid simple">
					<div class="grid-title no-border">
						<h4>Spots <span class="semi-bold">vinculados</span></h4>
					</div>
					<div class="grid-body no-border">
						{if $ts_links}
						<table class="table no-more-tables m-0">
							<thead>
								<tr>
									<th>GRUPO</th>
									<th>SPOT (TS)</th>
									<th>HOJE (imp/c/leads/rec)</th>
									<th>30 DIAS (imp/c/leads/rec)</th>
									<th>STATUS</th>
									<th>ACTION</th>
								</tr>
							</thead>
							<tbody>
								{section name=i loop=$ts_links}
								{assign var=spot_id value=$ts_links[i].ts_spot_id}
								<tr>
									<td class="{if $smarty.section.i.index mod 2 == 0}grey{else}white{/if}">
										{$ts_links[i].group_name} <span class="text-muted">(id {$ts_links[i].advgrp_id})</span>
									</td>
									<td class="{if $smarty.section.i.index mod 2 == 0}grey{else}white{/if}">
										<a href="https://admin.trafficstars.com/" target="_blank">{$ts_links[i].spot_name} <span class="text-muted">#{$ts_links[i].ts_spot_id}</span></a>
									</td>
									<td class="{if $smarty.section.i.index mod 2 == 0}grey{else}white{/if}">
										{$ts_links[i].today_impressions|default:0} / {$ts_links[i].today_clicks|default:0} / {$ts_links[i].today_leads|default:0} / ${$ts_links[i].today_amount|default:'0.00'}
									</td>
									<td class="{if $smarty.section.i.index mod 2 == 0}grey{else}white{/if}">
										{if $ts_period.$spot_id}
										{$ts_period.$spot_id.p_impressions} / {$ts_period.$spot_id.p_clicks} / {$ts_period.$spot_id.p_leads} / ${$ts_period.$spot_id.p_amount}
										{else}N/A{/if}
									</td>
									<td class="{if $smarty.section.i.index mod 2 == 0}grey{else}white{/if}">
										{if $ts_links[i].active == '1'}<span class="text-success">Active</span>{else}<span class="text-danger">Inactive</span>{/if}
									</td>
									<td class="action {if $smarty.section.i.index mod 2 == 0}grey{else}white{/if}">
										<div>
											{if $ts_links[i].active == '1'}
											<a class="btn btn-danger btn-xs btn-mini" href="index.php?m=tsstats&a=suspend&ID={$ts_links[i].id}">SUSPEND</a>
											{else}
											<a class="btn btn-success btn-xs btn-mini" href="index.php?m=tsstats&a=activate&ID={$ts_links[i].id}">ACTIVATE</a>
											{/if}
											<a class="btn btn-warning btn-xs btn-mini" href="index.php?m=tsstats&a=delete&ID={$ts_links[i].id}" onclick="return confirm('Desvincular este spot?');">DELETE</a>
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
									Nenhum spot vinculado ainda. Use o formulário acima.
								</div>
							</div>
						</div>
						{/if}
						<div class="row">
							<div class="col-xs-12">
								<div class="alert alert-info m-t-10">
									<button class="close" data-dismiss="alert"></button>
									<i class="fa fa-info-circle"></i>
									Cada grupo interno funciona como um <b>slot</b>: o código de embed (JSSDK) gerado
									no painel para o spot entra como um banner <code>adv</code> dentro deste grupo.
									A receita por slot/dia é espelhada pelo cron <code>scripts/ts_sync.php</code>.
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>			
			<!-- END PLACE PAGE CONTENT HERE -->
		</div>
	</div>
	<!-- END PAGE CONTAINER -->