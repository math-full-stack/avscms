<style>
#autoplay-overlay {
   display: none;
   position: absolute;
   top: 0; left: 0; right: 0; bottom: 0;
   background: rgba(0,0,0,0.88);
   z-index: 10;
   justify-content: center;
   align-items: center;
   flex-direction: column;
}
.autoplay-overlay-content {
   text-align: center;
   color: #fff;
   max-width: 380px;
   width: 90%;
}
.autoplay-next-count {
   font-size: 56px;
   font-weight: 700;
   color: #fff;
   line-height: 1;
   margin-bottom: 14px;
}
.autoplay-next-thumb {
   position: relative;
   width: 260px;
   height: 146px;
   margin: 0 auto 10px;
   border-radius: 8px;
   overflow: hidden;
}
.autoplay-next-thumb img {
   width: 100%;
   height: 100%;
   object-fit: cover;
}
.autoplay-next-duration {
   position: absolute;
   bottom: 5px;
   right: 5px;
   background: rgba(0,0,0,0.85);
   color: #fff;
   font-size: 11px;
   padding: 2px 6px;
   border-radius: 3px;
   font-weight: 500;
}
.autoplay-next-title {
   font-size: 13px;
   color: #ccc;
   margin-bottom: 14px;
   max-height: 36px;
   overflow: hidden;
   line-height: 1.3;
}
.autoplay-overlay-buttons {
   display: flex;
   justify-content: center;
   gap: 10px;
}
.autoplay-btn-cancel {
   background: transparent;
   color: #fff;
   border: 1px solid #555;
   padding: 8px 20px;
   border-radius: 20px;
   cursor: pointer;
   font-size: 12px;
   font-weight: 600;
   transition: background 0.2s;
}
.autoplay-btn-cancel:hover {
   background: rgba(255,255,255,0.1);
}
.autoplay-btn-skip {
   background: #ff1493;
   color: #fff;
   border: none;
   padding: 8px 20px;
   border-radius: 20px;
   cursor: pointer;
   font-size: 12px;
   font-weight: 600;
   transition: background 0.2s;
}
.autoplay-btn-skip:hover {
   background: #ff2ea2;
}

.autoplay-switch-wrap {
   display: flex;
   align-items: center;
   justify-content: flex-end;
   margin-top: 8px;
   gap: 10px;
   user-select: none;
}
.autoplay-switch-wrap .autoplay-icon {
   color: #888;
   font-size: 14px;
   transition: color 0.3s;
}
.autoplay-switch-wrap.active .autoplay-icon {
   color: #fff;
}
.autoplay-switch-wrap .autoplay-label {
   font-size: 13px;
   color: #888;
   transition: color 0.3s;
}
.autoplay-switch-wrap.active .autoplay-label {
   color: #ccc;
}
.autoplay-switch {
   position: relative;
   display: inline-block;
   width: 40px;
   height: 22px;
   flex-shrink: 0;
}
.autoplay-switch input {
   opacity: 0;
   width: 0;
   height: 0;
}
.autoplay-slider {
   position: absolute;
   cursor: pointer;
   top: 0; left: 0; right: 0; bottom: 0;
   background: #444;
   border-radius: 22px;
   transition: background 0.3s;
}
.autoplay-slider:before {
   content: "";
   position: absolute;
   height: 16px;
   width: 16px;
   left: 3px;
   bottom: 3px;
   background: #999;
   border-radius: 50%;
   transition: transform 0.3s, background 0.3s;
}
.autoplay-switch input:checked + .autoplay-slider {
   background: #ff1493;
}
.autoplay-switch input:checked + .autoplay-slider:before {
   transform: translateX(18px);
   background: #fff;
}
.autoplay-switch input:focus + .autoplay-slider {
   box-shadow: 0 0 2px rgba(229,9,20,0.5);
}

