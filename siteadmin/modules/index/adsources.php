<?php
defined('_VALID') or die('Restricted Access!');

Auth::checkAdmin();

// ---------------------------------------------------------------------------
// Ações
// ---------------------------------------------------------------------------
if ( isset($_POST['ts_save']) ) {
    $src_id     = isset($_POST['src_id'])     ? intval($_POST['src_id']) : 0;
    $name       = isset($_POST['name'])       ? trim($_POST['name'])     : '';
    $provider   = isset($_POST['provider'])   ? trim($_POST['provider']) : 'custom';
    $html       = isset($_POST['html'])       ? trim($_POST['html'])     : '';
    $share      = isset($_POST['share'])      ? intval($_POST['share'])  : 50;
    $active     = ( isset($_POST['active']) && $_POST['active'] == '1' ) ? '1' : '0';

    if ( $name == '' ) {
        $errors[] = 'Informe o nome da fonte.';
    } elseif ( $share < 0 || $share > 100 ) {
        $errors[] = 'Share deve ser de 0 a 100 (% das impressões).';
    } elseif ( $html == '' ) {
        $errors[] = 'Informe o HTML/embed da fonte.';
    }

    if ( !$errors ) {
        if ( $src_id ) {
            $sql = "UPDATE adv_source
                    SET name = ".$conn->qStr($name).",
                        provider = ".$conn->qStr($provider).",
                        html = ".$conn->qStr($html).",
                        share = ".intval($share).",
                        active = '".$active."',
                        updated_at = ".time()."
                    WHERE id = ".$src_id." LIMIT 1";
            $conn->execute($sql);
            $messages[] = 'Fonte atualizada!';
        } else {
            $sql = "INSERT INTO adv_source (name, provider, html, share, active, created_at, updated_at)
                    VALUES (".$conn->qStr($name).", ".$conn->qStr($provider).", ".$conn->qStr($html).",
                            ".intval($share).", '".$active."', ".time().", ".time().")";
            $conn->execute($sql);
            $messages[] = 'Fonte criada!';
        }
    }
}

if ( isset($_GET['a']) && $_GET['a'] == 'delete' && isset($_GET['ID']) && is_numeric($_GET['ID']) ) {
    $sql = "DELETE FROM adv_source WHERE id = ".intval($_GET['ID'])." LIMIT 1";
    $conn->execute($sql);
    $messages[] = 'Fonte removida!';
}

if ( isset($_GET['a']) && ( $_GET['a'] == 'activate' || $_GET['a'] == 'suspend' ) && isset($_GET['ID']) && is_numeric($_GET['ID']) ) {
    $status = ( $_GET['a'] == 'activate' ) ? '1' : '0';
    $sql = "UPDATE adv_source SET active = '".$status."', updated_at = ".time()." WHERE id = ".intval($_GET['ID'])." LIMIT 1";
    $conn->execute($sql);
    $messages[] = 'Fonte '.( $status == '1' ? 'ativada' : 'suspensa' ).'!';
}

// ---------------------------------------------------------------------------
// Dados da tela
// ---------------------------------------------------------------------------
$sql   = "SELECT id, name, provider, share, active, impressions, created_at, updated_at
          FROM adv_source ORDER BY id DESC";
$rs    = $conn->execute($sql);
$sources = $rs ? $rs->getrows() : array();

// Contagem de grupos (slots) existentes, para o resumo de alcance
$sql   = "SELECT COUNT(*) AS total FROM adv_group WHERE advgrp_status = '1'";
$rs    = $conn->execute($sql);
$total_groups = $rs ? intval($rs->fields['total']) : 0;

// Fonte em edição (form)
$edit_source = NULL;
if ( isset($_GET['e']) && is_numeric($_GET['e']) ) {
    $sql = "SELECT * FROM adv_source WHERE id = ".intval($_GET['e'])." LIMIT 1";
    $rs  = $conn->execute($sql);
    if ( $conn->Affected_Rows() == 1 ) {
        $edit_source = $rs->fields;
    }
}

$smarty->assign('src_list', $sources);
$smarty->assign('src_total_groups', $total_groups);
$smarty->assign('src_edit', $edit_source);
?>