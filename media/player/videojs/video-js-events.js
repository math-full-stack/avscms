var player = videojs('video');

player.videoJsResolutionSwitcher({
	default: player_resolution
});

if (typeof mysrc !== 'undefined' && mysrc.length > 0) {
    player.updateSrc(mysrc);
} 



if (player_logo == '1') {
	if (player_logo_redirect != '1') {
		player_logo_link = "#";
	}
	player.logobrand({
		image: player_logo_image, //image to use
		destination: player_logo_link, //destination when clicked
		position: player_logo_position,
		opacity: player_logo_opacity
	});	
}

if (player_pause_adv == '1' && aid != false) {
	var ad_div = document.createElement('div');
	ad_div.innerHTML = '<iframe class="ad-iframe" src="' + base_url + '/ads.php?id='  + aid +  '" marginwidth="0" marginheight="0" hspace="0" vspace="0" frameborder="0" scrolling="no" onload="resizeIframe(this)" style="display: hidden; width: 0; height: 0;"></iframe>';
	var ad_ifrm = ad_div.firstChild;
     
	$("body").on('click', "button[id*='ad-resume']", function(event) {  
		event.preventDefault();
		if ( $( ".ad-controls" ).length ) {	
			$(".ad-controls").remove();
			player.el().removeChild(ad_ifrm);
			player.play();
		}
		
	});

	$("body").on('click', "button[id*='ad-close']", function(event) {  
		event.preventDefault();
		if ( $( ".ad-controls" ).length ) {		
			$(".ad-controls").remove();	
			player.el().removeChild(ad_ifrm);
		}
	});
	
	function resizeIframe(obj) {
		var w = obj.contentWindow.document.body.scrollWidth;
		var h =  obj.contentWindow.document.body.scrollHeight;
		var ml = -(w / 2);
		var mt = -(h / 2) - 14;	
		var ml_controls = -91;	
		var mt_controls = (h / 2) - 12;
		obj.style.width = w + 'px';
		obj.style.height = h + 'px';		
		obj.style.marginLeft = ml + 'px';
		obj.style.marginTop = mt + 'px';
		obj.style.display = 'block';	
		$('<div id="ad-controls" class="ad-controls" style="margin-left: ' + ml_controls + 'px; margin-top: ' + mt_controls + 'px;"><button id="ad-resume" class="ad-resume" title="Resume">&#9654;&nbsp;&nbsp;RESUME</button> <button id="ad-close" class="ad-close" title="Close Ad">&#10005;&nbsp;&nbsp;CLOSE</button></div>').insertBefore(obj);
	}
}

// Create the quick controls card (bottom-right) only when enabled
var quickControlsEl = null;
if (player_quick_controls == '1') {
	var qcContainer = player.el();
	quickControlsEl = document.createElement('div');
	quickControlsEl.className = 'vjs-quick-controls';
	quickControlsEl.innerHTML =
		'<button type="button" class="qc-btn qc-play" title="Play / Pause">&#9654;</button>' +
		'<button type="button" class="qc-btn qc-speed" title="Playback speed">1x</button>' +
		'<button type="button" class="qc-btn qc-seek qc-back" title="-5 seconds">-5</button>' +
		'<button type="button" class="qc-btn qc-seek qc-forward" title="+5 seconds">+5</button>';
	qcContainer.appendChild(quickControlsEl);
}

