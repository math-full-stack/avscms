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
				theme: "novinhasbr"
			};
			$("#search_query").easyAutocomplete(options);
			$("#search_query_xs").easyAutocomplete(options);

			// Mobile dropdown toggle
			$('.xb-nav-dropdown-toggle').on('click', function(e) {
				if ($(window).width() < 992) {
					e.preventDefault();
					e.stopPropagation();
					var $dropdown = $(this).closest('.xb-nav-dropdown');
					$dropdown.toggleClass('open');
					$('.xb-nav-dropdown').not($dropdown).removeClass('open');
				}
			});

			// Close dropdown when clicking outside
			$(document).on('click', function(e) {
				if (!$(e.target).closest('.xb-nav-dropdown').length) {
					$('.xb-nav-dropdown').removeClass('open');
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

			// Header scroll effect
			var $topbar = $('.xb-topbar');
			var lastScroll = 0;
			$(window).on('scroll', function() {
				var currentScroll = $(this).scrollTop();
				if (currentScroll > 50) {
					$topbar.addClass('xb-topbar-scrolled');
				} else {
					$topbar.removeClass('xb-topbar-scrolled');
				}
				lastScroll = currentScroll;
			});

			// Keyboard navigation for nav dropdowns
			$('.xb-nav-dropdown-toggle').on('keydown', function(e) {
				var $dropdown = $(this).closest('.xb-nav-dropdown');
				var $menu = $dropdown.find('.xb-dropdown-menu');
				
				switch(e.key) {
					case 'Enter':
					case ' ':
						e.preventDefault();
						if ($(window).width() >= 992) {
							$dropdown.toggleClass('show');
						} else {
							$dropdown.toggleClass('open');
						}
						break;
					case 'Escape':
						$dropdown.removeClass('show open');
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
						$(this).closest('.xb-nav-dropdown').removeClass('show open');
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
					$('.xb-nav-dropdown').removeClass('show open');
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
	{if $view && !$video.embed_code && $player.engine != 'mediabunny'}
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
	{include file='../../../templates/backend/default/analytics/analytics.tpl'}	
</body>
</html>