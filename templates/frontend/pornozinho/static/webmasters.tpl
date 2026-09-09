<div class="mt-3">
	<div class="well-filters">
		<h1>Para Webmasters — Incorporar Vídeos</h1>
	</div>
</div>

<div class="card-sub mt-3">
	<h5>Como incorporar vídeos do {$site_name}</h5>
	<p>Você pode reproduzir vídeos do {$site_name} em seu próprio site usando um simples <em>iframe</em>. O conteúdo permanece hospedado em nossos servidores, e a reprodução é transmitida pelo nosso player.</p>
	<p><strong>Passo 1 — Obtenha o código:</strong> em qualquer página de vídeo, utilize o botão "Incorporar" (ícone de compartilhar) para copiar o código pronto, ou monte o código com a chave (vkey) do vídeo:</p>
	<pre class="my-2"><code>&lt;iframe width="640" height="360" src="{$baseurl}/embed/{literal}{CHAVE-DO-VIDEO}{/literal}" frameborder="0" allowfullscreen&gt;&lt;/iframe&gt;</code></pre>
	<p><strong>Passo 2 — Ajuste o tamanho:</strong> você pode alterar os valores de largura (width) e altura (height) do iframe. Para um reprodutor responsivo, utilize largura de 100% com altura proporcional via CSS.</p>
	<p class="mb-0"><strong>Passo 3 — Publique:</strong> cole o código no HTML da sua página. O player carrega automaticamente o vídeo selecionado.</p>
</div>

<div class="card-sub mt-3">
	<h5>Requisitos para uso</h5>
	<ul class="mb-0">
		<li>somente sites destinados a maiores de 18 anos e em conformidade com a legislação local;</li>
		<li>é proibido veicular nosso player ou conteúdo em páginas que envolvam atividade ilícita, conteúdo enganoso ou que simule ser de nossa autoria;</li>
		<li>é vedado redistribuir, baixar e rehospedar os arquivos de vídeo ou apresentar o conteúdo como se fosse seu;</li>
		<li>recomendamos manter o link/identificação do {$site_name} junto ao player para fins de transparência.</li>
	</ul>
</div>

<div class="card-sub mt-3">
	<h5>Parcerias, feeds e dados em massa</h5>
	<p>Para projetos que precisem de acesso em volume — feeds RSS/XML de vídeos, integrações, APIs ou parcerias de distribuição — entre em contato pelo e-mail <a href="mailto:{$admin_email}">{$admin_email}</a>. As solicitações são avaliadas caso a caso e estão sujeitas aos nossos <a href="{$baseurl}/static/terms">Termos de Uso</a> e à legislação de proteção de dados.</p>
</div>

<div class="card-sub mt-3">
	<h5>Conformidade e remoção</h5>
	<p class="mb-0">O conteúdo incorporado continua sujeito às nossas <a href="{$baseurl}/static/terms">políticas de uso</a>, à <a href="{$baseurl}/static/dmca">Política DMCA</a> e à <a href="{$baseurl}/static/privacy">Política de Privacidade</a>. Se um vídeo for removido ou tornado privado, a incorporação deixará de exibir o conteúdo.</p>
</div>