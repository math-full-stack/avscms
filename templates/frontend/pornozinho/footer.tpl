<div class="footer-container">
	<div class="footer-links">
		<div class="container">
			<div class="row">
				<div class="col-sm-3">
					<h4>{t c='footer.information'}</h4>
					<ul class="list-unstyled">
						<li><a href="{$relative}/static/terms" rel="nofollow">{translate c='footer.terms'}</a></li>
						<li><a href="{$relative}/static/privacy" rel="nofollow">{translate c='footer.privacy'}</a></li>
						<li><a href="{$relative}/static/dmca" rel="nofollow">{translate c='footer.dmca'}</a></li>
						<li><a href="{$relative}/static/_2257" rel="nofollow">{translate c='footer.2257'}</a></li>
					</ul>
				</div>
				<div class="col-sm-3">
					<h4>{t c='footer.work_with_us'}</h4>
					<ul class="list-unstyled">
						<li><a href="{$relative}/static/advertise" rel="nofollow">{translate c='footer.advertise'}</a></li>
						<li><a href="{$relative}/static/webmasters" rel="nofollow">{translate c='footer.webmasters'}</a></li>
						<li><a href="{$relative}/invite" rel="nofollow">{translate c='global.invite_friends'}</a></li>						
					</ul>
				</div>
				<div class="col-sm-3">
					<h4>{t c='footer.support_and_help'}</h4>
					<ul class="list-unstyled">
						<li><a href="{$relative}/notices">{translate c='global.notice'}</a></li>				
						<li><a href="{$relative}/static/faq" rel="nofollow">{translate c='footer.faq'}</a></li>
						<li><a href="{$relative}/feedback" rel="nofollow">{translate c='global.support_feedback'}</a></li>				
					</ul>
				</div>
				<div class="col-sm-3">
					<h4>Redes sociais</h4>
					<ul class="list-unstyled">
						<li><a href="https://www.facebook.com/{$facebook_id}/" target="_blank" rel="nofollow"><i class="fab fa-facebook-f"></i>&nbsp;&nbsp;Facebook</a></li>							
						<li><a href="https://www.instagram.com/{$instagram_id}/" target="_blank" rel="nofollow"><i class="fab fa-instagram"></i>&nbsp;&nbsp;Instagram</a></li>					
						<li><a href="https://twitter.com/{$twitter_id}/" target="_blank" rel="nofollow"><i class="fab fa-twitter"></i>&nbsp;&nbsp;Twitter</a></li>
						<li><a href="https://www.reddit.com/user/{$reddit_id}/" target="_blank" rel="nofollow"><i class="fab fa-reddit"></i>&nbsp;&nbsp;Reddit</a></li>							
					</ul>
				</div>					
			</div>
		</div>
	</div>
	<div class="footer">
		<div class="container">
			<div class="d-none d-sm-block">
				<div class="float-left">
					<span>{t c='footer.copyright'} &#169; 2008-2023</span> <span class="text-highlighted">{$site_name}</span>
				</div>
				<div class="clearfix"></div>
			</div>
			<div class="d-block d-sm-none"><span>{t c='footer.copyright'} &#169; 2008-2023</span> <span class="text-highlighted">{$site_name}</span></div>
		</div>
	</div>
	<div id="alerts_bottom"></div>
</div>

<!-- Barra de navegação inferior (mobile) -->
<nav class="xb-mobnav">
	<a href="{$relative}/" class="{if $menu == 'home'}active{/if}"><i class="fas fa-home"></i>Início</a>
	<a href="{$relative}/videos?o=mv"><i class="fas fa-fire"></i>Hot</a>
	{if $video_module == '1'}
	<a href="{$relative}/upload"><i class="fas fa-plus-circle"></i>Upload</a>
	{/if}
	<a href="{$relative}/categories"><i class="fas fa-th-large"></i>Categorias</a>
	<a href="{if isset($smarty.session.uid)}{$relative}/user{else}{$relative}/signup{/if}"><i class="fas fa-user"></i>Perfil</a>
</nav>

    <!-- Bootstrap core JavaScript
    ================================================== -->
    <!-- Placed at the end of the document so the pages load faster -->
	<script>
		var suggestion_arr = {$suggestion};
	</script>
    <script type="text/javascript" src="{$relative_tpl}/js/jquery.rotator.js"></script>
    <script type="text/javascript" src="{$relative_tpl}/js/jquery.main.js"></script>	
    <script type="text/javascript" src="{$relative_tpl}/js/jquery.easy-autocomplete.min.js"></script>
    <script type="text/javascript" src="{$relative_tpl}/js/md3-ripple.js"></script>
