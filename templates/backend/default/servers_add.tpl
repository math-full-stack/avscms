	<!-- BEGIN PAGE CONTAINER-->
	<div class="page-content"> 
		<div class="content">  
			<!-- BEGIN PAGE TITLE -->
			<div class="page-title">
				<i class="icon-custom-left"></i>
				<h3>Servers - <span class="semi-bold">Add Server</span></h3>
			</div>
			{include file="errmsg.tpl"}
			<!-- END PAGE TITLE -->

			<!-- BEGIN PLACE PAGE CONTENT HERE -->
			<div class="col-md-12">
				<div class="grid simple">
					<div class="grid-title no-border">
						<h4>Add Secondary <span class="semi-bold">Storage & Streaming Server</span></h4>
					</div>
					<div class="grid-body no-border">

						<div id="test_ftp_alert" class="alert" style="display: none;"></div>

						<form class="form-no-horizontal-spacing" name="addServer" method="POST" action="servers.php?m=add">
							<div class="row">
								<div class="col-lg-8 col-lg-offset-2 col-md-12">
									
									<div class="form-group">
										<label class="col-lg-4 control-label">Server URL (Web)</label>
										<div class="col-lg-8">
											<input class="form-control" name="url" id="srv_url" type="text" value="{$server.url|escape:'html'}" placeholder="http://node1.meusite.com ou https://node1.meusite.com" required>
											<span class="help">URL base do servidor secundário na web</span>
										</div>
										<div class="clearfix"></div>
									</div>

									<div class="form-group">
										<label class="col-lg-4 control-label">Video Streaming URL</label>
										<div class="col-lg-8">
											<input class="form-control" name="video_url" id="srv_video_url" type="text" value="{$server.video_url|escape:'html'}" placeholder="http://node1.meusite.com/media/videos" required>
											<span class="help">URL pública de onde o player do site carregará os vídeos (H.264 MP4)</span>
										</div>
										<div class="clearfix"></div>
									</div>

									<div class="form-group">
										<label class="col-lg-4 control-label">IP / Host FTP</label>
										<div class="col-lg-8">
											<input class="form-control" name="server_ip" id="srv_ip" type="text" value="{$server.server_ip|escape:'html'}" placeholder="192.168.1.100 ou ftp.node1.meusite.com" required>
											<span class="help">Endereço IP ou hostname para o servidor principal enviar os vídeos via FTP</span>
										</div>
										<div class="clearfix"></div>
									</div>

									<div class="form-group">
										<label class="col-lg-4 control-label">Usuário FTP</label>
										<div class="col-lg-8">
											<input class="form-control" name="ftp_username" id="srv_ftp_user" type="text" value="{$server.ftp_username|escape:'html'}" placeholder="usuario_ftp" required autocomplete="off">
										</div>
										<div class="clearfix"></div>
									</div>

									<div class="form-group">
										<label class="col-lg-4 control-label">Senha FTP</label>
										<div class="col-lg-8">
											<input class="form-control" name="ftp_password" id="srv_ftp_pass" type="password" value="" placeholder="••••••••" required autocomplete="new-password">
										</div>
										<div class="clearfix"></div>
									</div>

									<div class="form-group">
										<label class="col-lg-4 control-label">FTP Root Path</label>
										<div class="col-lg-8">
											<input class="form-control" name="ftp_root" id="srv_ftp_root" type="text" value="{$server.ftp_root|escape:'html'}" placeholder="/public_html/media/videos ou /var/www/html/media/videos" required>
											<span class="help">Caminho absoluto ou relativo do diretório raiz remoto onde a pasta <code>h264/</code> reside</span>
										</div>
										<div class="clearfix"></div>
									</div>

									<div class="form-group">
										<label class="col-lg-4 control-label">Status Inicial</label>
										<div class="col-lg-8">
											<div class="radio p-t-9">
												<input id="status_active" type="radio" name="status" value="1" {if $server.status != '0'}checked="checked"{/if} class="radio-enabled">
												<label for="status_active">Ativo (Participa da rotação de conversões)</label>
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
									<button name="add_server" type="submit" value="1" class="btn btn-success btn-cons">
										<i class="fa fa-check"></i> Salvar Servidor
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
		function initServerAdd() {
			if (typeof jQuery === 'undefined' || typeof $ === 'undefined') {
				setTimeout(initServerAdd, 50);
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
			document.addEventListener('DOMContentLoaded', initServerAdd);
		} else {
			initServerAdd();
		}
	})();
	</script>