.autoplay-card {
   background: #151515;
   border-radius: 8px;
   overflow: hidden;
   margin-bottom: 14px;
   border: 1px solid #262626;
}
.autoplay-card-header {
   display: flex;
   align-items: center;
   justify-content: space-between;
   padding: 10px 14px;
   background: #0D0D0D;
   border-bottom: 1px solid #262626;
}
.autoplay-card-header-left {
   display: flex;
   align-items: center;
   gap: 8px;
   color: #aaa;
   font-size: 13px;
   font-weight: 500;
}
.autoplay-card-header-left i {
   color: #ff1493;
   font-size: 12px;
}
.autoplay-card-body {
   display: flex;
   padding: 12px 14px;
   gap: 12px;
   text-decoration: none;
   color: inherit;
   transition: background 0.2s;
}
.autoplay-card-body:hover {
   background: rgba(255,255,255,0.03);
   text-decoration: none;
   color: inherit;
}
.autoplay-card-thumb {
   position: relative;
   width: 140px;
   min-width: 140px;
   height: 79px;
   border-radius: 6px;
   overflow: hidden;
   flex-shrink: 0;
}
.autoplay-card-thumb img {
   width: 100%;
   height: 100%;
   object-fit: cover;
}
.autoplay-card-duration {
   position: absolute;
   bottom: 4px;
   right: 4px;
   background: rgba(0,0,0,0.85);
   color: #fff;
   font-size: 11px;
   padding: 2px 5px;
   border-radius: 3px;
   font-weight: 500;
}
.autoplay-card-info {
   display: flex;
   flex-direction: column;
   justify-content: center;
   gap: 6px;
   min-width: 0;
}
.autoplay-card-title {
   font-size: 13px;
   color: #e0e0e0;
   font-weight: 500;
   line-height: 1.3;
   display: -webkit-box;
   -webkit-line-clamp: 2;
   -webkit-box-orient: vertical;
   overflow: hidden;
}
.autoplay-card-meta {
   display: flex;
   gap: 12px;
   font-size: 12px;
   color: #777;
}
.autoplay-card-meta i {
   margin-right: 3px;
}
</style>

<script type="text/javascript">
var lang_favoriting = "{t c='global.favoring'}";
var lang_posting = "{t c='global.posting'}";
var video_width = "{$video_width}";
var video_height = "{$video_height}";
var evideo_vkey = "{$video.vkey}";
var vitem = "{$vitem}";
{literal}
$( document ).ready(function() {

    var evdiv = $('.video-embedded');
	var ewidth = evdiv.width();
	eheight =  Math.round(ewidth / 1.777);
	evdiv.css("height" , eheight);

	$(window).resize(function() {
	var evwidth = $('.video-embedded').width();
	evheight =  Math.round(evwidth / 1.777);
	$('.video-embedded').css("height" , evheight);	
	});

    var autoplayState = localStorage.getItem('autoplayNext') !== 'false';
    var $wrap = $('.autoplay-card-header');
    var $cb = $('#autoplay-toggle-cb');
    $cb.prop('checked', autoplayState);

    $cb.on('change', function() {
        var checked = $(this).prop('checked');
        localStorage.setItem('autoplayNext', checked);
    });
});
{/literal}
</script>

<script type="text/javascript" src="{$relative_tpl}/js/jquery.comments.js"></script>
<script type="text/javascript" src="{$relative_tpl}/js/jquery.voting.js"></script>
<script type="text/javascript" src="{$relative_tpl}/js/jquery.video.js"></script>



