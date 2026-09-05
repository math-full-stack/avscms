<?php
define('_VALID', true);
require 'include/config.php';
require 'include/function_global.php';
require 'include/function_smarty.php';
require 'classes/filter.class.php';

$page           = NULL;
$pages_allowed  = array('terms', 'privacy', 'dmca', '_2257', 'webmasters', 'advertise', 'faq');
$page           = get_request_arg('static', 'STRING');
$template   	= 'static/' .$page;
switch ( $page ) {
    case 'faq':
        $self_title 	= 'Perguntas Frequentes (FAQ)';
        $self_description = 'Perguntas frequentes sobre o site: uso, cadastro, upload, conteúdo, denúncias e suporte.';
        $self_keywords 	= 'faq, perguntas frequentes, ajuda, suporte';
        break;
    case 'terms':
        $self_title 	= 'Termos e Condições de Uso';
        $self_description = 'Termos e condições de uso do site: elegibilidade, regras de conteúdo, uploads e responsabilidades.';
        $self_keywords 	= 'termos, condições, uso, regras, legal';
        break;
    case 'privacy':
        $self_title 	= 'Política de Privacidade';
        $self_description = 'Política de privacidade em conformidade com a LGPD: dados coletados, cookies, direitos e contato.';
        $self_keywords 	= 'privacidade, politica de privacidade, lgpd, dados pessoais, cookies';
        break;
    case 'dmca':
        $self_title 	= 'Política DMCA — Direitos Autorais';
        $self_description = 'Procedimento para notificação de violação de direitos autorais (DMCA) e remoção de conteúdo.';
        $self_keywords 	= 'dmca, direitos autorais, copyright, remoção, notificação';
        break;
    case '_2257':
        $self_title 	= NULL;
        $self_description = 'Declaração de conformidade com 18 U.S.C. 2257 e responsabilidade pela manutenção de registros.';
        $self_keywords 	= '2257, conformidade, registro, maiores de 18 anos';
        $template   	= 'static/2257';
        break;
    case 'webmasters':
        $self_title 	= 'Para Webmasters — Incorporar Vídeos';
        $self_description = 'Aprenda a incorporar vídeos do site em suas páginas, requisitos de uso e parcerias.';
        $self_keywords 	= 'webmasters, embed, incorporar, iframe, parceria';
        break;
    case 'advertise':
        $self_title 	= 'Anuncie Conosco';
        $self_description = 'Formatos de publicidade, segmentação, métricas e contato comercial para anunciantes.';
        $self_keywords 	= 'anuncie, publicidade, anuncios, banners, midia, ads';
        break;
    default:
        VRedirect::go($config['BASE_URL']. '/notfound/page_invalid');    
}

$self_title = ( isset($self_title) ) ? $self_title. ' - ' .$config['site_name'] : $config['site_name'];

$smarty->assign('errors',$errors);
$smarty->assign('messages',$messages);
$smarty->assign('menu', 'home');
$smarty->assign('self_title', $self_title);
$smarty->assign('self_description', ( isset($self_description) ) ? $self_description : '');
$smarty->assign('self_keywords', ( isset($self_keywords) ) ? $self_keywords : '');
$smarty->assign('template', $template.'.tpl');
$smarty->loadFilter('output', 'trimwhitespace');
$smarty->display('header.tpl');
$smarty->display('static.tpl');
$smarty->display('footer.tpl');
?>