<!-- BEGIN PAGE CONTAINER-->
	<div class="page-content"> 
		<div class="content">
			<!-- BEGIN PAGE TITLE -->
			<div class="page-title">
				<i class="icon-custom-left"></i>
				<h3>Settings - <span class="semi-bold">Advertising</span></h3>
			</div>
			{include file="errmsg.tpl"}
			<!-- END PAGE TITLE -->
			<!-- BEGIN PlACE PAGE CONTENT HERE -->															
			<div class="col-md-12">
				<div class="grid simple">
					<div class="grid-title no-border">
						<h4>Player <span class="semi-bold">Ads</span> <small>(preroll / midroll / pause / postroll / overlay)</small></h4>
					</div>
					<div class="grid-body no-border">
						<div class="row">
							<div class="col-xs-12">
								<div class="btn-group m-b-10">
									<a class="btn {if $tfilter == ''}btn-success{else}btn-white{/if} btn-cons" href="index.php?m=advplayer&all=1">All</a>
									{section name=i loop=$player_types}
										<a class="btn {if $tfilter == $player_types[i]}btn-success{else}btn-white{/if} btn-cons" href="index.php?m=advplayer&all=1&type={$player_types[i]}">{$player_types[i]}</a>
									{/section}
								</div>
								<a class="btn btn-success btn-cons pull-right m-b-10" href="index.php?m=advplayeradd"><i class="fa fa-plus"></i> Add Player Ad</a>
								<div class="clearfix"></div>
							</div>
						</div>
						<div class="row">
							<div class="col-xs-12">
								<div>
									{if $player_ads}
										<table class="table no-more-tables m-0">
											<thead>
												<tr>
													<th style="width:5%">ID</th>
													<th>NAME</th>
													<th>TYPE</th>
													<th>GROUP</th>
													<th>CREATIVE</th>
													<th>DEVICE</th>
													<th>VIEWS</th>
													<th>CLICKS</th>
													<th>STATUS</th>												
													<th>ACTION</th>
												</tr>
											</thead>
											<tbody>
												{section name=i loop=$player_ads}					
												<tr>
													<td class="{if $smarty.section.i.index mod 2 == 0}grey{else}white{/if}">{$player_ads[i].id}</td>
													<td class="{if $smarty.section.i.index mod 2 == 0}grey{else}white{/if}">
														<a href="index.php?m=advplayeredit&AID={$player_ads[i].id}">{$player_ads[i].name}</a>
													</td>
													<td class="{if $smarty.section.i.index mod 2 == 0}grey{else}white{/if}">
														<span class="label label-default">{$player_ads[i].type}</span>
													</td>
													<td class="{if $smarty.section.i.index mod 2 == 0}grey{else}white{/if}">
														{$player_ads[i].grp}
													</td>
													<td class="{if $smarty.section.i.index mod 2 == 0}grey{else}white{/if}">
														{$player_ads[i].creative}
													</td>
													<td class="{if $smarty.section.i.index mod 2 == 0}grey{else}white{/if}">
														{$player_ads[i].device}
													</td>
													<td class="{if $smarty.section.i.index mod 2 == 0}grey{else}white{/if}">
														{$player_ads[i].views}
													</td>
													<td class="{if $smarty.section.i.index mod 2 == 0}grey{else}white{/if}">
														{$player_ads[i].clicks}
													</td>
													<td class="{if $smarty.section.i.index mod 2 == 0}grey{else}white{/if}">
														{if $player_ads[i].status == '1'}<span class="text-success">Active</span>{else}<span class="text-danger">Inactive</span>{/if}
													</td>
													<td class="action {if $smarty.section.i.index mod 2 == 0}grey{else}white{/if}">
														<div>
															<a class="btn btn-success btn-xs btn-mini" href="index.php?m=advplayeredit&AID={$player_ads[i].id}">EDIT</a>
															{if $player_ads[i].status == '1'}
																<a class="btn btn-danger btn-xs btn-mini" href="index.php?m=advplayer&a=suspend&AID={$player_ads[i].id}">SUSPEND</a>
															{else}
																<a class="btn btn-success btn-xs btn-mini" href="index.php?m=advplayer&a=activate&AID={$player_ads[i].id}">ACTIVATE</a>
															{/if}
															<a class="btn btn-danger btn-xs btn-mini" href="index.php?m=advplayer&a=delete&AID={$player_ads[i].id}" onClick="javascript:return confirm('Are you sure you want to delete this ad?');">DELETE</a>															
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
												No Player Ads Found
											</div>
										</div>
									</div>
									{/if}
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