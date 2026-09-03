	<!-- BEGIN PAGE CONTAINER-->
	<div class="page-content"> 
		<div class="content">  
			<!-- BEGIN PAGE TITLE -->
			<div class="page-title">
				<i class="icon-custom-left"></i>
				<h3>Videos - <span class="semi-bold">Add Videos</span></h3>
			</div>
			{include file="errmsg.tpl"}
			<!-- END PAGE TITLE -->

			<style>
			.thumb-preview-container {
				position: relative;
				border: 1px solid #ddd;
				padding: 4px;
				background: #fff;
				border-radius: 4px;
				cursor: pointer;
				overflow: hidden;
			}
			.thumb-preview-container img {
				width: 100%;
				height: auto;
				max-height: 160px;
				object-fit: cover;
				border-radius: 2px;
				display: block;
				transition: transform 0.3s ease;
			}
			.thumb-preview-container:hover img {
				transform: scale(1.03);
			}
			.thumb-play-overlay {
				position: absolute;
				top: 0;
				left: 0;
				right: 0;
				bottom: 0;
				background: rgba(0, 0, 0, 0.35);
				display: flex;
				align-items: center;
				justify-content: center;
				border-radius: 4px;
				transition: background 0.2s ease;
			}
			.thumb-preview-container:hover .thumb-play-overlay {
				background: rgba(0, 0, 0, 0.5);
			}
			.thumb-play-btn {
				width: 50px;
				height: 50px;
				background: rgba(204, 24, 30, 0.9);
				border-radius: 50%;
				display: flex;
				align-items: center;
				justify-content: center;
				color: #fff;
				font-size: 20px;
				box-shadow: 0 2px 10px rgba(0,0,0,0.5);
				transition: transform 0.2s ease, background 0.2s ease;
			}
			.thumb-preview-container:hover .thumb-play-btn {
				transform: scale(1.1);
				background: #cc181e;
			}
			</style>

			<!-- BEGIN PLACE PAGE CONTENT HERE -->
			<div class="col-md-12">
				<div class="grid simple">
					<div class="grid-title no-border">
						<h4>Video <span class="semi-bold">Grabber</span></h4>
					</div>
					<div class="grid-body no-border">

						<!-- STEP 1: URL INPUT -->
						<div class="row m-b-20">
							<div class="col-lg-8 col-lg-offset-2 col-md-12">
								<div class="well" style="background: #fdfdfd; border: 1px solid #e5e5e5; border-radius: 4px; padding: 20px;">
									<div class="form-group m-b-10">
										<label class="control-label" style="font-weight: 600; font-size: 14px; margin-bottom: 8px; display: block;">
											<i class="fa fa-link"></i> Insira a URL do Vídeo (ex: YouTube)
										</label>
										<div class="input-group">
											<input type="text" id="grabber_url_input" class="form-control" placeholder="https://www.youtube.com/watch?v=... ou https://youtu.be/..." value="{$video.url}">
											<span class="input-group-btn">
												<button class="btn btn-primary" type="button" id="btn_fetch_info" onclick="fetchVideoInfo();" style="min-width: 140px;">
													<i class="fa fa-cloud-download"></i> <span id="btn_fetch_text">Obter Dados</span>
												</button>
											</span>
										</div>
										<div id="fetch_status_msg" class="m-t-10" style="display: none;"></div>
									</div>
									<div class="m-t-10 text-muted" style="font-size: 12px;">
										<strong>Sites Suportados:</strong> 
										{foreach from=$supported_sites item=site}
											{if $site == 'YouTube'}
												<span class="label m-r-5" style="background-color: #cc181e; color: #fff; font-size: 11px;"><i class="fa fa-youtube-play"></i> {$site}</span>
											{elseif $site == 'XFree'}
												<span class="label m-r-5" style="background-color: #8b00c9; color: #fff; font-size: 11px;"><i class="fa fa-film"></i> {$site}</span>
											{else}
												<span class="label label-default m-r-5" style="font-size: 11px;"><i class="fa fa-play-circle"></i> {$site}</span>
											{/if}
										{/foreach}
									</div>
								</div>
							</div>
						</div>

						<!-- STEP 2: VIDEO DETAILS FORM -->
						<form class="form-no-horizontal-spacing" id="form_grabber_video" name="grabVideo" method="POST" action="videos.php?m=grabber">
							<input type="hidden" name="grab_video" value="1">
							<input type="hidden" name="url" id="field_url" value="{$video.url}">
							<input type="hidden" name="thumb_url" id="field_thumb_url" value="{$video.thumb_url}">

							<div class="row">
								<div class="col-lg-8 col-lg-offset-2 col-md-12">
									
									<!-- PREVIEW CARD -->
									<div id="video_preview_box" class="row m-b-20" style="{if !$video.url}display: none;{/if}">
										<div class="col-sm-4 text-center">
											<div class="thumb-preview-container" onclick="openPreviewModal();" title="Clique para assistir prévia no player">
												<img id="preview_thumb_img" src="{if $video.thumb_url}{$video.thumb_url}{else}assets/img/no-thumbnail.jpg{/if}" alt="Thumbnail Preview">
												<div class="thumb-play-overlay">
													<div class="thumb-play-btn">
														<i class="fa fa-play" style="margin-left: 3px;"></i>
													</div>
												</div>
											</div>
											<button type="button" class="btn btn-info btn-xs btn-mini m-t-10 btn-block" onclick="openPreviewModal();">
												<i class="fa fa-play-circle"></i> Assistir Prévia no Player
											</button>
										</div>
										<div class="col-sm-8">
											<h4 id="preview_title" style="margin-top: 0; font-weight: 600;">{$video.title}</h4>
											<p class="text-muted" style="font-size: 12px;">
												<strong>Duração:</strong> <span id="preview_duration">{$video.duration}</span> &nbsp;|&nbsp; 
												<strong>Origem:</strong> <span class="badge badge-success" id="preview_source">YouTube</span>
											</p>
											<p class="text-muted" style="font-size: 12px;" id="preview_desc_short"></p>
										</div>
										<div class="clearfix"></div>
										<hr style="margin: 15px 0;">
									</div>

									<div class="row">		
										<div class="form-group">
											<label class="col-lg-3 control-label">Usuário Responsável</label>
											<div class="col-lg-9">
												<input class="form-control" name="username" id="field_username" type="text" value="{$video.username}">
												<span class="help">Nome de usuário cadastrado que receberá o upload</span>
											</div>
											<div class="clearfix"></div>
										</div>

										<div class="form-group">
											<label class="col-lg-3 control-label">Título</label>
											<div class="col-lg-9">
												<input class="form-control" name="title" id="field_title" type="text" value="{$video.title|escape:'html'}" required>
											</div>
											<div class="clearfix"></div>
										</div>

										<div class="form-group">
											<label class="col-lg-3 control-label">Descrição</label>
											<div class="col-lg-9">
												 <textarea class="form-control" name="description" id="field_description" rows="4" style="resize: vertical">{$video.description|escape:'html'}</textarea>
											</div>
											<div class="clearfix"></div>
										</div>

										<div class="form-group">
											<label class="col-lg-3 control-label">Categoria</label>
											<div class="col-lg-9">
												<div style="display: flex; gap: 6px; align-items: center;">
													<select id="field_category" name="category" style="flex:1" class="form-control">
														<option value="0">-- Selecione a Categoria --</option>
														{section name=i loop=$categories}
														<option value="{$categories[i].CHID}"{if $video.category == $categories[i].CHID} selected="selected"{/if}>{$categories[i].name|escape:'html'}</option>
														{/section}
													</select>
													<button type="button" id="btn_add_category" class="btn btn-success btn-sm" title="Adicionar Nova Categoria" style="min-width: 36px; height: 34px; padding: 0 10px;">
														<i class="fa fa-plus"></i>
													</button>
												</div>
											</div>
											<div class="clearfix"></div>
										</div>

										<div class="form-group">
											<label class="col-lg-3 control-label">Tags / Keywords</label>
											<div class="col-lg-9">
												 <textarea class="form-control" name="tags" id="field_tags" rows="3" style="resize: vertical">{$video.tags|escape:'html'}</textarea>
												 <span class="help">Separadas por vírgula</span>
											</div>
											<div class="clearfix"></div>
										</div>

										<div class="form-group">
											<label class="col-lg-3 control-label">Duração</label>
											<div class="col-lg-9">
												<input class="form-control" name="duration" id="field_duration" type="text" value="{$video.duration}">
												<span class="help">Segundos ou formato MM:SS</span>
											</div>
											<div class="clearfix"></div>
										</div>

										<div class="form-group">
											<label class="col-lg-3 control-label">Qualidade / Resolução</label>
											<div class="col-lg-9">
												<select id="field_quality" name="quality" class="form-control" style="width:100%">
													<option value="best" selected="selected">Melhor Qualidade Disponível (Máxima)</option>
													<option value="1080">1080p (Full HD)</option>
													<option value="720">720p (HD)</option>
													<option value="480">480p (SD)</option>
													<option value="360">360p</option>
												</select>
												<span class="help">A resolução escolhida será baixada e depois processada pela fila do AVS</span>
											</div>
											<div class="clearfix"></div>
										</div>

										<div class="form-group">
											<label class="col-lg-3 control-label">Privacidade</label>
											<div class="col-lg-9">
												<div class="radio p-t-9">
													<input id="type_pb" type="radio" name="type" value="public" {if $video.type != 'private'}checked="checked"{/if} class="radio-enabled">
													<label for="type_pb">Público</label>
													<input id="type_pv" type="radio" name="type" value="private" {if $video.type == 'private'}checked="checked"{/if} class="radio-disabled">
													<label for="type_pv">Privado</label>												
												</div>
											</div>
											<div class="clearfix"></div>
										</div>
									</div>
								</div>
							</div>

							<div class="form-actions">
								<div class="pull-right">
									<button name="grab_video" type="submit" value="1" id="save_video_button" class="btn btn-success btn-cons">
										<i class="fa fa-download"></i> <span id="save_video_btn_text">Importar e Adicionar à Fila</span>
									</button>
									<a href="videos.php?m=all&all=1" class="btn btn-white btn-cons">Cancelar</a>
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

	<!-- MODAL ADICIONAR CATEGORIA -->
	<div class="modal fade" id="modal_add_category" tabindex="-1" role="dialog" aria-hidden="true" style="display: none;">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
					<h4 class="modal-title semi-bold"><i class="fa fa-folder-plus"></i> Adicionar Nova Categoria</h4>
				</div>
				<div class="modal-body">
					<div id="add_category_alert" style="display: none;"></div>
					<div class="form-group">
						<label class="col-lg-3 control-label">Nome</label>
						<div class="col-lg-9">
							<input id="add_category_name" name="add_category_name" type="text" class="form-control" placeholder="Nome da categoria">
						</div>
						<div class="clearfix"></div>
					</div>
					<div class="form-group">
						<label class="col-lg-3 control-label">Slug</label>
						<div class="col-lg-9">
							<input id="add_category_slug" name="add_category_slug" type="text" class="form-control" placeholder="Deixe em branco para gerar automaticamente">
							<span class="help">Se deixar em branco, o slug será gerado a partir do nome.</span>
						</div>
						<div class="clearfix"></div>
					</div>
					<div class="m-b-10"></div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-default pull-left" data-dismiss="modal">Cancelar</button>
					<button type="button" id="btn_save_category" class="btn btn-success">
						<i class="fa fa-check"></i> Salvar
					</button>
				</div>
			</div>
		</div>
	</div>

	<!-- MODAL PREVIEW DO PLAYER AVS -->
	<div class="modal fade" id="modal_video_preview" tabindex="-1" role="dialog" aria-hidden="true">
		<div class="modal-dialog modal-lg" style="width: 800px; max-width: 95%;">
			<div class="modal-content" style="background: #111; color: #fff; border-radius: 6px; overflow: hidden;">
				<div class="modal-header" style="border-bottom: 1px solid #222; padding: 12px 18px;">
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true" style="color: #fff; opacity: 0.8; font-size: 22px;">×</button>
					<h4 class="modal-title" style="color: #fff; font-size: 16px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
						<i class="fa fa-play-circle text-danger"></i> <span id="modal_preview_title">Prévia do Vídeo</span>
					</h4>
				</div>
				<div class="modal-body" style="padding: 0; background: #000;">
					<div style="position: relative; width: 100%; padding-top: 56.25%; background: #000;">
						<iframe id="preview_player_frame" src="" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0;" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
					</div>
				</div>
				<div class="modal-footer" style="border-top: 1px solid #222; padding: 10px 18px;">
					<span class="pull-left text-muted" style="font-size: 12px; line-height: 30px;">Player de Prévia AVS</span>
					<button type="button" class="btn btn-white btn-sm" data-dismiss="modal">Fechar</button>
				</div>
			</div>
		</div>
	</div>

	<script type="text/javascript">
	var currentVideoData = null;

	function showFetchStatus(type, message) {
		var box = document.getElementById('fetch_status_msg');
		if (!box) return;
		box.className = 'm-t-10 alert alert-' + type;
		box.innerHTML = message;
		box.style.display = 'block';
	}

	function openPreviewModal() {
		if (!currentVideoData) {
			showToast('Nenhum dado de vídeo carregado para prévia.', 'error');
			return;
		}

		var modalTitle = document.getElementById('modal_preview_title');
		if (modalTitle) {
			modalTitle.innerText = currentVideoData.title || 'Prévia do Vídeo';
		}

		var iframe = document.getElementById('preview_player_frame');
		if (iframe) {
			var playerUrl = 'preview_player.php?title=' + encodeURIComponent(currentVideoData.title || '')
				+ '&poster=' + encodeURIComponent(currentVideoData.thumbnail || '')
				+ '&embed=' + encodeURIComponent(currentVideoData.embed_url || '')
				+ '&src=' + encodeURIComponent(currentVideoData.stream_url || '');

			iframe.src = playerUrl;
		}

		if (typeof jQuery !== 'undefined') {
			jQuery('#modal_video_preview').modal('show');
		} else {
			var modal = document.getElementById('modal_video_preview');
			if (modal) {
				modal.className += ' in';
				modal.style.display = 'block';
			}
		}
	}

	function closePreviewModal() {
		var iframe = document.getElementById('preview_player_frame');
		if (iframe) {
			iframe.src = '';
		}
	}

	function fetchVideoInfo() {
		var urlInput = document.getElementById('grabber_url_input');
		var url = urlInput ? urlInput.value.trim() : '';

		if (url === '') {
			showFetchStatus('danger', 'Por favor, digite ou cole a URL do vídeo (YouTube, XFree, etc.).');
			return;
		}

		var btn = document.getElementById('btn_fetch_info');
		var btnText = document.getElementById('btn_fetch_text');
		if (btn) btn.disabled = true;
		if (btnText) btnText.innerText = 'Buscando...';

		showFetchStatus('info', '<i class="fa fa-spinner fa-spin"></i> Obtendo informações do vídeo. Aguarde alguns segundos...');

		var endpoint = 'videos.php?m=grabber&a=fetch';
		var formData = new FormData();
		formData.append('url', url);

		fetch(endpoint, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin'
		})
		.then(function(response) {
			return response.json();
		})
		.then(function(data) {
			if (btn) btn.disabled = false;
			if (btnText) btnText.innerText = 'Obter Dados';

			if (data && data.status === true) {
				currentVideoData = data;

				showFetchStatus('success', '<i class="fa fa-check"></i> <b>Dados obtidos com sucesso!</b> Clique na miniatura ou no botão para assistir à prévia.');

				// Preencher campos
				var fUrl = document.getElementById('field_url');
				var fTitle = document.getElementById('field_title');
				var fDesc = document.getElementById('field_description');
				var fTags = document.getElementById('field_tags');
				var fDur = document.getElementById('field_duration');
				var fThumb = document.getElementById('field_thumb_url');

				if (fUrl) fUrl.value = url;
				if (fTitle) fTitle.value = data.title || '';
				if (fDesc) fDesc.value = data.description || '';
				if (fTags) fTags.value = data.tags || '';
				if (fDur) fDur.value = data.duration_formatted || data.duration || '';
				if (fThumb) fThumb.value = data.thumbnail || '';

				// Atualizar Preview Card
				var pBox = document.getElementById('video_preview_box');
				var pTitle = document.getElementById('preview_title');
				var pDur = document.getElementById('preview_duration');
				var pSrc = document.getElementById('preview_source');
				var pImg = document.getElementById('preview_thumb_img');
				var pDesc = document.getElementById('preview_desc_short');

				if (pTitle) pTitle.innerText = data.title || '';
				if (pDur) pDur.innerText = data.duration_formatted || (data.duration + 's');
				if (pSrc) pSrc.innerText = data.site || 'YouTube';
				if (pImg && data.thumbnail) pImg.src = data.thumbnail;
				if (pDesc && data.description) {
					pDesc.innerText = data.description.length > 180 ? (data.description.substring(0, 180) + '...') : data.description;
				}
				if (pBox) pBox.style.display = 'block';

				// Atualizar Qualidades
				if (data.qualities) {
					var qSelect = document.getElementById('field_quality');
					if (qSelect) {
						qSelect.innerHTML = '';
						for (var key in data.qualities) {
							if (data.qualities.hasOwnProperty(key)) {
								var opt = document.createElement('option');
								opt.value = key;
								opt.innerText = data.qualities[key];
								if (key === 'best') {
									opt.selected = true;
								}
								qSelect.appendChild(opt);
							}
						}
						qSelect.value = 'best';
					}
				}
			} else {
				var errMsg = (data && data.error) ? data.error : 'Não foi possível extrair dados desta URL.';
				showFetchStatus('danger', '<i class="fa fa-exclamation-triangle"></i> ' + errMsg);
			}
		})
		.catch(function(err) {
			if (btn) btn.disabled = false;
			if (btnText) btnText.innerText = 'Obter Dados';
			showFetchStatus('danger', '<i class="fa fa-times"></i> Erro ao processar requisição: ' + err.message);
		});
	}

	window.addEventListener('DOMContentLoaded', function() {
		var form = document.getElementById('form_grabber_video');
		if (form) {
			form.addEventListener('submit', function(e) {
				var fUrl = document.getElementById('field_url');
				var urlInput = document.getElementById('grabber_url_input');
				if (fUrl && (!fUrl.value || fUrl.value.trim() === '')) {
					if (urlInput && urlInput.value.trim() !== '') {
						fUrl.value = urlInput.value.trim();
					} else {
						showToast('Por favor, obtenha os dados do vídeo antes de importar.', 'error');
						e.preventDefault();
						return false;
					}
				}

				var fTitle = document.getElementById('field_title');
				if (fTitle && fTitle.value.trim() === '') {
					showToast('Por favor, informe ou obtenha o Título do vídeo.', 'error');
					e.preventDefault();
					return false;
				}

				var fCat = document.getElementById('field_category');
				if (fCat && (fCat.value === '0' || fCat.value === '')) {
					showToast('Por favor, selecione uma Categoria para o vídeo.', 'error');
					fCat.focus();
					e.preventDefault();
					return false;
				}

				var btnSave = document.getElementById('save_video_button');
				var btnSaveText = document.getElementById('save_video_btn_text');
				if (btnSaveText) {
					btnSaveText.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Baixando vídeo e enfileirando... Aguarde!';
				}
				if (btnSave) {
					btnSave.style.opacity = '0.7';
					btnSave.style.pointerEvents = 'none';
				}
				return true;
			});
		}

		// Ao fechar o modal, pausar o player
		if (typeof jQuery !== 'undefined') {
			jQuery('#modal_video_preview').on('hidden.bs.modal', function() {
				closePreviewModal();
			});
		}

		// Botão [+] para adicionar categoria
		var btnAddCat = document.getElementById('btn_add_category');
		if (btnAddCat) {
			btnAddCat.addEventListener('click', function() {
				var nameInput = document.getElementById('add_category_name');
				var slugInput = document.getElementById('add_category_slug');
				var alertBox  = document.getElementById('add_category_alert');
				if (nameInput) nameInput.value = '';
				if (slugInput) slugInput.value = '';
				if (alertBox) { alertBox.style.display = 'none'; alertBox.innerHTML = ''; }
				if (typeof jQuery !== 'undefined') {
					jQuery('#modal_add_category').modal('show');
					setTimeout(function() { if (nameInput) nameInput.focus(); }, 300);
				}
			});
		}

		// Auto-gerar slug a partir do nome
		var addCatName = document.getElementById('add_category_name');
		if (addCatName) {
			addCatName.addEventListener('input', function() {
				var slugInput = document.getElementById('add_category_slug');
				if (slugInput && slugInput.dataset.manual !== '1') {
					slugInput.value = this.value
						.toLowerCase()
						.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
						.replace(/[^a-z0-9\s-]/g, '')
						.replace(/\s+/g, '-')
						.replace(/-+/g, '-')
						.replace(/^-|-$/g, '');
				}
			});
		}
		var addCatSlug = document.getElementById('add_category_slug');
		if (addCatSlug) {
			addCatSlug.addEventListener('input', function() {
				this.dataset.manual = this.value.trim() !== '' ? '1' : '0';
			});
		}

		// Salvar nova categoria via AJAX
		var btnSaveCat = document.getElementById('btn_save_category');
		if (btnSaveCat) {
			btnSaveCat.addEventListener('click', function() {
				var nameInput  = document.getElementById('add_category_name');
				var slugInput  = document.getElementById('add_category_slug');
				var alertBox   = document.getElementById('add_category_alert');
				var name = nameInput ? nameInput.value.trim() : '';
				var slug = slugInput ? slugInput.value.trim() : '';

				if (name === '') {
					if (alertBox) {
						alertBox.className = 'alert alert-danger';
						alertBox.innerHTML = '<i class="fa fa-exclamation-triangle"></i> Informe o nome da categoria.';
						alertBox.style.display = 'block';
					}
					if (nameInput) nameInput.focus();
					return;
				}

				btnSaveCat.disabled = true;
				btnSaveCat.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Salvando...';

				var formData = new FormData();
				formData.append('name', name);
				formData.append('slug', slug);

				var ajaxUrl = 'videos.php?m=grabber&a=add_category';
				fetch(ajaxUrl, {
					method: 'POST',
					body: formData,
					credentials: 'same-origin'
				})
				.then(function(response) { return response.json(); })
				.then(function(data) {
					btnSaveCat.disabled = false;
					btnSaveCat.innerHTML = '<i class="fa fa-check"></i> Salvar';

					if (data && data.status === 1) {
						// Adicionar nova opção ao select
						var select = document.getElementById('field_category');
						if (select) {
							var opt = document.createElement('option');
							opt.value = data.id;
							opt.text  = data.name;
							opt.selected = true;
							// Inserir antes da última opção se existir, ou no final
							var lastOption = select.options[select.options.length - 1];
							if (lastOption && lastOption.value === '0') {
								select.insertBefore(opt, lastOption.nextSibling);
							} else {
								select.appendChild(opt);
							}
						}

						// Fechar modal
						if (typeof jQuery !== 'undefined') {
							jQuery('#modal_add_category').modal('hide');
						}

						// Notificação de sucesso
						if (typeof jQuery !== 'undefined' && jQuery.messenger) {
							jQuery.messenger().post({ message: 'Categoria "' + data.name + '" criada com sucesso!', type: 'success' });
						}
					} else {
						var errMsg = (data && data.error) ? data.error : 'Erro ao criar categoria.';
						if (alertBox) {
							alertBox.className = 'alert alert-danger';
							alertBox.innerHTML = '<i class="fa fa-exclamation-triangle"></i> ' + errMsg;
							alertBox.style.display = 'block';
						}
					}
				})
				.catch(function(err) {
					btnSaveCat.disabled = false;
					btnSaveCat.innerHTML = '<i class="fa fa-check"></i> Salvar';
					if (alertBox) {
						alertBox.className = 'alert alert-danger';
						alertBox.innerHTML = '<i class="fa fa-times"></i> Erro ao processar requisição: ' + err.message;
						alertBox.style.display = 'block';
					}
				});
			});
		}
	});
	</script>
