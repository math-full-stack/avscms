var timers  = new Array;
var images  = new Array;
function changeThumb( id, url )
{
        document.getElementById(id).src = url;
}
function thumb_path( vid ) {
	// Thumbs em CDN (GCS): thumb_cdn_base = https://.../thumbs; os callers
	// montam thumb_cdn_base + '/' + vid + '/' + arquivo.
	if ( typeof thumb_cdn_base !== 'undefined' && thumb_cdn_base !== '' ) {
		return thumb_cdn_base;
	}
	var index = parseInt( (vid - 1) / max_thumb_folders );
	var tmb_folder = 'tmb';
	if ( index !== 0 ) {
		tmb_folder = 'tmb'+ index;
	}
	var path = base_url + '/media/videos/' + tmb_folder;
    return path;
}
function xbOrientThumb( $ovl ) {
	// Fonte da verdade é o banco (video.orientation -> classe xb-portrait
	// renderizada no template). Thumbs são crops 16:9, então re-detectar
	// por naturalWidth/Height apagaria o portrait correto. Preserva.
	if ( $ovl.hasClass('xb-portrait') ) {
		if ( $ovl.find('.xb-trio').length ) {
			return;
		}
		var $cur = $ovl.find('img:first');
		if ( $cur.length && !$ovl.find('> .xb-portrait-bg').length && $cur[0].src ) {
			$ovl.prepend('<span class="xb-portrait-bg" style="background-image:url(\'' + $cur[0].src + '\')"></span>');
		}
		return;
	}
	var $img  = $ovl.find('img:first');
	if ( !$img.length || typeof $img[0].naturalWidth === 'undefined' ) {
		return;
	}
	if ( $img[0].complete && $img[0].naturalWidth === 0 ) {
		return;
	}
	$ovl.removeClass('xb-landscape xb-portrait');
	$ovl.find('> .xb-portrait-bg').remove();
	if ( $img[0].naturalHeight > $img[0].naturalWidth ) {
		$ovl.addClass('xb-portrait');
		$ovl.css('background-image', 'url("' + $img[0].src + '")');
		// Fundo desfocado full-bleed (padrão Pornolandia): a capa nítida fica
		// em contain por cima (CSS) e este span desfocado preenche o box 16:9.
		$ovl.prepend('<span class="xb-portrait-bg" style="background-image:url(\'' + $img[0].src + '\')"></span>');
	} else {
		$ovl.addClass('xb-landscape');
		$ovl.css('background-image', '');
	}
}
function xbOrientThumbs() {
	$('.thumb-overlay').each(function() {
		var $ovl = $(this);
		if ( $ovl.data('xb-oriented') ) {
			return;
		}
		$ovl.data('xb-oriented', true);
		var $img = $ovl.find('img:first');
		if ( !$img.length ) {
			return;
		}
		if ( $img[0].complete ) {
			xbOrientThumb( $ovl );
		} else {
			$img.on('load', function() {
				xbOrientThumb( $ovl );
			});
		}
	});
}
function xbAdvanceTrio($t) {
	if (!$t || !$t.length) return;
	var now = Date.now ? Date.now() : (new Date()).getTime();
	var last = $t.data('xb-last-advance') || 0;
	if (now - last < 400) return;
	$t.data('xb-last-advance', now);

	var video_id = parseInt($t.attr('data-vid'), 10);
	if (!video_id) return;
	var covers = String($t.attr('data-covers') || '').split(',').map(function(x) {
		return parseInt(x, 10);
	}).filter(function(x) {
		return x > 0;
	});
	if (covers.length < 2) return;
	var n = covers.length;
	var idx = (parseInt($t.attr('data-idx'), 10) || 0) % n;
	var ni = (idx + 1) % n;
	var base = thumb_path(video_id) + '/' + video_id + '/';
	var $imgs = $t.find('img');
	for (var s = 0; s < 3 && s < $imgs.length; s++) {
		var f = covers[(ni + s) % n];
		var url = base + f + '.jpg';
		var pre = new Image();
		pre.src = url;
		$($imgs[s]).attr('src', url);
	}
	$t.attr('data-idx', ni);
}