<script>
	{literal}
		$(document).ready(function() {
			var searchUrl = base_url + '/ajax/search_suggestions';
			var options = {
				url: function(phrase) {
					return searchUrl + '?q=' + encodeURIComponent(phrase) + '&type=' + $('#search_type').val();
				},
				getValue: function(element) {
					return element.expression;
				},
				ajaxSettings: {
					dataType: "json",
					method: "GET"
				},
				requestDelay: 300,
				minCharNumber: 2,
				list: {
					maxNumberOfElements: 8,
					match: {
						enabled: true
					},
					onSelectItemEvent: function() {
						var value = $("#search_query").getSelectedItemData().expression;
						$("#search_query").val(value);
						$("#search_form").submit();
					},
					showAnimation: {
						type: "fade",
						time: 200
					},
					hideAnimation: {
						type: "fade",
						time: 200
					}
				},
				theme: "pornozinho"
			};
			$("#search_query").easyAutocomplete(options);
			$("#search_query_xs").easyAutocomplete(options);

			var $topbar = $('.xb-topbar');
			var $header = $('.xb-header');
			var $navScrim = $('#xbNavScrim');

			function setNavAria($dropdown, on) {
				$dropdown.find('.xb-nav-dropdown-toggle').attr('aria-expanded', on ? 'true' : 'false');
			}

			function closeNavDropdowns() {
				$('.xb-nav-dropdown').each(function() {
					clearTimeout($(this).data('openTimer'));
					clearTimeout($(this).data('closeTimer'));
					setNavAria($(this), false);
					resetMmPanel($(this));
				});
				$('.xb-nav-dropdown').removeClass('show open');
				$navScrim.removeClass('show');
			}

			function toggleNavDropdown($dropdown) {
				clearTimeout($dropdown.data('openTimer'));
				clearTimeout($dropdown.data('closeTimer'));
				var desktop = $(window).width() >= 992;
				var cls = desktop ? 'show' : 'open';
				var isOpen = $dropdown.hasClass(cls);
				$('.xb-nav-dropdown').removeClass('show open').each(function() {
					setNavAria($(this), false);
				});
				$navScrim.removeClass('show');
				if (!isOpen) {
					$dropdown.addClass(cls);
					setNavAria($dropdown, true);
					if (!desktop) {
						$navScrim.addClass('show');
					}
				}
			}

			function setMmPanel($dropdown, key) {
				var $panel = $dropdown.find('.xb-mm-panel[data-mm-panel="' + key + '"]');
				if (!$panel.length) return;
				$dropdown.find('.xb-mm-panel').removeClass('xb-mm-active');
				$panel.addClass('xb-mm-active');
				$dropdown.find('.xb-mm-item').removeClass('xb-mm-current');
				$dropdown.find('.xb-mm-item[data-mm="' + key + '"]').addClass('xb-mm-current');
				$dropdown.find('.xb-mm-title').text($dropdown.find('.xb-mm-item[data-mm="' + key + '"]').data('mm-label'));
			}

			function resetMmPanel($dropdown) {
				var $first = $dropdown.find('.xb-mm-panel').first();
				if ($first.length) {
					setMmPanel($dropdown, $first.data('mm-panel'));
				}
			}

			// Desktop: hover intent (delay de abertura/fechamento)
			$(document).on('mouseenter', '.xb-nav-dropdown', function() {
				if ($(window).width() < 992) return;
				var $dropdown = $(this);
				clearTimeout($dropdown.data('closeTimer'));
				$dropdown.data('openTimer', setTimeout(function() {
					$('.xb-nav-dropdown').removeClass('show').each(function() {
						setNavAria($(this), false);
					});
					$dropdown.addClass('show');
					setNavAria($dropdown, true);
				}, 120));
			}).on('mouseleave', '.xb-nav-dropdown', function() {
				if ($(window).width() < 992) return;
				var $dropdown = $(this);
				clearTimeout($dropdown.data('openTimer'));
				$dropdown.data('closeTimer', setTimeout(function() {
					$dropdown.removeClass('show open');
					setNavAria($dropdown, false);
					$navScrim.removeClass('show');
					resetMmPanel($dropdown);
				}, 180));
			});

			// Mega menu Vídeos: hover/focus no item troca os cards (desktop)
			$(document).on('mouseenter focus', '.xb-mm-item', function() {
				if ($(window).width() < 992) return;
				setMmPanel($(this).closest('.xb-nav-dropdown'), $(this).data('mm'));
			});

			// Mobile dropdown toggle
			$(document).on('click', '.xb-nav-dropdown-toggle', function(e) {
				if ($(window).width() >= 992) return;
				e.preventDefault();
				e.stopPropagation();
				toggleNavDropdown($(this).closest('.xb-nav-dropdown'));
			});

			// Close dropdown when clicking outside
			$(document).on('click', function(e) {
				if (!$(e.target).closest('.xb-nav-dropdown').length) {
					closeNavDropdowns();
				}
			});

			// Close dropdowns via scrim (mobile bottom sheet)
			$navScrim.on('click', closeNavDropdowns);

			// Ao voltar para desktop, descarta estado mobile
			$(window).on('resize', function() {
				if ($(window).width() >= 992) {
					$('.xb-nav-dropdown').removeClass('open');
					$navScrim.removeClass('show');
				}
			});

			// Prevent dropdown close when clicking inside
			$(document).on('click', '.xb-dropdown-menu', function(e) {
				e.stopPropagation();
			});

			// Update search type for autocomplete when changed
			$(document).on('click', '.xb-search-type-option', function(e) {
				e.preventDefault();
				var type = $(this).data('type');
				var $form = $(this).closest('form');
				var $input = $form.find('input[name="search_query"]');
				var $hiddenType = $form.find('input[id^="search_type"]');
				var $btn = $form.find('.xb-search-type-btn');
				var $icon = $btn.find('.xb-search-type-icon');
				
				$hiddenType.val(type);
				
				// Update icon
				var icons = {
					'videos': '<i class="fas fa-video"></i>',
					'photos': '<i class="fas fa-camera"></i>',
					'users': '<i class="fas fa-user"></i>'
				};
				$icon.html(icons[type]);
				
				// Update placeholder
				var placeholders = {
					'videos': '{t c="ajax.search"} {t c="global.videos"}',
					'photos': '{t c="ajax.search"} {t c="global.albums"}',
					'users': '{t c="ajax.search"} {t c="global.users"}'
				};
				$input.attr('placeholder', placeholders[type]);
				
				// Close dropdown
				$btn.dropdown('toggle');
			});

			// Header scroll effect (topbar some, nav fica colada no topo)
			$(window).on('scroll', function() {
				var currentScroll = $(this).scrollTop();
				$topbar.toggleClass('xb-topbar-scrolled', currentScroll > 50);
				$header.toggleClass('xb-header-sticky', currentScroll > 80);
			});

			// Inicializa estado ao carregar com a página já rolada
			$(window).trigger('scroll');

			// Keyboard navigation for nav dropdowns
			$('.xb-nav-dropdown-toggle').on('keydown', function(e) {
				var $dropdown = $(this).closest('.xb-nav-dropdown');
				var $menu = $dropdown.find('.xb-dropdown-menu');
				
				switch(e.key) {
					case 'Enter':
					case ' ':
						e.preventDefault();
						toggleNavDropdown($dropdown);
						break;
					case 'Escape':
						closeNavDropdowns();
						$(this).focus();
						break;
					case 'ArrowDown':
						e.preventDefault();
						if ($menu.is(':visible')) {
							$menu.find('a:first').focus();
						}
						break;
				}
			});

			$('.xb-dropdown-menu a').on('keydown', function(e) {
				var $items = $(this).closest('.xb-dropdown-menu').find('a');
				var index = $items.index(this);
				
				switch(e.key) {
					case 'ArrowDown':
						e.preventDefault();
						if (index < $items.length - 1) {
							$items.eq(index + 1).focus();
						}
						break;
					case 'ArrowUp':
						e.preventDefault();
						if (index > 0) {
							$items.eq(index - 1).focus();
						} else {
							$(this).closest('.xb-nav-dropdown').find('.xb-nav-dropdown-toggle').focus();
						}
						break;
					case 'Escape':
						e.preventDefault();
						closeNavDropdowns();
						$(this).closest('.xb-nav-dropdown').find('.xb-nav-dropdown-toggle').focus();
						break;
					case 'Tab':
						if (e.shiftKey && index === 0) {
							e.preventDefault();
							$(this).closest('.xb-nav-dropdown').find('.xb-nav-dropdown-toggle').focus();
						}
						break;
				}
			});

			// Close dropdowns on escape key globally
			$(document).on('keydown', function(e) {
				if (e.key === 'Escape') {
					closeNavDropdowns();
					$('.btn-group').removeClass('show');
				}
			});

			// User dropdown keyboard support
			$('.xb-actions .dropdown-toggle').on('keydown', function(e) {
				var $dropdown = $(this).next('.dropdown-menu');
				switch(e.key) {
					case 'Enter':
					case ' ':
						e.preventDefault();
						$(this).dropdown('toggle');
						break;
					case 'Escape':
						$dropdown.removeClass('show');
						$(this).focus();
						break;
					case 'ArrowDown':
						e.preventDefault();
						if ($dropdown.hasClass('show')) {
							$dropdown.find('.dropdown-item:first').focus();
						}
						break;
				}
			});

			$('.xb-actions .dropdown-item').on('keydown', function(e) {
				var $items = $(this).closest('.dropdown-menu').find('.dropdown-item');
				var index = $items.index(this);
				
				switch(e.key) {
					case 'ArrowDown':
						e.preventDefault();
						if (index < $items.length - 1) {
							$items.eq(index + 1).focus();
						}
						break;
					case 'ArrowUp':
						e.preventDefault();
						if (index > 0) {
							$items.eq(index - 1).focus();
						} else {
							$(this).closest('.btn-group').find('.dropdown-toggle').focus();
						}
						break;
					case 'Escape':
						e.preventDefault();
						$(this).closest('.btn-group').removeClass('show');
						$(this).closest('.btn-group').find('.dropdown-toggle').focus();
						break;
				}
			});

			// Search type dropdown keyboard support
			$('.xb-search-type-btn').on('keydown', function(e) {
				var $dropdown = $(this).next('.xb-search-type-dropdown');
				switch(e.key) {
					case 'Enter':
					case ' ':
						e.preventDefault();
						$(this).dropdown('toggle');
						break;
					case 'Escape':
						$dropdown.removeClass('show');
						$(this).focus();
						break;
					case 'ArrowDown':
						e.preventDefault();
						if ($dropdown.hasClass('show')) {
							$dropdown.find('.xb-search-type-option:first').focus();
						}
						break;
				}
			});

			$('.xb-search-type-option').on('keydown', function(e) {
				var $items = $(this).closest('.xb-search-type-dropdown').find('.xb-search-type-option');
				var index = $items.index(this);
				
				switch(e.key) {
					case 'ArrowDown':
						e.preventDefault();
						if (index < $items.length - 1) {
							$items.eq(index + 1).focus();
						}
						break;
					case 'ArrowUp':
						e.preventDefault();
						if (index > 0) {
							$items.eq(index - 1).focus();
						} else {
							$(this).closest('.xb-search-type').find('.xb-search-type-btn').focus();
						}
						break;
					case 'Escape':
						e.preventDefault();
						$(this).closest('.xb-search-type').find('.xb-search-type-btn').focus();
						$(this).closest('.xb-search-type-dropdown').removeClass('show');
						break;
				}
			});
		});
	{/literal}