player.ready(function(){

	// Start muted (configurable in admin playeredit)
	if (player_start_muted == '1') {
		player.muted(true);
	}

	// Quick controls card: play / speed / +5s in the bottom-right corner
	if (player_quick_controls == '1' && quickControlsEl != null) {
		var qcSpeeds = [1, 1.25, 1.5, 2, 0.75, 0.5];
		var qcSpeedIndex = 0;
		var qcPlay = quickControlsEl.querySelector('.qc-play');
		var qcSpeed = quickControlsEl.querySelector('.qc-speed');
		var qcBack = quickControlsEl.querySelector('.qc-back');
		var qcFwd = quickControlsEl.querySelector('.qc-forward');

		qcPlay.addEventListener('click', function(e) {
			e.preventDefault();
			e.stopPropagation();
			if (player.paused()) { player.play(); } else { player.pause(); }
		});

		qcSpeed.addEventListener('click', function(e) {
			e.preventDefault();
			e.stopPropagation();
			qcSpeedIndex = (qcSpeedIndex + 1) % qcSpeeds.length;
			var rate = qcSpeeds[qcSpeedIndex];
			player.playbackRate(rate);
			qcSpeed.textContent = rate + 'x';
		});

		qcBack.addEventListener('click', function(e) {
			e.preventDefault();
			e.stopPropagation();
			player.currentTime(Math.max(0, player.currentTime() - 5));
		});

		qcFwd.addEventListener('click', function(e) {
			e.preventDefault();
			e.stopPropagation();
			var d = (player.duration && player.duration()) ? player.duration() : player.currentTime();
			player.currentTime(Math.min(d, player.currentTime() + 5));
		});

		player.on('play', function() { qcPlay.innerHTML = '&#10074;&#10074;'; });
		player.on('pause', function() { qcPlay.innerHTML = '&#9654;'; });
	}

	if (player_pause_adv == '1' && aid != false) {
		this.on("pause", function(){
			if (!this.seeking() && this.paused() && this.currentTime()> 1) {
				this.el().appendChild(ad_ifrm);
				display_ads = false;
			}
		});
		
		this.on("play", function(){
			if ( $( ".ad-controls" ).length ) {
				$(".ad-controls").remove();
				this.el().removeChild(ad_ifrm);
			}
		});
	}

	if (player_timeline_preview == '1') {
		var step = (video_duration / 20);
		var resize = 0.6;
		var thumb_w = Math.floor(256*resize);
		var thumb_h =  Math.floor(144*resize);
		this.thumbnails({
			0: {
				src: player_sprite,
				style: {
				  left: '-'+(Math.floor(thumb_w/2))+'px',
				  width: ''+(thumb_w*20)+'px',
				  height: ''+thumb_h+'px',
				  clip: 'rect(0, '+thumb_w+'px, '+thumb_h+'px, 0)'
				}
			},
			[Math.round(1*step)]: {
				style: {
				  left: '-'+(Math.floor(thumb_w/2) + (1*thumb_w))+'px',
				  clip: 'rect(0, '+((1+1)*thumb_w)+'px, '+thumb_h+'px, '+(1*thumb_w)+'px)'
				}
			},
			[Math.round(2*step)]: {
				style: {
				  left: '-'+(Math.floor(thumb_w/2) + (2*thumb_w))+'px',
				  clip: 'rect(0, '+((2+1)*thumb_w)+'px, '+thumb_h+'px, '+(2*thumb_w)+'px)'
				}
			},		
			[Math.round(3*step)]: {
				style: {
				  left: '-'+(Math.floor(thumb_w/2) + (3*thumb_w))+'px',
				  clip: 'rect(0, '+((3+1)*thumb_w)+'px, '+thumb_h+'px, '+(3*thumb_w)+'px)'
				}
			},		
			[Math.round(4*step)]: {
				style: {
				  left: '-'+(Math.floor(thumb_w/2) + (4*thumb_w))+'px',
				  clip: 'rect(0, '+((4+1)*thumb_w)+'px, '+thumb_h+'px, '+(4*thumb_w)+'px)'
				}
			},		
			[Math.round(5*step)]: {
				style: {
				  left: '-'+(Math.floor(thumb_w/2) + (5*thumb_w))+'px',
				  clip: 'rect(0, '+((5+1)*thumb_w)+'px, '+thumb_h+'px, '+(5*thumb_w)+'px)'
				}
			},		
			[Math.round(6*step)]: {
				style: {
				  left: '-'+(Math.floor(thumb_w/2) + (6*thumb_w))+'px',
				  clip: 'rect(0, '+((6+1)*thumb_w)+'px, '+thumb_h+'px, '+(6*thumb_w)+'px)'
				}
			},		
			[Math.round(7*step)]: {
				style: {
				  left: '-'+(Math.floor(thumb_w/2) + (7*thumb_w))+'px',
				  clip: 'rect(0, '+((7+1)*thumb_w)+'px, '+thumb_h+'px, '+(7*thumb_w)+'px)'
				}
			},		
			[Math.round(8*step)]: {
				style: {
				  left: '-'+(Math.floor(thumb_w/2) + (8*thumb_w))+'px',
				  clip: 'rect(0, '+((8+1)*thumb_w)+'px, '+thumb_h+'px, '+(8*thumb_w)+'px)'
				}
			},		
			[Math.round(9*step)]: {
				style: {
				  left: '-'+(Math.floor(thumb_w/2) + (9*thumb_w))+'px',
				  clip: 'rect(0, '+((9+1)*thumb_w)+'px, '+thumb_h+'px, '+(9*thumb_w)+'px)'
				}
			},		
			[Math.round(10*step)]: {
				style: {
				  left: '-'+(Math.floor(thumb_w/2) + (10*thumb_w))+'px',
				  clip: 'rect(0, '+((10+1)*thumb_w)+'px, '+thumb_h+'px, '+(10*thumb_w)+'px)'
				}
			},
			[Math.round(11*step)]: {
				style: {
				  left: '-'+(Math.floor(thumb_w/2) + (11*thumb_w))+'px',
				  clip: 'rect(0, '+((11+1)*thumb_w)+'px, '+thumb_h+'px, '+(11*thumb_w)+'px)'
				}
			},		
			[Math.round(12*step)]: {
				style: {
				  left: '-'+(Math.floor(thumb_w/2) + (12*thumb_w))+'px',
				  clip: 'rect(0, '+((12+1)*thumb_w)+'px, '+thumb_h+'px, '+(12*thumb_w)+'px)'
				}
			},		
			[Math.round(13*step)]: {
				style: {
				  left: '-'+(Math.floor(thumb_w/2) + (13*thumb_w))+'px',
				  clip: 'rect(0, '+((13+1)*thumb_w)+'px, '+thumb_h+'px, '+(13*thumb_w)+'px)'
				}
			},		
			[Math.round(14*step)]: {
				style: {
				  left: '-'+(Math.floor(thumb_w/2) + (14*thumb_w))+'px',
				  clip: 'rect(0, '+((14+1)*thumb_w)+'px, '+thumb_h+'px, '+(14*thumb_w)+'px)'
				}
			},		
			[Math.round(15*step)]: {
				style: {
				  left: '-'+(Math.floor(thumb_w/2) + (15*thumb_w))+'px',
				  clip: 'rect(0, '+((15+1)*thumb_w)+'px, '+thumb_h+'px, '+(15*thumb_w)+'px)'
				}
			},		
			[Math.round(6*step)]: {
				style: {
				  left: '-'+(Math.floor(thumb_w/2) + (16*thumb_w))+'px',
				  clip: 'rect(0, '+((16+1)*thumb_w)+'px, '+thumb_h+'px, '+(16*thumb_w)+'px)'
				}
			},		
			[Math.round(17*step)]: {
				style: {
				  left: '-'+(Math.floor(thumb_w/2) + (17*thumb_w))+'px',
				  clip: 'rect(0, '+((17+1)*thumb_w)+'px, '+thumb_h+'px, '+(17*thumb_w)+'px)'
				}
			},		
			[Math.round(18*step)]: {
				style: {
				  left: '-'+(Math.floor(thumb_w/2) + (18*thumb_w))+'px',
				  clip: 'rect(0, '+((18+1)*thumb_w)+'px, '+thumb_h+'px, '+(18*thumb_w)+'px)'
				}
			},		
			[Math.round(19*step)]: {
				style: {
				  left: '-'+(Math.floor(thumb_w/2) + (19*thumb_w))+'px',
				  clip: 'rect(0, '+((19+1)*thumb_w)+'px, '+thumb_h+'px, '+(19*thumb_w)+'px)'
				}
			},		
			[Math.round(20*step)]: {
				style: {
				  left: '-'+(Math.floor(thumb_w/2) + (20*thumb_w))+'px',
				  clip: 'rect(0, '+((20+1)*thumb_w)+'px, '+thumb_h+'px, '+(20*thumb_w)+'px)'
				}
			}		
		});		
	}
	
   $('.video-container').mousedown(function(event) {
      if(event.which === 3) {
         $('.video-container').bind('contextmenu',function () { return false; });
       }
       else {
         $('.video-container').unbind('contextmenu');
       }
   });	

   // Auto-play next video
   var watchedKey = 'watched_videos';
   var maxWatched = 50;

   // Mark current video as watched
   var watched = [];
   try { watched = JSON.parse(localStorage.getItem(watchedKey)) || []; } catch(e) {}
   if (watched.indexOf(video_id) === -1) {
      watched.push(video_id);
      if (watched.length > maxWatched) watched.shift();
      localStorage.setItem(watchedKey, JSON.stringify(watched));
   }

   // Find next unwatched video
   function getNextVideo() {
      if (!related_videos_data || related_videos_data.length === 0) return null;
      for (var i = 0; i < related_videos_data.length; i++) {
         if (watched.indexOf(String(related_videos_data[i].vid)) === -1) {
            return related_videos_data[i];
         }
      }
      // All watched, clear and return first
      localStorage.removeItem(watchedKey);
      watched = [];
      return related_videos_data[0];
   }

   // Update card with next video
   var nextVideo = getNextVideo();
   if (nextVideo) {
      var card = document.getElementById('autoplay-card');
      if (card) {
         var cardLink = card.querySelector('.autoplay-card-body');
         var cardImg = card.querySelector('.autoplay-card-thumb img');
         var cardDur = card.querySelector('.autoplay-card-duration');
         var cardTitle = card.querySelector('.autoplay-card-title');
         var cardViews = card.querySelector('.autoplay-card-views');
         var cardRate = card.querySelector('.autoplay-card-rate');
         var cardRateWrap = card.querySelector('.autoplay-card-rate-wrap');

         cardLink.href = base_url + '/video/' + nextVideo.vid + '/' + nextVideo.slug;
         cardImg.src = nextVideo.thumb;
         cardImg.alt = nextVideo.title;
         cardDur.textContent = nextVideo.duration;
         cardTitle.textContent = nextVideo.title;
         cardViews.textContent = nextVideo.views;
         if (nextVideo.rate != 0) {
            cardRate.textContent = nextVideo.rate + '%';
            cardRateWrap.style.display = '';
         } else {
            cardRateWrap.style.display = 'none';
         }
         card.style.display = '';
      }
   }

   this.on("ended", function() {
      if (!nextVideo) return;
      if (localStorage.getItem('autoplayNext') === 'false') return;

      var overlay = document.getElementById('autoplay-overlay');
      if (!overlay) {
         overlay = document.createElement('div');
         overlay.id = 'autoplay-overlay';
         overlay.innerHTML =
            '<div class="autoplay-overlay-content">' +
               '<div class="autoplay-next-count" id="autoplay-count">3</div>' +
               '<div class="autoplay-next-thumb">' +
                  '<img id="autoplay-overlay-thumb" src="" alt="">' +
                  '<div class="autoplay-next-duration" id="autoplay-overlay-dur"></div>' +
               '</div>' +
               '<div class="autoplay-next-title" id="autoplay-overlay-title"></div>' +
               '<div class="autoplay-overlay-buttons">' +
                  '<button id="autoplay-cancel" class="autoplay-btn-cancel">CANCELAR</button>' +
                  '<button id="autoplay-skip" class="autoplay-btn-skip">PRÓXIMO <i class="fas fa-forward"></i></button>' +
               '</div>' +
            '</div>';
         player.el().appendChild(overlay);
      }

      document.getElementById('autoplay-overlay-thumb').src = nextVideo.thumb;
      document.getElementById('autoplay-overlay-thumb').alt = nextVideo.title;
      document.getElementById('autoplay-overlay-dur').textContent = nextVideo.duration;
      document.getElementById('autoplay-overlay-title').textContent = nextVideo.title;
      overlay.style.display = 'flex';

      var count = 3;
      var countEl = document.getElementById('autoplay-count');
      countEl.textContent = count;

      var nextUrl = base_url + '/video/' + nextVideo.vid + '/' + nextVideo.slug + '?autoplay=1';

      var timer = setInterval(function() {
         count--;
         if (count <= 0) {
            clearInterval(timer);
            window.location.href = nextUrl;
         } else {
            countEl.textContent = count;
         }
      }, 1000);

      document.getElementById('autoplay-cancel').onclick = function(e) {
         e.preventDefault();
         e.stopPropagation();
         clearInterval(timer);
         overlay.style.display = 'none';
      };

      document.getElementById('autoplay-skip').onclick = function(e) {
         e.preventDefault();
         e.stopPropagation();
         clearInterval(timer);
         window.location.href = nextUrl;
      };
   });

   this.on("play", function() {
      var overlay = document.getElementById('autoplay-overlay');
      if (overlay && overlay.style.display === 'flex') {
         overlay.style.display = 'none';
      }
   });

});	