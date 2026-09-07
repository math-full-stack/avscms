/**
 * GA4 Custom Events for AVSCMS
 * Tracks: video_play, video_complete, video_rate, video_favorite,
 *         video_share, user_subscribe, search, signup, video_flag
 */
(function() {
    if (typeof gtag === 'undefined') return;

    function ga4event(name, params) {
        gtag('event', name, params);
    }

    // ── video_play / video_complete ──────────────────────────────
    function bindPlayerEvents() {
        if (typeof videojs === 'undefined') return;
        try {
            var p = videojs('video');
            if (!p) return;

            var playFired = false;
            p.on('play', function() {
                if (!playFired) {
                    playFired = true;
                    ga4event('video_play', {
                        video_id: (typeof video_id !== 'undefined') ? video_id : '',
                        video_title: document.querySelector('.well-filters h1') ? document.querySelector('.well-filters h1').textContent.trim() : ''
                    });
                }
            });
            p.on('ended', function() {
                ga4event('video_complete', {
                    video_id: (typeof video_id !== 'undefined') ? video_id : '',
                    video_title: document.querySelector('.well-filters h1') ? document.querySelector('.well-filters h1').textContent.trim() : ''
                });
                playFired = false;
            });
        } catch(e) {}
    }

    // ── video_rate (like / dislike) ──────────────────────────────
    $(document).on('click', "[id*='vote_']", function() {
        var parts = $(this).attr('id').split('_');
        ga4event('video_rate', {
            video_id: (typeof video_id !== 'undefined') ? video_id : (parts[3] || ''),
            rating_type: parts[1] === 'up' ? 'like' : 'dislike'
        });
    });

    // ── video_favorite ───────────────────────────────────────────
    $(document).on('click', "[id*='video_favorite']", function() {
        var vid = $(this).attr('data-vid') || (typeof video_id !== 'undefined' ? video_id : '');
        ga4event('video_favorite', {
            video_id: vid,
            video_title: document.querySelector('.well-filters h1') ? document.querySelector('.well-filters h1').textContent.trim() : ''
        });
    });

    // ── video_share ──────────────────────────────────────────────
    $(document).on('click', "#video_share", function() {
        ga4event('video_share', {
            video_id: (typeof video_id !== 'undefined') ? video_id : '',
            method: 'modal_open'
        });
    });

    // track actual share links inside the modal
    $(document).on('click', "#shareModal a[href]", function() {
        var href = $(this).attr('href') || '';
        var method = 'other';
        if (href.indexOf('facebook.com') > -1) method = 'facebook';
        else if (href.indexOf('twitter.com') > -1 || href.indexOf('x.com') > -1) method = 'twitter';
        else if (href.indexOf('reddit.com') > -1) method = 'reddit';
        else if (href.indexOf('pinterest.com') > -1) method = 'pinterest';
        else if (href.indexOf('tumblr.com') > -1) method = 'tumblr';
        else if (href.indexOf('mailto:') > -1) method = 'email';
        else if (href.indexOf('whatsapp.com') > -1 || href.indexOf('wa.me') > -1) method = 'whatsapp';
        else if (href.indexOf('telegram') > -1) method = 'telegram';
        ga4event('video_share', {
            video_id: (typeof video_id !== 'undefined') ? video_id : '',
            method: method
        });
    });

    // ── user_subscribe ───────────────────────────────────────────
    $(document).on('click', "#user_subscription", function() {
        var uid = $(this).attr('data-uid') || '';
        ga4event('user_subscribe', {
            channel_id: uid,
            action: $(this).attr('data-subscribed') === '1' ? 'unsubscribe' : 'subscribe'
        });
    });

    // ── search ───────────────────────────────────────────────────
    $(document).on('submit', "#search_form, #search_form_xs", function() {
        var term = ($(this).find('#search_query').val() || '').trim();
        var type = $('#search_type').val() || 'videos';
        if (term) {
            ga4event('search', {
                search_term: term,
                search_type: type
            });
        }
    });

    // ── signup ───────────────────────────────────────────────────
    $(document).on('click', "#fb-signup-submit-new, #g-signup-submit-new, #signup_submit", function() {
        var method = 'manual';
        if ($(this).attr('id') === 'fb-signup-submit-new') method = 'facebook';
        else if ($(this).attr('id') === 'g-signup-submit-new') method = 'google';
        ga4event('signup', { method: method });
    });

    // ── video_flag ───────────────────────────────────────────────
    $(document).on('click', "#submit_flag_video", function() {
        var reason = $("input[name='flag_reason']:checked").val() || 'other';
        ga4event('video_flag', {
            video_id: $(this).attr('data-vid') || (typeof video_id !== 'undefined' ? video_id : ''),
            reason: reason
        });
    });

    // ── init ─────────────────────────────────────────────────────
    $(document).ready(function() {
        bindPlayerEvents();
    });

})();