</script>
	{if $view && !$video.embed_code && $player.engine != 'mediabunny' && $player.engine != 'vidstack'}
		<script src="{$baseurl}/media/player/videojs/video-js-events.js?ver=1.1.1"></script>			
	{/if}
	{if $g_signin == '1' || $fb_signin == '1'}
		<script type="text/javascript" src="{$relative_tpl}/js/jquery.load-apis.js"></script>	
	{/if}	
	<script>
	{literal}
			if (navigator.userAgent.match(/IEMobile\/10\.0/)) {
		  var msViewportStyle = document.createElement('style')
		  msViewportStyle.appendChild(
			document.createTextNode(
			  '@-ms-viewport{width=1280!important}'
			)
		  )
		  document.querySelector('head').appendChild(msViewportStyle)
		}
{/literal}
	</script>
	{* Mini player flutuante: com o cookie avs_mini (gravado por avs-player.js enquanto
	   há vídeo tocando) a janelinha já vem pronta no HTML, em vez de ser montada por JS
	   a cada página — era isso que dava a sensação de recarregar. O avs-mini.js só
	   hidrata este shell; sem o cookie nada é renderizado e o custo é zero (por isso
	   não é sempre: nem toda página tem vídeo para ressuscitar). O avs-player.css vem
	   junto porque é ele que dá o visual do mini, que usa as MESMAS classes do player
	   da página do vídeo. No embed/view o mini interno do player já está na página, daí
	   o !$view; e o avs-mini.js remove o shell em qualquer página que tenha player. *}
	{if isset($smarty.cookies.avs_mini) && $smarty.cookies.avs_mini == '1' && !$view}
	<link rel="stylesheet" href="{$baseurl}/media/player/mediabunny/avs-player.css?ver=3.2.7">
	<div id="avs-mini-floating" class="avs-player avs-mini-mode avs-fallback" style="display:none">
		<svg class="avs-sprite" aria-hidden="true">
			<symbol id="avs-i-play" viewBox="0 0 24 24"><path fill="currentColor" d="M8 5v14l11-7z"/></symbol>
			<symbol id="avs-i-pause" viewBox="0 0 24 24"><path fill="currentColor" d="M6 5h4v14H6zM14 5h4v14h-4z"/></symbol>
			<symbol id="avs-i-vol-high" viewBox="0 0 24 24"><path fill="currentColor" d="M3 9v6h4l5 4V5L7 9H3z"/><path fill="currentColor" d="M16.2 8.3a4.8 4.8 0 0 1 0 7.4l-1.2-1.4a2.9 2.9 0 0 0 0-4.6z"/><path fill="currentColor" d="M18.7 5.7a8.4 8.4 0 0 1 0 12.6l-1.2-1.4a6.5 6.5 0 0 0 0-9.8z"/></symbol>
			<symbol id="avs-i-vol-low" viewBox="0 0 24 24"><path fill="currentColor" d="M3 9v6h4l5 4V5L7 9H3z"/><path fill="currentColor" d="M16.2 8.3a4.8 4.8 0 0 1 0 7.4l-1.2-1.4a2.9 2.9 0 0 0 0-4.6z"/></symbol>
			<symbol id="avs-i-vol-mute" viewBox="0 0 24 24"><path fill="currentColor" d="M3 9v6h4l5 4V5L7 9H3z"/><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M16.5 8l4 8M20.5 8l-4 8"/></symbol>
			<symbol id="avs-i-settings" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3.2"/><g fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M12 2.5v3.2M12 18.3v3.2M2.5 12h3.2M18.3 12h3.2"/><path d="M5 5l2.3 2.3M16.7 16.7 19 19M5 19l2.3-2.3M16.7 7.3 19 5"/></g></symbol>
			<symbol id="avs-i-pip" viewBox="0 0 24 24"><path fill="currentColor" d="M21 3H3c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h18c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H3V5h18v14zm-11-9h9v6h-9z"/></symbol>
			<symbol id="avs-i-close" viewBox="0 0 24 24"><path fill="currentColor" d="M18.3 5.7 12 12l6.3 6.3-1.4 1.4L10.6 13l-6.3 6.3-1.4-1.4L9.8 12 3.5 5.7l1.4-1.4 6.3 6.3 6.3-6.3z"/></symbol>
			<symbol id="avs-i-fs-enter" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H3v5M16 3h5v5M8 21H3v-5M16 21h5v-5"/></g></symbol>
			<symbol id="avs-i-fs-exit" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3v5H3M16 3v5h5M8 21v-5H3M16 21v-5h5"/></g></symbol>
			<symbol id="avs-i-rw10" viewBox="0 0 24 24"><path fill="currentColor" d="M10.5 8.4 6.6 12l3.9 3.6z"/><path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" d="M14.8 8.4v7.2"/><circle cx="18.2" cy="12" r="2.1" fill="none" stroke="currentColor" stroke-width="1.6"/></symbol>
			<symbol id="avs-i-fw10" viewBox="0 0 24 24"><path fill="currentColor" d="M6.4 12l3.9-3.6v7.2z"/><path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" d="M14.2 8.4v7.2"/><circle cx="17.6" cy="12" r="2.1" fill="none" stroke="currentColor" stroke-width="1.6"/></symbol>
		</svg>
		<video class="avs-fallback-video" playsinline preload="auto"></video>
		<div class="avs-center" aria-hidden="true">
			<button type="button" class="avs-center-btn avs-center-rw" title="-10 segundos"><svg class="avs-icon" aria-hidden="true"><use href="#avs-i-rw10"></use></svg></button>
			<button type="button" class="avs-center-btn avs-center-toggle" title="Play / Pausar"><svg class="avs-icon avs-icon-xl" aria-hidden="true"><use href="#avs-i-play"></use></svg></button>
			<button type="button" class="avs-center-btn avs-center-fw" title="+10 segundos"><svg class="avs-icon" aria-hidden="true"><use href="#avs-i-fw10"></use></svg></button>
		</div>
		<div class="avs-controls">
			<div class="avs-seek" role="slider" aria-label="Seek" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" title="Seek">
				<div class="avs-seek-track"></div>
				<div class="avs-seek-buffer"></div>
				<div class="avs-seek-fill"><span class="avs-seek-handle"></span></div>
			</div>
			<div class="avs-controls-row">
				<div class="avs-controls-left">
					<button type="button" class="avs-btn" data-action="play" title="Play / Pausar"><svg class="avs-icon" aria-hidden="true"><use href="#avs-i-play"></use></svg></button>
					<div class="avs-volume-wrap">
						<button type="button" class="avs-btn" data-action="volume" title="Mudo"><svg class="avs-icon" aria-hidden="true"><use href="#avs-i-vol-high"></use></svg></button>
						<input type="range" class="avs-volume-slider" min="0" max="100" step="1" value="80" title="Volume" aria-label="Volume">
					</div>
					<span class="avs-time avs-current">00:00</span>
					<span class="avs-time avs-sep">/</span>
					<span class="avs-time avs-duration">00:00</span>
				</div>
				<div class="avs-controls-right">
					<button type="button" class="avs-btn avs-settings-btn" data-action="settings" title="Configurações"><svg class="avs-icon" aria-hidden="true"><use href="#avs-i-settings"></use></svg></button>
					<button type="button" class="avs-btn avs-btn-active" data-action="mini" title="Mini player"><svg class="avs-icon" aria-hidden="true"><use href="#avs-i-pip"></use></svg></button>
					<button type="button" class="avs-btn" data-action="fullscreen" title="Tela cheia"><svg class="avs-icon" aria-hidden="true"><use href="#avs-i-fs-enter"></use></svg></button>
				</div>
			</div>
		</div>
		<div class="avs-settings" role="menu" aria-label="Configurações">
			<div class="avs-settings-head">
				<div class="avs-settings-title">
					<span class="material-symbols-rounded" aria-hidden="true">settings</span>
					<span>Configurações</span>
				</div>
				<div class="avs-settings-tabs" role="tablist" aria-label="Ajustes">
					<button type="button" class="avs-settings-tab avs-settings-tab-active" data-settings-tab="quality" role="tab" aria-selected="true"><span class="material-symbols-rounded" aria-hidden="true">hd</span>Qualidade</button>
					<button type="button" class="avs-settings-tab" data-settings-tab="speed" role="tab" aria-selected="false"><span class="material-symbols-rounded" aria-hidden="true">speed</span>Velocidade</button>
					<button type="button" class="avs-settings-tab" data-settings-tab="playback" role="tab" aria-selected="false"><span class="material-symbols-rounded" aria-hidden="true">play_circle</span>Reprodução</button>
				</div>
			</div>
			<div class="avs-settings-pane avs-settings-pane-active" data-settings-group="quality" role="tabpanel" aria-label="Qualidade"></div>
			<div class="avs-settings-pane" data-settings-group="speed" role="tabpanel" aria-label="Velocidade"></div>
			<div class="avs-settings-pane" data-settings-group="playback" role="tabpanel" aria-label="Reprodução"></div>
		</div>
		<div class="avs-mini-actions">
			<button type="button" class="avs-mini-expand" title="Voltar ao player grande"><svg class="avs-icon" aria-hidden="true"><use href="#avs-i-fs-exit"></use></svg></button>
			<button type="button" class="avs-mini-close" title="Fechar"><svg class="avs-icon" aria-hidden="true"><use href="#avs-i-close"></use></svg></button>
		</div>
		<div class="avs-error" style="display:none;"></div>
	</div>
	{/if}
	<link rel="stylesheet" href="{$baseurl}/media/player/mediabunny/avs-mini.css?ver=2.1.0">
	<script type="text/javascript" src="{$baseurl}/media/player/mediabunny/avs-mini.js?ver=2.1.0"></script>
	{include file='../../../templates/backend/default/analytics/analytics.tpl'}
</body>
</html>