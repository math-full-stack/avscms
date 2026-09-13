<div id="avs-shorts-app" class="avs-shorts-app" data-baseurl="{$baseurl}" data-active-tab="{$active_tab}" data-initial-vid="{$initial_vid}" data-logged="{if isset($smarty.session.uid) && $smarty.session.uid}1{else}0{/if}">
	<!-- Topbar Flutuante Imersiva -->
	<header class="avs-shorts-topbar">
		<a href="{$baseurl}/" class="avs-shorts-back" title="Voltar ao início">
			<span class="material-symbols-rounded" aria-hidden="true">arrow_back</span>
		</a>

		<nav class="avs-shorts-tabs" role="tablist" aria-label="Abas de Shorts">
			<a href="{$baseurl}/shorts?tab=foryou" class="avs-shorts-tab {if $active_tab == 'foryou'}active{/if}" data-tab="foryou">Para Você</a>
			<a href="{$baseurl}/shorts?tab=trending" class="avs-shorts-tab {if $active_tab == 'trending'}active{/if}" data-tab="trending">Em Alta</a>
			<a href="{$baseurl}/shorts?tab=recent" class="avs-shorts-tab {if $active_tab == 'recent'}active{/if}" data-tab="recent">Recentes</a>
		</nav>

		<div class="avs-shorts-top-actions">
			<button type="button" class="avs-shorts-sound-btn" id="avs-global-mute-btn" title="Alternar som (M)" aria-label="Alternar som">
				<span class="material-symbols-rounded sound-icon-off" aria-hidden="true">volume_off</span>
				<span class="material-symbols-rounded sound-icon-on" aria-hidden="true" style="display:none;">volume_up</span>
			</button>
		</div>
	</header>

	<!-- Container de Rolagem com Scroll Snap -->
	<main class="avs-shorts-stream" id="avs-shorts-stream" tabindex="0" aria-label="Feed de Vídeos Verticais">
		{if $initial_videos}
			{section name=i loop=$initial_videos}
				{assign var="v" value=$initial_videos[i]}
				<article class="avs-short-card" id="short-{$v.vid}" data-vid="{$v.vid}" data-index="{$smarty.section.i.index}" data-title="{$v.title|escape:'html'}" data-author="{$v.creator.username|escape:'html'}" data-share-url="{$v.share_url}">
					<!-- Projeção Ambient Blur (Desktop) -->
					<div class="avs-ambient-bg" style="background-image: url('{$v.poster_url}');" aria-hidden="true"></div>

					<!-- Container Central do Vídeo -->
					<div class="avs-player-wrapper {if $v.is_vertical}avs-is-vertical{else}avs-is-horizontal{/if}">
						<video class="avs-video-el" 
							   src="{$v.video_url}" 
							   poster="{$v.poster_url}" 
							   playsinline 
							   webkit-playsinline 
							   loop 
							   preload="{if $smarty.section.i.index == 0}auto{elseif $smarty.section.i.index <= 2}metadata{else}none{/if}"
							   muted>
						</video>

						<!-- Feedback Animado de Play / Pause -->
						<div class="avs-play-pulse" aria-hidden="true">
							<span class="material-symbols-rounded icon-play">play_arrow</span>
							<span class="material-symbols-rounded icon-pause" style="display:none;">pause</span>
						</div>

						<!-- Animação do Coração (Double Tap) -->
						<div class="avs-heart-burst" aria-hidden="true"></div>

						<!-- Badge Flutuante de Áudio (Toque para desmutar) -->
						<button type="button" class="avs-unmute-overlay-badge" aria-label="Ativar áudio">
							<span class="material-symbols-rounded" aria-hidden="true">volume_off</span>
							<span>Toque para ativar o som</span>
						</button>

						<!-- Metadados Inferiores do Vídeo -->
						<div class="avs-short-meta-bottom">
							<!-- Criador -->
							<div class="avs-short-creator">
								<a href="{$v.creator.channel_url}" class="avs-short-avatar" title="{$v.creator.username|escape:'html'}">
									<img src="{$v.creator.avatar_url}" alt="{$v.creator.username|escape:'html'}" loading="lazy">
								</a>
								<a href="{$v.creator.channel_url}" class="avs-short-username">@{$v.creator.username|escape:'html'}</a>
								{if isset($smarty.session.uid) && $smarty.session.uid != $v.creator.uid}
									<button type="button" class="avs-btn-follow {if $v.creator.is_subscribed}following{/if}" data-uid="{$v.creator.uid}" title="Seguir criador">
										{if $v.creator.is_subscribed}Seguindo{else}Seguir{/if}
									</button>
								{/if}
							</div>

							<!-- Título & Descrição -->
							<div class="avs-short-title-wrap">
								<h2 class="avs-short-title">{$v.title|escape:'html'}</h2>
								{if $v.description}
									<p class="avs-short-desc">{$v.description|escape:'html'}</p>
								{/if}
							</div>

							<!-- Áudio / Trilha Sonora -->
							<div class="avs-short-music">
								<span class="material-symbols-rounded music-note-icon" aria-hidden="true">music_note</span>
								<div class="avs-music-marquee">
									<span>Áudio original — @{$v.creator.username|escape:'html'} • {$v.title|escape:'html'}</span>
								</div>
							</div>
						</div>

						<!-- Barra Lateral de Ações (Estilo Reels / TikTok) -->
						<aside class="avs-short-actions" aria-label="Ações do vídeo">
							<!-- Avatar com botão Seguir -->
							<div class="avs-action-item avs-action-profile">
								<a href="{$v.creator.channel_url}" class="avs-action-avatar-link" title="{$v.creator.username|escape:'html'}">
									<img src="{$v.creator.avatar_url}" alt="{$v.creator.username|escape:'html'}" class="avs-action-avatar-img">
								</a>
								<button type="button" class="avs-action-plus-btn {if $v.creator.is_subscribed}active{/if}" data-uid="{$v.creator.uid}" title="Seguir criador" aria-label="Seguir">
									<span class="material-symbols-rounded" aria-hidden="true">{if $v.creator.is_subscribed}check{else}add{/if}</span>
								</button>
							</div>

							<!-- Curtir (Like) -->
							<div class="avs-action-item">
								<button type="button" class="avs-action-btn avs-btn-like {if $v.is_liked}active{/if}" data-vid="{$v.vid}" title="Curtir vídeo (L)" aria-label="Curtir vídeo">
									<span class="material-symbols-rounded icon-outline" aria-hidden="true">favorite</span>
								</button>
								<span class="avs-action-label avs-like-count" data-count="{$v.likes}">{$v.likes_formatted}</span>
							</div>

							<!-- Comentários -->
							<div class="avs-action-item">
								<button type="button" class="avs-action-btn avs-btn-comments" data-vid="{$v.vid}" title="Ver comentários (C)" aria-label="Comentários">
									<span class="material-symbols-rounded" aria-hidden="true">chat_bubble</span>
								</button>
								<span class="avs-action-label avs-comment-count">{$v.comments_formatted}</span>
							</div>

							<!-- Favoritar -->
							<div class="avs-action-item">
								<button type="button" class="avs-action-btn avs-btn-fav {if $v.is_fav}active{/if}" data-vid="{$v.vid}" title="Salvar nos favoritos" aria-label="Favoritar">
									<span class="material-symbols-rounded" aria-hidden="true">bookmark</span>
								</button>
								<span class="avs-action-label">Salvar</span>
							</div>

							<!-- Compartilhar -->
							<div class="avs-action-item">
								<button type="button" class="avs-action-btn avs-btn-share" data-vid="{$v.vid}" data-url="{$v.share_url}" data-title="{$v.title|escape:'html'}" title="Compartilhar" aria-label="Compartilhar">
									<span class="material-symbols-rounded" aria-hidden="true">share</span>
								</button>
								<span class="avs-action-label">Enviar</span>
							</div>

							<!-- Mute / Som Individual -->
							<div class="avs-action-item avs-action-audio">
								<button type="button" class="avs-action-btn avs-btn-sound" title="Alternar som" aria-label="Som">
									<span class="material-symbols-rounded icon-sound-off" aria-hidden="true">volume_off</span>
									<span class="material-symbols-rounded icon-sound-on" aria-hidden="true" style="display:none;">volume_up</span>
								</button>
							</div>

							<!-- Disco de Vinil Giratório -->
							<div class="avs-music-disc" aria-hidden="true">
								<img src="{$v.creator.avatar_url}" alt="" class="disc-art">
							</div>
						</aside>

						<!-- Barra de Progresso Fina (Scrubber) -->
						<div class="avs-short-progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
							<div class="avs-short-progress-fill"></div>
						</div>
					</div>

					<!-- Botões Desktop de Próximo / Anterior -->
					<div class="avs-desktop-nav" aria-hidden="true">
						<button type="button" class="avs-nav-btn avs-nav-prev" title="Vídeo anterior (↑ ou K)" aria-label="Vídeo anterior">
							<span class="material-symbols-rounded">expand_less</span>
						</button>
						<button type="button" class="avs-nav-btn avs-nav-next" title="Próximo vídeo (↓ ou J)" aria-label="Próximo vídeo">
							<span class="material-symbols-rounded">expand_more</span>
						</button>
					</div>
				</article>
			{/section}
		{else}
			<div class="avs-shorts-empty">
				<span class="material-symbols-rounded empty-icon">movie</span>
				<h3>Nenhum vídeo encontrado nesta seção</h3>
				<p>Explore as outras abas para continuar assistindo aos melhores vídeos verticais.</p>
				<a href="{$baseurl}/shorts?tab=foryou" class="btn btn-primary">Voltar para "Para Você"</a>
			</div>
		{/if}

		<!-- Spinner de Carregamento Infinito -->
		<div class="avs-shorts-infinite-loader" id="avs-shorts-infinite-loader" style="display:none;">
			<div class="avs-spinner"></div>
			<span>Carregando mais vídeos...</span>
		</div>
	</main>

	<!-- Drawer / Painel de Comentários (Lateral Desktop / Bottom Sheet Mobile) -->
	<aside class="avs-comments-drawer" id="avs-comments-drawer" aria-hidden="true">
		<div class="avs-drawer-backdrop" id="avs-drawer-backdrop"></div>
		<div class="avs-drawer-content">
			<header class="avs-drawer-header">
				<div class="avs-drawer-handle" aria-hidden="true"></div>
				<div class="avs-drawer-title-wrap">
					<h3 class="avs-drawer-title">Comentários</h3>
					<span class="avs-drawer-count" id="avs-drawer-count">(0)</span>
				</div>
				<button type="button" class="avs-drawer-close-btn" id="avs-drawer-close-btn" aria-label="Fechar comentários">
					<span class="material-symbols-rounded">close</span>
				</button>
			</header>

			<!-- Lista de Comentários com Scroll -->
			<div class="avs-comments-body" id="avs-comments-body">
				<div class="avs-comments-loading" id="avs-comments-loading">
					<div class="avs-spinner"></div>
					<span>Carregando comentários...</span>
				</div>
				<div class="avs-comments-container" id="avs-comments-container"></div>
				<div class="avs-comments-empty" id="avs-comments-empty" style="display:none;">
					<span class="material-symbols-rounded">chat_bubble_outline</span>
					<p>Nenhum comentário ainda.<br>Seja o primeiro a comentar!</p>
				</div>
			</div>

			<!-- Rodapé de Envio de Comentário -->
			<footer class="avs-drawer-footer">
				{if isset($smarty.session.uid) && $smarty.session.uid}
					<form class="avs-comment-form" id="avs-comment-form">
						<input type="text" class="avs-comment-input" id="avs-comment-input" placeholder="Adicione um comentário..." maxlength="500" autocomplete="off" required>
						<button type="submit" class="avs-comment-submit-btn" id="avs-comment-submit-btn" aria-label="Publicar comentário">
							<span class="material-symbols-rounded">send</span>
						</button>
					</form>
				{else}
					<div class="avs-comment-login-prompt">
						<p>Você precisa estar conectado para comentar.</p>
						<a href="{$baseurl}/login" class="avs-btn-login-prompt">Entrar</a>
					</div>
				{/if}
			</footer>
		</div>
	</aside>

	<!-- Toast Notificação Flutuante -->
	<div class="avs-shorts-toast" id="avs-shorts-toast" aria-live="polite" role="status"></div>
</div>

<!-- Dados Iniciais em JSON para hidratação veloz do JS -->
<script>
	window.__AVS_INITIAL_SHORTS = {$initial_videos_json};
	window.__AVS_EXCLUDE_VIDS = {$exclude_vids_json};
</script>
