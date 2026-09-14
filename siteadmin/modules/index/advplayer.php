<?php
defined('_VALID') or die('Restricted Access!');

Auth::checkAdmin();

if ( isset($_GET['a']) ) {
    $action     = trim($_GET['a']);
    $AID        = ( isset($_GET['AID']) && is_numeric($_GET['AID']) ) ? intval(trim($_GET['AID'])) : NULL;
    if ( $action == 'activate' or $action == 'suspend' ) {
        $status = ( $action == 'activate' ) ? '1' : '0';
        $sql    = "UPDATE adv_player SET status = '" .$status. "' WHERE id = " .$AID. " LIMIT 1";
        $conn->execute($sql);
        if ( $conn->Affected_Rows() ) {
            $messages[] = 'Player ad successfully ' .$action. 'ed!';
        } else {
            $errors[] = 'Failed to ' .$action. ' player ad! Invalid advertise id!?';
        }
    } elseif ( $action == 'delete' ) {
        $sql    = "DELETE FROM adv_player WHERE id = " .$AID. " LIMIT 1";
        $conn->execute($sql);
        $messages[]    = 'Player ad deleted successfully!';
    } else {
        $errors[] = 'Invalid action specified! Allowed actions: activate, suspend and delete!';
    }
}

$player_types   = array('preroll', 'midroll', 'pause', 'overlay', 'postroll');
$tfilter        = ( isset($_GET['type']) && in_array($_GET['type'], $player_types) ) ? trim($_GET['type']) : '';

$sql    = "SELECT p.*, g.advgrp_name FROM adv_player AS p LEFT JOIN adv_group AS g ON p.grp = g.advgrp_name";
if ( $tfilter != '' ) {
    $sql .= " WHERE p.type = " .$conn->qStr($tfilter);
}
$sql    .= " ORDER BY p.id DESC";
$rs     = $conn->execute($sql);
$player_ads = ( $rs ) ? $rs->getrows() : array();

$smarty->assign('player_ads', $player_ads);
$smarty->assign('tfilter', $tfilter);
$smarty->assign('player_types', $player_types);
?>