<div class="modal fade" id="shareModal" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h4 class="modal-title">{t c='video.SHARE'}</h4>
				<button type="button" class="close" data-dismiss="modal">&times;</button>
			</div>
			<div class="modal-body">
				<div class="form-group mt-3">
					<label for="video_share_url">{t c='video.share_url'}</label>
					<input id="video_share_url" type="text" class="form-control" value="{$baseurl}/video/{$video.VID}/{$video.title}" readonly>
					<button class="btn btn-secondary btn-bold mt-1 btn-xs float-right" onclick="copyToClipboard('video_share_url')"><span id="video_share_url_copied"><i class="fas fa-clone"></i></span> {translate c='global.copy_to_clipboard'}</button>
					<div class="clearfix"></div>
				</div>
				<div class="form-group mt-3">
					<label for="video_embed_code">{t c='video.embed_code'}</label>
					<textarea name="video_embed_code" rows="4" id="video_embed_code" class="form-control" readonly><iframe width="{$embed_width}" height="{$embed_auto_height}" src="{$baseurl}/embed/{$video.vkey}" frameborder="0" allowfullscreen></iframe></textarea>
					<button class="btn btn-secondary btn-bold mt-1 btn-xs float-right" onclick="copyToClipboard('video_embed_code')"><span id="video_embed_code_copied"><i class="fas fa-clone"></i></span> {translate c='global.copy_to_clipboard'}</button>
					<div class="clearfix"></div>					
				</div>
				<div id="custom_size" class="form-group">
					<label for="custom_width">{t c='video.embed_custom_size'}</label>
					<div>
						<div class="float-left">
							<input id="custom_width" type="text" class="form-control" value="" placeholder="{t c='video.width'}" style="width: 100px!important;"/>									
						</div>
						<div class="float-left ml-2 mr-2" style="line-height: 38px;">
							&times;
						</div>
						<div class="float-left mr-2">
							<input id="custom_height" type="text" class="form-control" value="" placeholder="{t c='video.height'}" style="width: 100px!important;"/>
						</div>
						<div class="float-left" style="line-height: 38px;">
							{t c='video.embed_custom_size_min'}
						</div>								
					</div>
				</div>					
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-secondary btn-bold float-left" data-dismiss="modal">{translate c='global.cancel'}</button>
			</div>
		</div>
	</div>
</div>

<div class="modal fade" id="flagModal" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h4 class="modal-title">{t c='video.FLAG'}</h4>
				<button type="button" class="close" data-dismiss="modal">&times;</button>
			</div>
			<div class="modal-body">
				<div class="form-group">
					<label>{t c='video.flag'}</label>
					<div>
						<div class="radio">
							<label>
								<input name="flag_reason" type="radio" value="inappropriate" checked="yes" />
								{t c='flag.inappr'}
							</label>
						</div>
						<div class="radio">
							<label>
								<input name="flag_reason" type="radio" value="underage" />
								{t c='flag.underage'}
							</label>
						</div>
						<div class="radio">
							<label>
								<input name="flag_reason" type="radio" value="copyrighted" />
								{t c='flag.copyright'}
							</label>
						</div>
						<div class="radio">
							<label>
								<input name="flag_reason" type="radio" value="not_playing" />
								{t c='flag.not_playing'}
							</label>
						</div>
						<div class="radio">
							<label>
								<input name="flag_reason" type="radio" value="other" />
								{t c='flag.other'}
							</label>
						</div>
						<div id="flag_reason_error" class="text-danger m-t-5" style="display: none;"></div>
					</div>
				</div>
				<div class="form-group">
					<label for="flag_message">{t c='flag.reason'}</label>
					<div>
						<textarea name="flag_message" class="form-control" rows="3" id="flag_message"></textarea>
					</div>
				</div>				
			</div>
			<div class="modal-footer">
				<button id="submit_flag_video" data-vid="{$video.VID}" type="button" class="btn btn-primary btn-bold">{t c='video.flag'}</button>
				<button type="button" class="btn btn-secondary btn-bold" data-dismiss="modal">{translate c='global.cancel'}</button>
			</div>
		</div>
	</div>
</div>

