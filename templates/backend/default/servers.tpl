	<!-- BEGIN PAGE CONTAINER-->
	<div class="page-content"> 
		<div class="content">  
			<!-- BEGIN PAGE TITLE -->
			<div class="page-title">
				<i class="icon-custom-left"></i>
				<h3>Servers - <span class="semi-bold">Manage Servers</span></h3>
			</div>
			{include file="errmsg.tpl"}
			<!-- END PAGE TITLE -->

			<!-- BEGIN PLACE PAGE CONTENT HERE -->
			<div class="col-md-12">
				<div class="grid simple">
					<div class="grid-title no-border">
						<h4>Secondary <span class="semi-bold">Storage & Streaming Servers</span></h4>
						<div class="tools">
							<a href="servers.php?m=add" class="btn btn-success btn-sm btn-cons"><i class="fa fa-plus"></i> Add Server</a>
						</div>
					</div>
					<div class="grid-body no-border">

						<div class="alert alert-info m-b-20">
							<i class="fa fa-info-circle"></i> Os servidores secundários abaixo são utilizados em <b>rotação (Round-Robin)</b> para armazenar e transmitir vídeos (H.264 MP4), distribuindo o uso de largura de banda e armazenamento. Apenas nós com status <b>Active</b> são selecionados para novas conversões.
						</div>

						{if $servers}
						<div class="table-responsive">
							<table class="table table-hover table-condensed">
								<thead>
									<tr>
										<th style="width: 50px;">ID</th>
										<th>Tipo</th>
										<th>Conexão / Host</th>
										<th>URL de Streaming (Video URL)</th>
										<th class="text-center">Vídeos</th>
										<th>Último Uso</th>
										<th class="text-center">Status</th>
										<th class="text-right" style="width: 180px;">Ações</th>
									</tr>
								</thead>
								<tbody>
									{section name=i loop=$servers}
									<tr id="server_row_{$servers[i].server_id}">
										<td class="v-align-middle"><strong>#{$servers[i].server_id}</strong></td>
										<td class="v-align-middle">
											{if $servers[i].server_type == 'gcs'}
												<span class="label label-info"><i class="fa fa-cloud"></i> GCS</span>
											{else}
												<span class="label label-inverse"><i class="fa fa-plug"></i> FTP</span>
											{/if}
										</td>
										<td class="v-align-middle">
											{if $servers[i].server_type == 'gcs'}
												<span class="label label-info">gs://{$servers[i].gcs_bucket|escape:'html'}</span>
											{else}
												<span class="label label-inverse">{$servers[i].server_ip|escape:'html'}</span>
												<br><small class="text-muted">{$servers[i].ftp_username|escape:'html'}@{$servers[i].ftp_root|escape:'html'}</small>
											{/if}
										</td>
										<td class="v-align-middle"><a href="{$servers[i].video_url}" target="_blank">{$servers[i].video_url|escape:'html'}</a></td>
										<td class="v-align-middle text-center"><span class="badge badge-info">{$servers[i].total_videos}</span></td>
										<td class="v-align-middle"><small>{if $servers[i].last_used}{$servers[i].last_used}{else}Nunca{/if}</small></td>
										<td class="v-align-middle text-center">
											<span id="status_{$servers[i].server_id}">
												{if $servers[i].status == '1'}
													<a href="javascript:;" onclick="toggleServerStatus({$servers[i].server_id}, 1)" class="badge badge-success" title="Clique para desativar"><i class="fa fa-check"></i> Ativo</a>
												{else}
													<a href="javascript:;" onclick="toggleServerStatus({$servers[i].server_id}, 0)" class="badge badge-important" title="Clique para ativar"><i class="fa fa-times"></i> Inativo</a>
												{/if}
											</span>
										</td>
										<td class="v-align-middle text-right">
											<a href="servers.php?m=edit&SID={$servers[i].server_id}" class="btn btn-default btn-xs btn-mini" title="Editar"><i class="fa fa-pencil"></i></a>
											{if $servers[i].server_type == 'gcs'}
												<button type="button" class="btn btn-info btn-xs btn-mini" onclick="testServerGCS('{$servers[i].gcs_bucket|escape:'javascript'}', '{$servers[i].gcs_key_path|escape:'javascript'}', {$servers[i].server_id})" title="Testar Conexão GCS"><i class="fa fa-cloud"></i> Testar</button>
											{else}
												<button type="button" class="btn btn-info btn-xs btn-mini" onclick="testServerFTP('{$servers[i].server_ip|escape:'javascript'}', '{$servers[i].ftp_username|escape:'javascript'}', '{$servers[i].ftp_root|escape:'javascript'}', {$servers[i].server_id})" title="Testar Conexão FTP"><i class="fa fa-plug"></i> Testar</button>
											{/if}
											<a href="servers.php?m=all&a=delete&SID={$servers[i].server_id}" onclick="return confirm('Tem certeza que deseja excluir o Servidor ID {$servers[i].server_id}? Os vídeos existentes não serão apagados do nó remoto, mas novos envios não usarão este servidor.');" class="btn btn-danger btn-xs btn-mini" title="Excluir"><i class="fa fa-trash-o"></i></a>
										</td>
									</tr>
									{/section}
								</tbody>
							</table>
						</div>

						<!-- PAGINAÇÃO -->
						{if $paging}
						<div class="row">
							<div class="col-xs-12 text-center">
								<ul class="pagination">
									{$paging}
								</ul>
							</div>
						</div>
						{/if}

						{else}
						<div class="alert alert-warning m-t-20">
							<i class="fa fa-exclamation-triangle"></i> Nenhum servidor secundário cadastrado no momento. <a href="servers.php?m=add" class="alert-link">Clique aqui para adicionar seu primeiro servidor secundário</a>.
						</div>
						{/if}

					</div>
				</div>
			</div>			
			<!-- END PLACE PAGE CONTENT HERE -->
		</div>
	</div>
	<!-- END PAGE CONTAINER -->

	<!-- MODAL TESTE -->
	<div class="modal fade" id="modal_test_ftp" tabindex="-1" role="dialog" aria-hidden="true">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
					<h4 class="modal-title" id="modal_test_title"><i class="fa fa-plug"></i> Teste de Conexão</h4>
				</div>
				<div class="modal-body" id="modal_test_ftp_body">
					<div class="text-center p-t-20 p-b-20">
						<i class="fa fa-spinner fa-spin fa-2x"></i>
						<p class="m-t-10">Conectando ao servidor FTP e verificando permissões de escrita...</p>
					</div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-default" data-dismiss="modal">Fechar</button>
				</div>
			</div>
		</div>
	</div>

	<script type="text/javascript">
	function toggleServerStatus(sid, currentStatus) {
		$.ajax({
			url: base_url + '/ajax.php?module=admin_status_server',
			type: 'POST',
			dataType: 'json',
			data: { server_id: sid, server_status: currentStatus },
			success: function(response) {
				if (response && response.status == 1) {
					var badge = '';
					if (currentStatus == 1) {
						// estava ativo → agora inativo
						badge = '<a href="javascript:;" onclick="toggleServerStatus(' + sid + ', 0)" class="badge badge-important" title="Clique para ativar"><i class="fa fa-times"></i> Inativo</a>';
					} else {
						// estava inativo → agora ativo
						badge = '<a href="javascript:;" onclick="toggleServerStatus(' + sid + ', 1)" class="badge badge-success" title="Clique para desativar"><i class="fa fa-check"></i> Ativo</a>';
					}
					$('#status_' + sid).html(badge);
				} else {
					showToast('Erro ao alterar status do servidor.', 'error');
				}
			},
			error: function() {
				showToast('Erro de conexão ao alterar o status.', 'error');
			}
		});
	}

	function testServerFTP(ip, user, root, sid) {
		$('#modal_test_ftp').modal('show');
		$('#modal_test_title').html('<i class="fa fa-plug"></i> Teste de Conexão FTP');
		$('#modal_test_ftp_body').html('<div class="text-center p-t-20 p-b-20"><i class="fa fa-spinner fa-spin fa-2x text-info"></i><p class="m-t-10">Conectando a <b>' + ip + '</b> com o usuário <b>' + user + '</b>...</p></div>');

		// Use server_id to fetch password securely from database
		$.ajax({
			url: base_url + '/ajax.php?module=admin_test_server',
			type: 'POST',
			dataType: 'json',
			data: { server_id: sid },
			success: function(res) {
				if (res.status == 1) {
					$('#modal_test_ftp_body').html('<div class="alert alert-success m-0"><i class="fa fa-check-circle fa-lg"></i> <b>Sucesso!</b> ' + res.message + '</div>');
				} else {
					$('#modal_test_ftp_body').html('<div class="alert alert-danger m-0"><i class="fa fa-times-circle fa-lg"></i> <b>Falha no Teste:</b><br>' + res.message + '</div>');
				}
			},
			error: function(xhr, st, err) {
				$('#modal_test_ftp_body').html('<div class="alert alert-danger m-0"><i class="fa fa-exclamation-triangle"></i> Erro na requisição de teste: ' + err + '</div>');
			}
		});
	}

	function testServerGCS(bucket, keyPath, sid) {
		$('#modal_test_ftp').modal('show');
		$('#modal_test_title').html('<i class="fa fa-cloud"></i> Teste de Conexão GCS');
		$('#modal_test_ftp_body').html('<div class="text-center p-t-20 p-b-20"><i class="fa fa-spinner fa-spin fa-2x text-info"></i><p class="m-t-10">Conectando ao bucket <b>gs://' + bucket + '</b>...</p></div>');

		$.ajax({
			url: base_url + '/ajax.php?module=admin_get_server',
			type: 'POST',
			dataType: 'json',
			data: { server_id: sid },
			success: function(srv) {
				if (srv && srv.gcs_bucket) {
					$.ajax({
						url: base_url + '/ajax.php?module=admin_test_gcs',
						type: 'POST',
						dataType: 'json',
						data: {
							gcs_bucket: srv.gcs_bucket,
							gcs_key_path: srv.gcs_key_path
						},
						success: function(res) {
							if (res.status == 1) {
								$('#modal_test_ftp_body').html('<div class="alert alert-success m-0"><i class="fa fa-check-circle fa-lg"></i> <b>Sucesso!</b> ' + res.message + '</div>');
							} else {
								$('#modal_test_ftp_body').html('<div class="alert alert-danger m-0"><i class="fa fa-times-circle fa-lg"></i> <b>Falha no Teste:</b><br>' + res.message + '</div>');
							}
						},
						error: function(xhr, st, err) {
							$('#modal_test_ftp_body').html('<div class="alert alert-danger m-0"><i class="fa fa-exclamation-triangle"></i> Erro na requisição de teste: ' + err + '</div>');
						}
					});
				}
			}
		});
	}
	</script>
