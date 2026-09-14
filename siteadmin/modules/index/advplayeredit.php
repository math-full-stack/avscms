<?php
defined('_VALID') or die('Restricted Access!');
require '../include/function_global.php';
Auth::checkAdmin();

$player_types = array('preroll', 'midroll', 'pause', 'overlay', 'postroll');
$creatives    = array('image', 'html', 'video', 'vast');
$positions    = array('bottom-right', 'bottom-left', 'top-right', 'top-left', 'center');

$AID = ( isset($_GET['AID']) && is_numeric($_GET['AID']) ) ? intval($_GET['AID']) : NULL;
if ( !$AID ) {
    $errors[] = 'Invalid player ad id!';
}

if ( ( isset($_POST['adv_edit']) || isset($_POST['adv_add']) ) && !$errors ) {
    $adv = array(
        'name'          => trim($_POST['adv_name']),
        'type'          => ( in_array($_POST['adv_type'], $player_types) ) ? trim($_POST['adv_type']) : 'preroll',
        'grp'           => trim($_POST['adv_grp']),
        'device'        => ( in_array($_POST['adv_device'], array('dm', 'd', 'm')) ) ? trim($_POST['adv_device']) : 'dm',
        'creative'      => ( in_array($_POST['adv_creative'], $creatives) ) ? trim($_POST['adv_creative']) : 'image',
        'media_url'     => trim($_POST['adv_media_url']),
        'code'          => $_POST['adv_code'],
        'duration'      => max(0, intval($_POST['adv_duration'])),
        'fake_progress' => ( $_POST['adv_fake_progress'] == '1' ) ? '1' : '0',
        'skip_after'    => max(0, intval($_POST['adv_skip_after'])),
        'schedule'      => trim($_POST['adv_schedule']),
        'cap_per_hour'  => min(50, max(0, intval($_POST['adv_cap_per_hour']))),
        'position'      => ( in_array($_POST['adv_position'], $positions) ) ? trim($_POST['adv_position']) : 'bottom-right',
        'status'        => ( $_POST['adv_status'] == '1' ) ? '1' : '0'
    );

    if ( $adv['name'] == '' ) {
        $errors[] = 'Player ad name field cannot be left blank!';
        $err['name'] = 1;
    }
    if ( $adv['grp'] == '' ) {
        $errors[] = 'Please select a player ad group!';
        $err['grp'] = 1;
    }

    $adv['categories'] = '-';
    foreach ( $_POST as $key => $value ) {
        if ( $key != 'check_all_categories' && substr($key, 0, 9) == 'category_' ) {
            if ( $value == '1' ) {
                $cid = intval(str_replace('category_', '', $key));
                $adv['categories'] = $adv['categories'].$cid.'-';
            }
        }
    }

    if ( in_array($adv['creative'], array('image', 'video')) && $adv['media_url'] == '' ) {
        $errors[] = 'Media URL cannot be blank for ' .$adv['creative']. ' creative!';
        $err['media_url'] = 1;
    }
    if ( in_array($adv['creative'], array('html', 'vast')) && $adv['code'] == '' ) {
        $errors[] = 'Code cannot be blank for ' .$adv['creative']. ' creative!';
        $err['code'] = 1;
    }
    if ( $adv['fake_progress'] == '1' && $adv['duration'] < 5 ) {
        $errors[] = 'Duration must be at least 5 seconds when fake progress is enabled!';
        $err['duration'] = 1;
    }

    if ( !$errors ) {
        $sql = "UPDATE adv_player SET
                    type = " .$conn->qStr($adv['type']). ",
                    grp = " .$conn->qStr($adv['grp']). ",
                    name = " .$conn->qStr($adv['name']). ",
                    device = " .$conn->qStr($adv['device']). ",
                    categories = " .$conn->qStr($adv['categories']). ",
                    creative = " .$conn->qStr($adv['creative']). ",
                    media_url = " .$conn->qStr($adv['media_url']). ",
                    code = " .$conn->qStr($adv['code']). ",
                    duration = " .intval($adv['duration']). ",
                    fake_progress = " .$conn->qStr($adv['fake_progress']). ",
                    skip_after = " .intval($adv['skip_after']). ",
                    schedule = " .$conn->qStr($adv['schedule']). ",
                    cap_per_hour = " .intval($adv['cap_per_hour']). ",
                    position = " .$conn->qStr($adv['position']). ",
                    status = " .$conn->qStr($adv['status']). "
                WHERE id = " .$AID. " LIMIT 1";
        $conn->execute($sql);
        $messages[] = 'Player ad successfully updated!';
    }
}

if ( !$errors ) {
    $sql = "SELECT * FROM adv_player WHERE id = " .$AID. " LIMIT 1";
    $rs  = $conn->execute($sql);
    $adv = $rs->getrows();
    $adv = $adv['0'];
}

$sql        = "SELECT advgrp_id, advgrp_name FROM adv_group WHERE advgrp_name LIKE 'player\\_%' ORDER BY advgrp_name ASC";
$rs         = $conn->execute($sql);
$advgroups  = ( $rs ) ? $rs->getrows() : array();

$categories = get_categories();
foreach ( $categories as $k => $v ) {
    if ( $adv['categories'] == '-' || strpos($adv['categories'], '-'.$categories[$k]['CHID'].'-') !== false ) {
        $categories[$k]['checked'] = 1;
    } else {
        $categories[$k]['checked'] = 0;
    }
}

$smarty->assign('adv', $adv);
$smarty->assign('advgroups', $advgroups);
$smarty->assign('categories', $categories);
$smarty->assign('edit_mode', true);
$smarty->assign('player_types', $player_types);
$smarty->assign('creatives', $creatives);
$smarty->assign('positions', $positions);
?>