<!doctype html>
<meta charset="utf-8">
<title>probe</title>
<body style="margin:0">
<pre id="out">pending</pre>
<iframe id="f" src="/avscms/" style="width:1440px;height:1400px;border:0"></iframe>
<script>
var M = '@' + '@A@' + '@', N = '@' + '@B@' + '@';
var f = document.getElementById('f');
function measure() {
	try {
		var d = f.contentDocument, w = d.defaultView;
		var hs = {}, cards = [], out = [];
		d.querySelectorAll('#home-feed .thumb-overlay').forEach(function (o, i) {
			var r = o.getBoundingClientRect(), h = Math.round(r.height);
			hs[h] = (hs[h] || 0) + 1;
			cards.push(h + 'x' + Math.round(r.width) + ':' + (o.className.indexOf('xb-portrait') > -1 ? 'P' : 'L') + ':' + (o.querySelector('.xb-thumb-meta') ? 'meta' : 'hoist'));
			if (o.className.indexOf('xb-portrait') > -1) { return; }
			var dur = o.querySelector('.duration'), chip = o.querySelector('.xb-thumb-views');
			var dr = dur.getBoundingClientRect(), cr = chip.getBoundingClientRect();
			var titleMax = Math.round(r.width - 110);
			out.push({
				i: i,
				box: Math.round(r.width) + 'x' + h,
				durLeftFromRight: Math.round(r.right - dr.left),
				durW: Math.round(dr.width),
				viewsRight: Math.round(cr.right - r.left),
				titleRightEdge: 8 + titleMax,
				viewsEncostaDur: Math.round(cr.right - dr.left)
			});
		});
		document.getElementById('out').textContent = M + JSON.stringify({ heights: hs, cards: cards, landscape: out }) + N;
	} catch (e) {
		document.getElementById('out').textContent = M + 'ERRO ' + e.message + N;
	}
}
f.addEventListener('load', function () { measure(); setTimeout(measure, 4000); });
setTimeout(measure, 12000);
</script>