<div class="container mt-3 mb-3">
	{if $guest_limit}
	<h1>
		<div class="text-danger">{t c='video.limit'}</div>
	</h1>
	{elseif !$is_friend}
	<h1>
		<span class="text-danger">{t c='video.private' r=$relative s=$video.username sn=$video.username}</span>
	</h1>
	{else}
	<div class="well-filters">
		<h1>{$video.title}</h1>
	</div>
	{/if}
	{if $is_friend && !$guest_limit}
	<div class="row">
		<div class="content-left mt-3 mb-3">
		<script>
		{literal}
		let vid_files = '{"vid_files":['+
		{/literal}
		{if $video.iphone == 1}
			{literal}'{"src":"{/literal}{$video.iphone_url}","type":"video/mp4", "label":"SD", "res":"720" {literal}},'+{/literal} 
			{if $video.hd == 1}
				{literal}'{"src":"{/literal}{$video.hd_url}","type":"video/mp4", "label":"HD", "res":"1080" {literal}}},'+{/literal} 
			{/if}
		{else}
			{section name=i loop=$video.files}
				{literal}'{"src":"{/literal}{$video.files[i].url}","type":"video/{$video.files[i].format}", "label":"{$video.files[i].label}", "res":"{$video.files[i].height}" {literal}},{/literal}'+ 
			{/section}
		{/if}
		{literal}
		']}';
		{/literal}
		</script>

			
			{include file='video_vplayer.tpl'}
			{insert name=adv assign=adv group='video_player_bottom'}
			{if $adv.ad}		
			<div class="ad-content mt-3">
				{$adv.ad}
			</div>	
			{elseif $adv.help}		
				<div class="ad-body mt-3">
					<p class="ad-title"><span>{t c='global.sponsors'}</span><span class="ad-group">VIDEO PLAYER BOTTOM</span></p>
					<p class="ad-size">Auto &times; Auto</p>
				</div>			
			{/if}			
			<div class="row mt-3">
				<div class="col-12">
					<div class="vote-box float-left">
						<span class="content-rating">
							<span class="mr-2"><i class="fas fa-thumbs-up"></i> <span id="rating_video_{$video.VID}">{if $video.rate != 0}{$video.rate}%{else}-{/if}</span></span>
							<span class="vote-up mr-1"><i id="vote_up_video_{$video.VID}" class="fas fa-thumbs-up"></i> <span id="likes_video_{$video.VID}">{$video.likes}</span></span>			
							<span class="vote-down"><i id="vote_down_video_{$video.VID}" class="fas fa-thumbs-down"></i> <span id="dislikes_video_{$video.VID}">{$video.dislikes}</span></span>						
						</span>	
					</div>
					<div class="video-actions float-right ml-3">
						{if $downloads == '1' && $video.embed_code == '' && $is_friend}	
						<span>
							<a  id="video_download" href="#" class="btn btn-secondary btn-bold btn-xxs" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
								<i class="fas fa-download"></i>
							</a>					
							<div class="dropdown-menu dropdown-menu-right" aria-labelledby="video_download">

								{if $video.formats}
									{section name=i loop=$video.files}
										<a class="dropdown-item" href="{$baseurl}/download.php?id={$video.VID}&label={$video.files[i].label}">{if $video.files[i].height >= 480}HD - {$video.files[i].label} (MP4){else}SD - {$video.files[i].label} (MP4){/if}</a>
									{/section}								
								{else}
									{if $video.hd == '1'}<a class="dropdown-item" href="{$baseurl}/download_hd.php?id={$video.VID}">HD (MP4)</a>{/if}
									{if $video.iphone == '1'}<a class="dropdown-item" href="{$baseurl}/download_mobile.php?id={$video.VID}">Mobile (MP4)</a>{/if}
								{/if}							

							</div>	
						</span>	
						{/if}
						<a href="#" id="video_share" class="btn btn-secondary btn-bold btn-xxs"><i class="fas fa-share"></i><span class="d-none d-md-inline"> {t c='global.share'}</span></a>
						{if isset($smarty.session.uid)}
						<a href="#" id="video_favorite" data-vid="{$video.VID}" class="btn btn-secondary btn-bold btn-xxs"><i class="fas fa-heart"></i><span class="d-none d-md-inline"> {t c='global.favorite'}</span></a>	
						<a href="#" id="video_flag" data-vid="{$video.VID}" class="btn btn-secondary btn-bold btn-xxs"><i class="fas fa-flag"></i><span class="d-none d-md-inline"> {t c='global.flag'}</span></a>							
						{/if}

					</div>					
				</div>
			</div>
			<div class="row">
				<div class="col-12">
					<div class="card-sub mt-3">
						{insert name=time_range assign=addtime time=$video.addtime}							
						<span class="d-block d-sm-none float-right mb-3"><span class="text-highlighted"><i class="fas fa-eye"></i> {$video.viewnumber}</span> &nbsp; {$addtime}</span>
						<div class="clearfix"></div>
						<div class="float-left">
							<a href="{$relative}/user/{$video.username}"><img class="medium-avatar" src="{$relative}/media/users/{if $video.photo == ''}nopic-{$video.gender}.gif{else}{$video.photo}{/if}" /><span>{$video.username}</span></a>	
							{insert name=tsubscribers assign=t_subscribers subscribers=$video.total_subscribers}
							| <span class="total-subscribers" id="total_subscribers">{$t_subscribers}</span>								
						</div>					
						<div class="float-right mt-2">
							<span class="d-none d-sm-inline"><span class="text-highlighted"><i class="fas fa-eye"></i> {$video.viewnumber}</span> &nbsp; {$addtime}</span>
							{if isset($smarty.session.uid) && $smarty.session.uid != $video.UID}
								{insert name=is_subscribed assign=is_subscribed SUID=$smarty.session.uid UID=$video.UID}
								{if isset($is_subscribed) && $is_subscribed}			
									<a href="#" id="user_subscription" data-uid="{$video.UID}" data-subscribed="1" class="btn btn-secondary btn-bold btn-xs ml-2">{t c='user.subscribed'} <i class="fas fa-check"></i></a>
								{else}
									<a href="#" id="user_subscription" data-uid="{$video.UID}" data-subscribed="0" class="btn btn-secondary btn-bold btn-xs ml-2">{t c='user.subscribe'}</a>		
								{/if}
							{/if}													
						</div>
						<div class="clearfix"></div>						
					</div>
					{if $video.description}
						<div class="mt-3 overflow-hidden">
							{$video.description|nl2br}
						</div>
					{/if}					
					<div class="mt-3 overflow-hidden">
						{assign var='keywords' value=$video.keyword}
						{t c='global.tags'}:
						{section name=i loop=$keywords}
							<a class="tag" href="{$relative}/search/tags/{$keywords[i]}">{$keywords[i]}</a>{if !$smarty.section.i.last},{/if}
						{/section}						
					</div>					
				</div>
			</div>
			{if $video_comments == '1'}
				<script type="text/javascript">
					var lang_comments_confirm_delete 		= "{t c='comments.delete_confirm'}";
					var lang_comments_reply 		 		= "{t c='global.reply'}";				
					var lang_comments_view_more_replies	 	= "{t c='comments.view_more_replies'}";								
					var lang_comments_insert_media   		= "{t c='comments.insert_media'}";		
					var lang_cancel					   		= "{t c='global.cancel'}";						
				</script>		
				<div class="comments-section mt-3">
					<div class="modal fade" id="commentsMediaModal" tabindex="-1" role="dialog" aria-hidden="true">
						<div class="modal-dialog modal-dialog-centered modal-lg" role="document">
							<div class="modal-content">
								<div class="modal-body">
									<nav>
										<div class="nav nav-tabs" role="tablist">
										<a class="nav-item nav-link active" id="nav-cvideos-tab" data-toggle="tab" href="#nav-cvideos" role="tab" aria-controls="nav-cvideos" aria-selected="true">{t c='global.videos'}</a>
										<a class="nav-item nav-link" id="nav-cphotos-tab" data-toggle="tab" href="#nav-cphotos" role="tab" aria-controls="nav-cphotos" aria-selected="false">{t c='global.photos'}</a>
										</div>
									</nav>
									<div class="tab-content">
										<div class="tab-pane fade show active" id="nav-cvideos" role="tabpanel" aria-labelledby="nav-cvideos-tab">
											<input type="text" class="form-control" placeholder="{t c='global.search_videos'}" id="search-cvideos" value="" autocomplete="off">
											<div id="info-cvideos"></div>
											<div class="clearfix"></div>
											<div id="cvideos-container">
											</div>
											<div id="cvideos-loader"><i class="fas fa-circle-notch fa-spin fa-2x"></i></div>
										</div>
										<div class="tab-pane fade" id="nav-cphotos" role="tabpanel" aria-labelledby="nav-cphotos-tab">
											<input type="text" class="form-control" placeholder="{t c='global.search_photos'}" id="search-cphotos" value="" autocomplete="off">
											<div id="info-cphotos"></div>
											<div class="clearfix"></div>
											<div id="cphotos-container">
											</div>
											<div id="cphotos-loader"><i class="fas fa-circle-notch fa-spin fa-2x"></i></div>									
										</div>
									</div>
									<input id="insert_media_target" type="hidden" value="">
								</div>
								<div class="modal-footer">
									<button type="button" class="btn btn-secondary btn-bold" data-dismiss="modal">Close</button>
								</div>
							</div>
						</div>
					</div>		
					{assign var="comment_section" value="video"}
					<div class="well-filters mb-1">
						<div class="float-left mr-3">
							<h1><i class="fas fa-comments"></i> {t c='global.COMMENTS'}</h1>
						</div>
						<div class="float-left">
							<h1>
								<a id="comments_sort" href="#" data-id="{$video.VID}" data-type="{$comment_section}" data-sort="newest" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><i class="fas fa-sort-amount-down"></i></a>
								<div class="dropdown-menu dropdown-menu-left" aria-labelledby="comments_sort">
									<a class="dropdown-item active" data-sort="newest" id="comments_sort_newest" href="#">
										{t c='comments.newest'}
									</a>							
									<a class="dropdown-item" data-sort="top" id="comments_sort_top" href="#">
										{t c='comments.top_comments'}
									</a>
								</div>							
							</h1>
						</div>
						<div class="float-left ml-3">
							<h1>
								<span id="sort_loading"></span>					
							</h1>
						</div>
						<div class="float-right">
							<h1><span id="comments_total">{$comments_total}</span></h1>
						</div>	
						<div class="clearfix"></div>
					</div>
					<div id="comments_input_container">
						<textarea data-id="{$video.VID}" data-type="{$comment_section}" id="comments_input" rows="3"  maxlength="1000" class="form-control" {if !isset($smarty.session.uid)}disabled{/if}></textarea>
						<span id="comments_login_register" class="{if isset($smarty.session.uid)}d-none{/if}">{t c='comments.login_register'}</span>					
					</div>
					{if isset($smarty.session.uid)}
					<div id="comments_btn_container">
						<a id="post_comment" href="#" class="btn btn-secondary btn-sm">{t c='comments.post_comment'}</a>
						<span data-toggle="tooltip" data-placement="top" title="{t c='comments.insert_media'}"><a id="insert_media" href="#" class="btn btn-secondary btn-sm" data-toggle="modal" data-target="#commentsMediaModal"><i class="fas fa-paperclip"></i></a></span>
						<span id="comment_response" class="comment-response"></span>
					</div>
					{/if}
					<div id="comments_list" class="comments-list">
					{if $comments}
						{section name=i loop=$comments}
						{insert name=time_range assign=addtime time=$comments[i].addtime}
						{insert name=comment_output assign=comment comment=$comments[i].message}	
						{insert name=total_replies assign=total_replies cid=$comments[i].CID type=$comment_section}	
						<div class="comment-item" id="comment_{$comments[i].CID}">
							<div class="comment-user">
								<a href="{$relative}/user/{$comments[i].username}">
									<img src="{$relative}/media/users/{if $comments[i].photo != ''}{$comments[i].photo}{else}nopic-{$comments[i].gender}.gif{/if}" alt="{$comments[i].username}">
								</a>						
							</div>    
							<div class="comment-info">
								<div class="comment-body">
									<div class="comment-actions">							
										<a id="comment_actions_{$comment_section}_{$comments[i].CID}" data-uid="{$comments[i].UID}" data-rel="{$comment_section}_{$comments[i].CID}" href="#" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
											<i class="fas fa-ellipsis-h"></i>
										</a>
										<div class="dropdown-menu dropdown-menu-right" aria-labelledby="comment_actions_{$comment_section}_{$comments[i].CID}">
											<a class="dropdown-item {if $smarty.session.uid == $comments[i].UID}d-none{/if}" id="report_comment_{$comment_section}_{$comments[i].CID}" href="#">
												<i class="fas fa-flag"></i> {t c='global.report_spam'}
											</a>							
											<a class="dropdown-item {if $smarty.session.uid != $comments[i].UID}d-none{/if}" id="delete_comment_{$comment_section}_{$comments[i].CID}" href="#">
												<i class="fas fa-trash"></i> {t c='global.delete'}
											</a>
										</div>					
									</div>
									<div class="comment-user-info">
										<a class="comment-username" href="{$relative}/user/{$comments[i].username}">{$comments[i].username}</a>
										<span class="comment-add-time"><i class="far fa-clock"></i>{$addtime}</span>	
									</div>
									<div class="comment-text">
										{$comment|nl2br}
									</div>
									<div class="comment-meta">
										<div class="vote-box">
											<span class="content-rating">
												<span class="vote-up mr-1"><i id="comment_vote_up_{$comment_section}_{$comments[i].CID}" class="fas fa-thumbs-up"></i><span id="comment_rate_{$comment_section}_{$comments[i].CID}">{$comments[i].rate}</span></span>		
												<span class="vote-down"><i id="comment_vote_down_{$comment_section}_{$comments[i].CID}" class="fas fa-thumbs-down"></i></span>						
											</span>	
										</div>
										<div class="comment-reply">
											<a id="comment_reply_{$comment_section}_{$comments[i].CID}" data-id="{$comments[i].CID}" data-type="{$comment_section}" data-reply-username="" class="" href="#"><i class="fas fa-share"></i>{t c='global.reply'}</a>								
										</div>
									</div>								
								</div>
							</div>
							<div class="comment-replies">
								<div class="comment-reply-container d-none" id="reply_container_{$comment_section}_{$comments[i].CID}"></div>
								<div class="comments-list replies-list" id="replies_more_{$comment_section}_{$comments[i].CID}"></div>
								<div class="comments-list replies-list" id="replies_list_{$comment_section}_{$comments[i].CID}"></div>
								{if $total_replies > 0}							
									<div id="replies_show_hide_container_{$comment_section}_{$comments[i].CID}" class="replies-show-hide-container">
										<a id="replies_show_more_{$comment_section}_{$comments[i].CID}" class="replies-show-more" data-page="1" data-type="{$comment_section}" data-id="{$comments[i].CID}" href="#">{if $total_replies == 1}{t c='comments.view_reply'}{else}{t c='comments.view_replies'} <span id="replies_total_{$comment_section}_{$comments[i].CID}">{$total_replies}</span>{/if}<i class="fas fa-chevron-down"></i></a>
										<a id="replies_show_more_{$comment_section}_{$comments[i].CID}_" class="replies-show-more replies-view-more" data-page="1" data-type="{$comment_section}" data-id="{$comments[i].CID}" href="#">{t c='comments.view_more_replies'} <span id="replies_total_{$comment_section}_{$comments[i].CID}_">0</span><i class="fas fa-chevron-down"></i></a>							
										<a id="replies_hide_{$comment_section}_{$comments[i].CID}" class="replies-hide" data-type="{$comment_section}" data-id="{$comments[i].CID}" href="#">{if $total_replies == 1}{t c='comments.hide_reply'}{else}{t c='comments.hide_replies'}{/if}<i class="fas fa-chevron-up"></i></a>
										<span class="reply-response" id="replies_loading_{$comment_section}_{$comments[i].CID}"></span>															
									</div>
								{/if}							
							</div>
						</div>					
						{/section}
					{/if}			
					</div>
					<div id="comments_more" class="comments-list">
					</div>				
					{if $comments_total > 10}
						<a href="#" id="comments_show_more" class="comments-show-more" data-page="2" data-type="{$comment_section}" data-id="{$video.VID}">{t c='global.show_more'}<i class="fas fa-chevron-down"></i></a>
						<a href="#" id="comments_hide" class="comments-hide">{t c='global.hide'}<i class="fas fa-chevron-up"></i></a>
						<span id="comments_loading"></span>
					{/if}
				</div>
			{/if}
			
		</div>
		<div class="content-right mt-3 mb-3">

			{if $videos && isset($videos[0])}
			<div class="autoplay-card" id="autoplay-card" style="display:none;">
				<div class="autoplay-card-header">
					<div class="autoplay-card-header-left">
						<i class="fas fa-forward"></i>
						<span>Próximo vídeo</span>
					</div>
					<label class="autoplay-switch">
						<input type="checkbox" id="autoplay-toggle-cb">
						<span class="autoplay-slider"></span>
					</label>
				</div>
				<a href="#" class="autoplay-card-body">
					<div class="autoplay-card-thumb">
						<img src="" alt="">
						<div class="autoplay-card-duration"></div>
					</div>
					<div class="autoplay-card-info">
						<div class="autoplay-card-title"></div>
						<div class="autoplay-card-meta">
							<span class="autoplay-card-views-wrap"><i class="fas fa-eye"></i> <span class="autoplay-card-views"></span></span>
							<span class="autoplay-card-rate-wrap"><i class="fas fa-thumbs-up"></i> <span class="autoplay-card-rate"></span></span>
						</div>
					</div>
				</a>
			</div>
			{/if}

			{insert name=adv assign=adv group='video_right'}
			{if $adv.ad}
			<div class="ad-content">
				{$adv.ad}
			</div>	
			{elseif $adv.help}		
				<div class="ad-body" style="width:{$adv.width}px;">
					<p class="ad-title"><span>{t c='global.sponsors'}</span><span class="ad-group">VIDEO RIGHT</span></p>
					<p class="ad-size">{$adv.width} &times; Auto</p>
				</div>			
			{/if}

			{if $videos}
			{section name=i loop=$videos}
					<div class="related-video">
						<a href="{$relative}/video/{$videos[i].VID}/{$videos[i].title|clean}">
							<div class="thumb-overlay" {if $videos[i].vthumbs == '1'} id="playvthumb_{$videos[i].VID}"{/if}>
								<img src="{insert name=thumb_path vid=$videos[i].VID}/{$videos[i].thumb}.jpg" title="{$videos[i].title|escape:'html'}" alt="{$videos[i].title|escape:'html'}" {if $videos[i].vthumbs == '0'}id="rotate_{$videos[i].VID}_{$videos[i].thumbs}_{$videos[i].thumb}_viewed"{/if} class="img-responsive {if $videos[i].type == 'private'}img-private{/if}"/>
								{if $videos[i].type == 'private'}<div class="label-private">{t c='global.PRIVATE'}</div>{/if}
								<span class="xb-thumb-meta">
									{insert name=views assign=s_views views=$videos[i].viewnumber}
									<span class="xb-thumb-views"><i class="fas fa-eye"></i> {$s_views}</span>
									{if isset($videos[i].username)}
									<span class="xb-thumb-user">@{$videos[i].username}</span>
									{/if}
									<span class="xb-thumb-title">
										<span class="xb-thumb-title-inner">
											<span class="xb-tt">{$videos[i].title|escape:'html'}</span><span class="xb-tt">{$videos[i].title|escape:'html'}</span>
										</span>
									</span>
								</span>
								<div class="duration">
									{if $videos[i].hd==1}<span class="hd-text-icon">HD</span>{/if}
									{insert name=duration assign=duration duration=$videos[i].duration}
									{$duration}
								</div>
							</div>
							<div class="content-info">
								<a href="{$relative}/video/{$videos[i].VID}/{$videos[i].title|clean}">
									<span class="content-title">{$videos[i].title|escape:'html'}</span>
								</a>
							</div>
						</a>
						<div class="clearfix"></div>
					</div>			
			{/section}			
			{/if}
		</div>	
	</div>
	{/if}

	{insert name=adv assign=adv group='video_bottom'}
	{if $adv.ad}		
	<div class="ad-content">
		{$adv.ad}
	</div>	
	{elseif $adv.help}		
		<div class="ad-body">
			<p class="ad-title"><span>{t c='global.sponsors'}</span><span class="ad-group">VIDEO BOTTOM</span></p>
			<p class="ad-size">Auto &times; Auto</p>
		</div>			
	{/if}		
</div>
{if $player.engine == 'mediabunny'}
<script type="text/javascript" src="{$relative_tpl}/js/decrypt.min.js?ver=1.0.35"></script>
{else}
<script type="text/javascript" src="{$relative_tpl}/js/player.js?ver=1.0.35"></script>
<script type="text/javascript" src="{$relative_tpl}/js/decrypt.min.js?ver=1.0.35"></script>
<script type="text/javascript" src="{$relative_tpl}/js/player-init.min.js?ver=1.0.35"></script>
{/if}