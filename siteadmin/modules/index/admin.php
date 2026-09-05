<?php
defined('_VALID') or die('Restricted Access!');

Auth::checkAdmin();

require $config['BASE_DIR']. '/include/config.template.php';
if ( isset($_POST['submit_admin']) ) {
    $filter                 = new VFilter();

    $admin_name             = $filter->get('admin_name');
    // Passwords must be read RAW: VFilter runs HTMLPurifier (xss_filter),
    // which HTML-encodes '&' -> '&amp;' and corrupts the stored value so the
    // login never matches again. siteadmin/login.php also compares raw input.
    $admin_pass             = trim($_POST['admin_pass'] ?? '');
    $admin_pass_np          = trim($_POST['admin_pass_np'] ?? '');
    $admin_pass_cnp         = trim($_POST['admin_pass_cnp'] ?? '');
    $admin_email            = $filter->get('admin_email');
    $noreply_email          = $filter->get('noreply_email');

    if ( $admin_name == '' ) {
        $errors[]   = 'Admin Name (used for siteadmin login) cannot be blank!';
		$err['admin_name'] = 1;
    } elseif ( strlen($admin_name) < 5 ) {
        $errors[]   = 'Admin Name (used for siteadmin login) must be at least 6 characters long!';
		$err['admin_name'] = 1;
    }
    

    if ( $admin_pass != $config['admin_pass'] ) {
        $errors[]   = 'Wrong Admin Password!';
		$err['admin_pass'] = 1;
    }
	
	if ( $admin_pass_np != '' || $admin_pass_cnp != '') {
		if ( strlen($admin_pass_np) < 5 ) {
			$errors[]   = 'Admin Password (used for siteadmin login) must be at least 6 characters long!';
			$err['admin_pass_np'] = 1;
			$err['admin_pass_cnp'] = 1;
		} elseif ( $admin_pass_np != $admin_pass_cnp ) {
			$errors[]   = 'New Password and Confirm New Password do not match!';
			$err['admin_pass_np'] = 1;
			$err['admin_pass_cnp'] = 1;			
		}
	}
	
    if ( $admin_email == '' ) {
        $errors[]   = 'Admin Email field cannot be blank!';
		$err['admin_email'] = 1;
    } elseif ( !VValidation::email_($admin_email) ) {
        $errors[]   = 'Admin Email field is not a valid email address!';
		$err['admin_email'] = 1;		
    }
    
    if ( $noreply_email == '' ) {
        $errors[]   = 'Noreply Email field cannot be blank!';
		$err['noreply_email'] = 1;
    } elseif ( !VValidation::email_($noreply_email) ) {
        $errors[]   = 'Noreply Email field is not a valid email address!';
		$err['noreply_email'] = 1;		
    }
    
    if ( !$errors ) {
        $config['admin_name']           = $admin_name;
        
        $config['admin_email']          = $admin_email;
        $config['noreply_email']        = $noreply_email;
		if ( $admin_pass_np != '') {
			$config['admin_pass']       = $admin_pass_np;
		}
        update_config($config);
        update_smarty();    
        $messages[] = 'Admin Settings Updated Successfuly!';
    }
	
	$smarty->assign('admin_name', $admin_name);
	$smarty->assign('admin_email', $admin_email);
	$smarty->assign('noreply_email', $noreply_email);

}

$smarty->assign('templates', $templates);
$smarty->assign('err', $err);
?>