$(document).ready(function() {
	
	xbOrientThumbs();
	
	$("body").on('mouseenter', "[id*='playvthumb_']", function(event) {
		xbOrientThumb( $(this) );
		var $trio = $(this).find('.xb-trio');
		if ($trio.length) {
			xbAdvanceTrio($trio);
		}
		var img = $(this).find('img:first');
		if (!img.hasClass("img-private")) {			
			var image_id    = $(this).attr("id");
			var id_split    = image_id.split('_');
			var video_id    = id_split[1];
			var video 		= $('<video style="width:100%; height:100%; position:absolute; top:0; left:0;" class="img-fluid" muted autoplay loop>');
			var content 	= '<source type="video/webm" src="'+thumb_path(video_id) + '/' + video_id + '/video.webm"></source>';
				content		= content + '<source type="video/mp4" src="'+thumb_path(video_id) + '/' + video_id + '/video.mp4"></source>';
				$(video).append(content);
				$(video).hide();
				var vloader = $('<span class="vloader">');
				var target = $trio.length ? $trio : $(this).find('img:first');
				$(target).after($(video));$(video).after($(vloader));
				$( ".vloader" ).animate({ width: '100%',}, 2000, function() {$( ".vloader" ).fadeOut();});
				$("#thumbPlayer").css('visibility','visible');

				var vid = $(video)[0];
				vid.load();
				vid.oncanplay = function(){
					vid.play();
				};
				$(vid).on('play', function() {
					$(video).fadeIn(); 
				});
		}
	});
	$("body").on('mouseleave', "[id*='playvthumb_']", function(event) {
		var target = $(this).find('video');
		var img = $(this).find('img:first');
		$(target).remove();$(this).find('.vloader').remove(); $(img).show(); 
	});
	
    $("body").on('mouseover', "img[id*='rotate_']", function(event) {
        var image_id    = $(this).attr("id");
        var id_split    = image_id.split('_');
        var video_id    = id_split[1];
		var thumbs		= id_split[2];
		if (typeof thumbs == "undefined") {
			thumbs = 20;
		}
		
        for ( var i=1; i<=thumbs; i++ ) {
            var image_url = thumb_path(video_id) + '/' + video_id + '/' + i + '.jpg';
            images[i]     = new Image();
            images[i].src = image_url;
        }
        for ( var i=1; i<=thumbs; i++ ) {
            timers[i] = setTimeout("changeThumb('" + image_id + "','" + thumb_path(video_id) + '/' + video_id + '/' + i + '.jpg' + "')", i*50*10);
        }
    }).on('mouseout', "img[id*='rotate_']", function(event) {
        var image_id    = $(this).attr("id");
        var id_split    = image_id.split('_');
        var video_id    = id_split[1];
		var thumbs		= id_split[2];
		var def_thumb = id_split[3];		
		if (typeof thumbs == "undefined") {
			thumbs = 20;
		}

        for ( var i=1; i<=thumbs; i++ ) {
            if ( typeof timers[i] == "number" ) {
                clearTimeout(timers[i]);
            }
        }
		if ( $.isNumeric(def_thumb) )
			$(this).attr('src', thumb_path(video_id) + '/' + video_id + '/' + def_thumb + '.jpg');
		else
			$(this).attr('src', thumb_path(video_id) + '/' + video_id + '/1.jpg');
    });

	// Trio vertical: passar o mouse avança as 3 capas (janela deslizante
	// sobre data-covers). Sem volta no mouseleave — avança e fica.
	$("body").on('mouseenter', '.xb-trio', function(event) {
		var $t = $(this);
		var video_id = parseInt($t.attr('data-vid'), 10);
		if (!video_id) {
			return;
		}
		var covers = String($t.attr('data-covers') || '').split(',').map(function(x) {
			return parseInt(x, 10);
		}).filter(function(x) {
			return x > 0;
		});
		if (covers.length < 2) {
			return;
		}
		var n = covers.length;
		var idx = (parseInt($t.attr('data-idx'), 10) || 0) % n;
		var ni = (idx + 1) % n;
		var base = thumb_path(video_id) + '/' + video_id + '/';
		var $imgs = $t.find('img');
		for (var s = 0; s < 3 && s < $imgs.length; s++) {
			var f = covers[(ni + s) % n];
			var url = base + f + '.jpg';
			var pre = new Image();
			pre.src = url;
			$($imgs[s]).attr('src', url);
		}
		$t.attr('data-idx', ni);
	});
});

$(window).on('load', function() {
	xbOrientThumbs();
});
