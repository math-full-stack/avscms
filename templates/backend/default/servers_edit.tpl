	<!-- BEGIN PAGE CONTAINER-->
	<div class="page-content"> 
		<div class="content">  
			<!-- BEGIN PAGE TITLE -->
			<div class="page-title">
				<i class="icon-custom-left"></i>
				<h3>Servers - <span class="semi-bold">Edit Server</span></h3>
			</div>
			{include file="errmsg.tpl"}
			<!-- END PAGE TITLE -->

			<!-- BEGIN PLACE PAGE CONTENT HERE -->
			<div class="col-md-12">
				<div class="grid simple">
					<div class="grid-title no-border">
						<h4>Edit Secondary Server <span class="semi-bold">#{$server.server_id}</span></h4>
					</div>
					<div class="grid-body no-border">

						<div id="test_ftp_alert" class="alert" style="display: none;"></div>

						<form class="form-no-horizontal-spacing" name="editServer" method="POST" action="servers.php?m=edit&SID={$server.server_id}">
							<input type="hidden" name="server_id" value="{$server.server_id}">

							<div class="row">
								<div class="col-lg-8 col-lg-offset-2 col-md-12">
									
									<div class="form-group">
										<label class="col-lg-4 control-label">Server URL (Web)</label>
										<div class="col-lg-8">
											<input class="form-control" name="url" id="srv_url" type="text" value="{$server.url|escape:'html'}" required>
											<span class="help">URL base do servidor secundário na web</span>
										</div>
										<div class="clearfix"></div>
									</div>

									<div class="form-group">
										<label class="col-lg-4 control-label">Video Streaming URL</label>
										<div class="col-lg-8">
											<input class="form-control" name="video_url" id="srv_video_url" type="text" value="{$server.video_url|escape:'html'}" required>
											<span class="help">URL pública onde os vídeos MP4 são carregados pelo player</span>
										</div>
										<div class="clearfix"></div>
									</div>

									<div class="form-group">
										<label class="col-lg-4 control-label">IP / Host FTP</label>
										<div class="col-lg-8">
											<input class="form-control" name="server_ip" id="srv_ip" type="text" value="{$server.server_ip|escape:'html'}" required>
											<span class="help">Endereço IP ou hostname FTP</span>
										</div>
										<div class="clearfix"></div>
									</div>

									<div class="form-group">
										<label class="col-lg-4 control-label">Usuário FTP</label>
										<div class="col-lg-8">
											<input class="form-control" name="ftp_username" id="srv_ftp_user" type="text" value="{$server.ftp_username|escape:'html'}" required autocomplete="off">
										</div>
										<div class="clearfix"></div>
									</div>

									<div class="form-group">
										<label class="col-lg-4 control-label">Senha FTP</label>
										<div class="col-lg-8">
											<input class="form-control" name="ftp_password" id="srv_ftp_pass" type="password" value="{$server.ftp_password|escape:'html'}" required autocomplete="new-password">
											<span class="help">Preencha apenas caso deseje alterar a senha atual</span>
										</div>
										<div class="clearfix"></div>
									</div>

									<div class="form-group">
										<label class="col-lg-4 control-label">FTP Root Path</label>
										<div class="col-lg-8">
											<input class="form-control" name="ftp_root" id="srv_ftp_root" type="text" value="{$server.ftp_root|escape:'html'}" required>
											<span class="help">Diretório raiz de armazenamento de vídeos no servidor secundário</span>
										</div>
										<div class="clearfix"></div>
									</div>

									<div class="form-group">
										<label class="col-lg-4 control-label">Status</label>
										<div class="col-lg-8">
											<div class="radio p-t-9">
												<input id="status_active" type="radio" name="status" value="1" {if $server.status == '1'}checked="checked"{/if} class="radio-enabled">
												<label for="status_active">Ativo (Participa da rotação de novas conversões)</label>
												<input id="status_inactive" type="radio" name="status" value="0" {if $server.status == '0'}checked="checked"{/if} class="radio-disabled">
												<label for="status_inactive">Inativo</label>												
											</div>
										</div>
										<div class="clearfix"></div>
									</div>

									<div class="form-group">
										<div class="col-lg-8 col-lg-offset-4">
											<button type="button" id="btn_test_ftp" class="btn btn-info btn-cons">
												<i class="fa fa-plug"></i> <span id="btn_test_ftp_text">Testar Conexão FTP</span>
											</button>
										</div>
										<div class="clearfix"></div>
									</div>

								</div>
							</div>

							<div class="form-actions">
								<div class="pull-right">
									<button name="edit_server" type="submit" value="1" class="btn btn-success btn-cons">
										<i class="fa fa-save"></i> Salvar Alterações
									</button>
									<a href="servers.php?m=all&all=1" class="btn btn-white btn-cons">Cancelar</a>
								</div>
							</div>
						</form>

					</div>
				</div>
			</div>			
			<!-- END PLACE PAGE CONTENT HERE -->
		</div>
	</div>
	<!-- END PAGE CONTAINER -->

	<script type="text/javascript">
	(function() {
		function initServerEdit() {
			if (typeof jQuery === 'undefined' || typeof $ === 'undefined') {
				setTimeout(initServerEdit, 50);
				return;
			}
			var $ = jQuery;
			$('#btn_test_ftp').on('click', function(e) {
				e.preventDefault();
				var ip   = $.trim($('#srv_ip').val());
				var user = $.trim($('#srv_ftp_user').val());
				var pass = $.trim($('#srv_ftp_pass').val());
				var root = $.trim($('#srv_ftp_root').val());

				if (ip === '' || user === '' || pass === '') {
					$('#test_ftp_alert').removeClass('alert-success alert-danger')
						.addClass('alert-warning')
						.html('<i class="fa fa-exclamation-triangle"></i> Por favor, preencha o IP, Usuário e Senha FTP para testar a conexão.')
						.slideDown();
					return;
				}

				$('#btn_test_ftp').prop('disabled', true);
				$('#btn_test_ftp_text').text('Testando...');
				$('#test_ftp_alert').removeClass('alert-success alert-warning alert-danger')
					.addClass('alert-info')
					.html('<i class="fa fa-spinner fa-spin"></i> Conectando ao servidor FTP e testando permissão de escrita...')
					.slideDown();

				$.ajax({
					url: 'ajax.php/admin_test_server',
					type: 'POST',
					dataType: 'json',
					data: {
						server_ip: ip,
						ftp_username: user,
						ftp_password: pass,
						ftp_root: root
					},
					success: function(res) {
						$('#btn_test_ftp').prop('disabled', false);
						$('#btn_test_ftp_text').text('Testar Conexão FTP');

						if (res && res.status == 1) {
							$('#test_ftp_alert').removeClass('alert-info alert-danger alert-warning')
								.addClass('alert-success')
								.html('<i class="fa fa-check-circle"></i> <b>Sucesso!</b> ' + res.message);
						} else {
							var msg = (res && res.message) ? res.message : 'Erro ao conectar via FTP.';
							$('#test_ftp_alert').removeClass('alert-info alert-success alert-warning')
								.addClass('alert-danger')
								.html('<i class="fa fa-times-circle"></i> <b>Falha:</b> ' + msg);
						}
					},
					error: function(xhr, status, error) {
						$('#btn_test_ftp').prop('disabled', false);
						$('#btn_test_ftp_text').text('Testar Conexão FTP');
						$('#test_ftp_alert').removeClass('alert-info alert-success alert-warning')
							.addClass('alert-danger')
							.html('<i class="fa fa-times-circle"></i> Erro na requisição AJAX: ' + error);
					}
				});
			});
		}

		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', initServerEdit);
		} else {
			initServerEdit();
		}
	})();
	</script>
