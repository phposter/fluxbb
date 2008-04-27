<?php
/***********************************************************************

  Copyright (C) 2002-2008  PunBB.org

  This file is part of PunBB.

  PunBB is free software; you can redistribute it and/or modify it
  under the terms of the GNU General Public License as published
  by the Free Software Foundation; either version 2 of the License,
  or (at your option) any later version.

  PunBB is distributed in the hope that it will be useful, but
  WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program; if not, write to the Free Software
  Foundation, Inc., 59 Temple Place, Suite 330, Boston,
  MA  02111-1307  USA

************************************************************************/


if (!defined('PUN_ROOT'))
	define('PUN_ROOT', '../');
require PUN_ROOT.'include/common.php';
require PUN_ROOT.'include/common_admin.php';
require_once PUN_ROOT.'include/xml.php';

($hook = get_hook('aex_start')) ? eval($hook) : null;

if ($pun_user['g_id'] != PUN_ADMIN)
	message($lang_common['No permission']);

// Load the admin.php language file
require PUN_ROOT.'lang/'.$pun_user['language'].'/admin.php';

// Make sure we have XML support
if (!function_exists('xml_parser_create'))
	message($lang_admin['No XML support']);

$section = isset($_GET['section']) ? $_GET['section'] : null;


// Install an extension
if (isset($_GET['install']) || isset($_GET['install_hotfix']))
{
	($hook = get_hook('aex_install_selected')) ? eval($hook) : null;

	// User pressed the cancel button
	if (isset($_POST['install_cancel']))
		redirect(pun_link($pun_url['admin_extensions_install']), $lang_admin['Cancel redirect']);

	$id = preg_replace('/[^0-9a-z_]/', '', isset($_GET['install']) ? $_GET['install'] : $_GET['install_hotfix']);

	// Load manifest (either locally or from punbb.org updates service)
	if (isset($_GET['install']))
		$manifest = @file_get_contents(PUN_ROOT.'extensions/'.$id.'/manifest.xml');
	else
		$manifest = @end(get_remote_file('http://punbb.org/update/manifest/'.$id.'.xml', 16));

	// Parse manifest.xml into an array and validate it
	$ext_data = xml_to_array($manifest);
	$errors = validate_manifest($ext_data, $id);

	if (!empty($errors))
		message(isset($_GET['install']) ? $lang_common['Bad request'] : $lang_admin['Hotfix download failed']);

	// Setup breadcrumbs
	$pun_page['crumbs'] = array(
		array($pun_config['o_board_title'], pun_link($pun_url['index'])),
		array($lang_admin['Forum administration'], pun_link($pun_url['admin_index'])),
		array($lang_admin['Install extensions'], pun_link($pun_url['admin_extensions_install'])),
		$lang_admin['Install extension']
	);

	if (isset($_POST['install_comply']))
	{
		($hook = get_hook('aex_install_comply_form_submitted')) ? eval($hook) : null;

		// Is there some uninstall code to store in the db?
		$uninstall_code = (isset($ext_data['extension']['uninstall']) && trim($ext_data['extension']['uninstall']) != '') ? '\''.$pun_db->escape(trim($ext_data['extension']['uninstall'])).'\'' : 'NULL';

		// Is there an uninstall note to store in the db?
		$uninstall_note = 'NULL';
		foreach ($ext_data['extension']['note'] as $cur_note)
		{
			if ($cur_note['attributes']['type'] == 'uninstall' && trim($cur_note['content']) != '')
				$uninstall_note = '\''.$pun_db->escape(trim($cur_note['content'])).'\'';
		}

		$notices = array();

		// Is this a fresh install or an upgrade?
		$query = array(
			'SELECT'	=> 'e.version',
			'FROM'		=> 'extensions AS e',
			'WHERE'		=> 'e.id=\''.$pun_db->escape($id).'\''
		);

		($hook = get_hook('aex_qr_get_current_ext_version')) ? eval($hook) : null;
		$result = $pun_db->query_build($query) or error(__FILE__, __LINE__);
		if ($pun_db->num_rows($result))
		{
			// EXT_CUR_VERSION will be available to the extension install routine (to facilitate extension upgrades)
			define('EXT_CUR_VERSION', $pun_db->result($result));

			// Run the author supplied install code
			if (isset($ext_data['extension']['install']) && trim($ext_data['extension']['install']) != '')
				eval($ext_data['extension']['install']);

			// Update the existing extension
			$query = array(
				'UPDATE'	=> 'extensions',
				'SET'		=> 'title=\''.$pun_db->escape($ext_data['extension']['title']).'\', version=\''.$pun_db->escape($ext_data['extension']['version']).'\', description=\''.$pun_db->escape($ext_data['extension']['description']).'\', author=\''.$pun_db->escape($ext_data['extension']['author']).'\', uninstall='.$uninstall_code.', uninstall_note='.$uninstall_note,
				'WHERE'		=> 'id=\''.$pun_db->escape($id).'\''
			);

			($hook = get_hook('aex_qr_update_ext')) ? eval($hook) : null;
			$pun_db->query_build($query) or error(__FILE__, __LINE__);

			// Delete the old hooks
			$query = array(
				'DELETE'	=> 'extension_hooks',
				'WHERE'		=> 'extension_id=\''.$pun_db->escape($id).'\''
			);

			($hook = get_hook('aex_qr_update_ext_delete_hooks')) ? eval($hook) : null;
			$pun_db->query_build($query) or error(__FILE__, __LINE__);
		}
		else
		{
			// Run the author supplied install code
			if (isset($ext_data['extension']['install']) && trim($ext_data['extension']['install']) != '')
				eval($ext_data['extension']['install']);

			// Add the new extension
			$query = array(
				'INSERT'	=> 'id, title, version, description, author, uninstall, uninstall_note',
				'INTO'		=> 'extensions',
				'VALUES'	=> '\''.$pun_db->escape($ext_data['extension']['id']).'\', \''.$pun_db->escape($ext_data['extension']['title']).'\', \''.$pun_db->escape($ext_data['extension']['version']).'\', \''.$pun_db->escape($ext_data['extension']['description']).'\', \''.$pun_db->escape($ext_data['extension']['author']).'\', '.$uninstall_code.', '.$uninstall_note
			);

			($hook = get_hook('aex_qr_add_ext')) ? eval($hook) : null;
			$pun_db->query_build($query) or error(__FILE__, __LINE__);
		}

		// Now insert the hooks
		foreach ($ext_data['extension']['hooks']['hook'] as $hook)
		{
			$query = array(
				'INSERT'	=> 'id, extension_id, code, installed',
				'INTO'		=> 'extension_hooks',
				'VALUES'	=> '\''.$pun_db->escape(trim($hook['attributes']['id'])).'\', \''.$pun_db->escape($id).'\', \''.$pun_db->escape(trim($hook['content'])).'\', '.time()
			);

			($hook = get_hook('aex_qr_add_hook')) ? eval($hook) : null;
			$pun_db->query_build($query) or error(__FILE__, __LINE__);
		}

		// Empty the PHP cache
		$d = dir(PUN_CACHE_DIR);
		while (($entry = $d->read()) !== false)
		{
			if (substr($entry, strlen($entry)-4) == '.php')
				@unlink(PUN_CACHE_DIR.$entry);
		}
		$d->close();

		// Regenerate the hooks cache
		require_once PUN_ROOT.'include/cache.php';
		generate_hooks_cache();

		// Display notices if there are any
		if (!empty($notices))
		{
			($hook = get_hook('aex_install_notices_pre_header_load')) ? eval($hook) : null;

			define('PUN_PAGE_SECTION', 'extensions');
			define('PUN_PAGE', 'admin-extensions-install');
			require PUN_ROOT.'header.php';

?>
<div id="pun-main" class="main sectioned admin">

<?php echo generate_admin_menu(); ?>

	<div class="main-head">
		<h1><span>{ <?php echo end($pun_page['crumbs']) ?> }</span></h1>
	</div>
	<div class="main-content frm">
		<div class="frm-head">
			<h2><span><?php echo end($pun_page['crumbs']) ?> "<?php echo pun_htmlencode($ext_data['extension']['title']) ?>"</span></h2>
		</div>
		<div class="frm-info">
			<p><?php echo $lang_admin['Extension installed info'] ?></p>
			<ul>
<?php

			while (list(, $cur_notice) = each($notices))
				echo "\t\t\t\t".'<li><span>'.$cur_notice.'</span></li>'."\n";

?>
			</ul>
			<p><a href="<?php echo pun_link($pun_url['admin_extensions_manage']) ?>"><?php echo $lang_admin['Manage extensions'] ?></a></p>
		</div>
	</div>

</div>
<?php

			require PUN_ROOT.'footer.php';
		}
		else
			redirect(pun_link($pun_url['admin_extensions_manage']), $lang_admin['Extension installed'].' '.$lang_admin['Redirect']);
	}


	($hook = get_hook('aex_install_pre_header_load')) ? eval($hook) : null;

	define('PUN_PAGE_SECTION', 'extensions');
	define('PUN_PAGE', 'admin-extensions-install');
	require PUN_ROOT.'header.php';

?>
<div id="pun-main" class="main sectioned admin">

<?php echo generate_admin_menu(); ?>

	<div class="main-head">
		<h1><span>{ <?php echo end($pun_page['crumbs']) ?> }</span></h1>
	</div>

	<div class="main-content frm">
		<div class="frm-head">
			<h2><span><?php echo end($pun_page['crumbs']) ?> "<?php echo pun_htmlencode($ext_data['extension']['title']) ?>"</span></h2>
		</div>
		<form class="frm-form" method="post" accept-charset="utf-8" action="<?php echo $base_url.'/admin/extensions.php'.(isset($_GET['install']) ? '?install=' : '?install_hotfix=').$id ?>">
			<div class="hidden">
				<input type="hidden" name="csrf_token" value="<?php echo generate_form_token($base_url.'/admin/extensions.php'.(isset($_GET['install']) ? '?install=' : '?install_hotfix=').$id) ?>" />
			</div>
			<div class="ext-item databox">
				<h3 class="legend"><span><?php echo pun_htmlencode($ext_data['extension']['title']).((strpos($id, 'hotfix_') !== 0) ? ' v'.$ext_data['extension']['version'] : '') ?></span></h3>
				<p><span><?php printf($lang_admin['Extension by'], $ext_data['extension']['author']) ?></span><br /><span><?php echo pun_htmlencode($ext_data['extension']['description']) ?></span></p>
<?php

	// Setup an array of warnings to display in the form
	$form_warnings = array();
	$pun_page['num_items'] = 0;

	foreach ($ext_data['extension']['note'] as $cur_note)
	{
		if ($cur_note['attributes']['type'] == 'install')
			$form_warnings[] = '<p>'.++$pun_page['num_items'].'. '.pun_htmlencode($cur_note['content']).'</p>';
	}

	if (version_compare(clean_version($pun_config['o_cur_version']), clean_version($ext_data['extension']['maxtestedon']), '>'))
		$form_warnings[] = '<p>'.++$pun_page['num_items'].'. '.$lang_admin['Maxtestedon warning'].'</p>';

	if (!empty($form_warnings))
	{

?>
				<h4 class="note"><?php echo $lang_admin['Install note'] ?></h4>
<?php

		echo implode("\n\t\t\t\t\t", $form_warnings)."\n";
	}

?>
			</div>
			<div class="frm-buttons">
				<span class="submit"><input type="submit" name="install_comply" value="<?php echo ((strpos($id, 'hotfix_') !== 0) ? $lang_admin['Install extension'] : $lang_admin['Install hotfix']) ?>" /></span>
				<span class="cancel"><input type="submit" name="install_cancel" value="<?php echo $lang_admin['Cancel'] ?>" /></span>
			</div>
		</form>
	</div>

</div>
<?php

	require PUN_ROOT.'footer.php';
}


// Uninstall an extension
else if (isset($_GET['uninstall']))
{
	// User pressed the cancel button
	if (isset($_POST['uninstall_cancel']))
		redirect(pun_link($pun_url['admin_extensions_manage']), $lang_admin['Cancel redirect']);

	($hook = get_hook('aex_uninstall_selected')) ? eval($hook) : null;

	$id = preg_replace('/[^0-9a-z_]/', '', $_GET['uninstall']);

	// Fetch info about the extension
	$query = array(
		'SELECT'	=> 'e.title, e.version, e.description, e.author, e.uninstall, e.uninstall_note',
		'FROM'		=> 'extensions AS e',
		'WHERE'		=> 'e.id=\''.$pun_db->escape($id).'\''
	);

	($hook = get_hook('aex_qr_get_extension')) ? eval($hook) : null;
	$result = $pun_db->query_build($query) or error(__FILE__, __LINE__);
	if (!$pun_db->num_rows($result))
		message($lang_common['Bad request']);

	$ext_data = $pun_db->fetch_assoc($result);

	// Setup breadcrumbs
	$pun_page['crumbs'] = array(
		array($pun_config['o_board_title'], pun_link($pun_url['index'])),
		array($lang_admin['Forum administration'], pun_link($pun_url['admin_index'])),
		array($lang_admin['Manage extensions'], pun_link($pun_url['admin_extensions_manage'])),
		$lang_admin['Uninstall extension']
	);

	// If the user has confirmed the uninstall
	if (isset($_POST['uninstall_comply']))
	{
		($hook = get_hook('aex_uninstall_comply_form_submitted')) ? eval($hook) : null;

		$notices = array();

		// Run uninstall code
		eval($ext_data['uninstall']);

		// Now delete the extension and its hooks from the db
		$query = array(
			'DELETE'	=> 'extension_hooks',
			'WHERE'		=> 'extension_id=\''.$pun_db->escape($id).'\''
		);

		($hook = get_hook('aex_qr_uninstall_delete_hooks')) ? eval($hook) : null;
		$pun_db->query_build($query) or error(__FILE__, __LINE__);

		$query = array(
			'DELETE'	=> 'extensions',
			'WHERE'		=> 'id=\''.$pun_db->escape($id).'\''
		);

		($hook = get_hook('aex_qr_delete_extension')) ? eval($hook) : null;
		$pun_db->query_build($query) or error(__FILE__, __LINE__);

		// Empty the PHP cache
		$d = dir(PUN_CACHE_DIR);
		while (($entry = $d->read()) !== false)
		{
			if (substr($entry, strlen($entry)-4) == '.php')
				@unlink(PUN_CACHE_DIR.$entry);
		}
		$d->close();

		// Regenerate the hooks cache
		require_once PUN_ROOT.'include/cache.php';
		generate_hooks_cache();

		// Display notices if there are any
		if (!empty($notices))
		{
			($hook = get_hook('aex_uninstall_notices_pre_header_load')) ? eval($hook) : null;

			define('PUN_PAGE_SECTION', 'extensions');
			define('PUN_PAGE', 'admin-extensions-manage');
			require PUN_ROOT.'header.php';

?>
<div id="pun-main" class="main sectioned admin">

<?php echo generate_admin_menu(); ?>

	<div class="main-head">
		<h1><span>{ <?php echo end($pun_page['crumbs']) ?> }</span></h1>
	</div>

	<div class="main-content frm">
		<div class="frm-head">
			<h2><span><?php echo end($pun_page['crumbs']) ?> "<?php echo pun_htmlencode($ext_data['title']) ?>"</span></h2>
		</div>
		<div class="frm-info">
			<p><?php echo $lang_admin['Extension uninstalled info'] ?></p>
			<ul>
<?php

			while (list(, $cur_notice) = each($notices))
				echo "\t\t\t\t".'<li><span>'.$cur_notice.'</span></li>'."\n";

?>
			</ul>
			<p><a href="<?php echo pun_link($pun_url['admin_extensions_manage']) ?>"><?php echo $lang_admin['Manage extensions'] ?></a></p>
		</div>
	</div>

</div>
<?php

			require PUN_ROOT.'footer.php';
		}
		else
			redirect(pun_link($pun_url['admin_extensions_manage']), $lang_admin['Extension uninstalled'].' '.$lang_admin['Redirect']);
	}
	else	// If the user hasn't confirmed the uninstall
	{
		($hook = get_hook('aex_uninstall_pre_header_loaded')) ? eval($hook) : null;

		define('PUN_PAGE_SECTION', 'extensions');
		define('PUN_PAGE', 'admin-extensions-manage');
		require PUN_ROOT.'header.php';

?>
<div id="pun-main" class="main sectioned admin">

<?php echo generate_admin_menu(); ?>

	<div class="main-head">
		<h1><span>{ <?php echo end($pun_page['crumbs']) ?> }</span></h1>
	</div>

	<div class="main-content frm">
		<div class="frm-head">
			<h2><span><?php echo end($pun_page['crumbs']) ?> "<?php echo pun_htmlencode($ext_data['title']) ?>"</span></h2>
		</div>
		<form class="frm-form" method="post" accept-charset="utf-8" action="<?php echo $base_url ?>/admin/extensions.php?section=manage&amp;uninstall=<?php echo $id ?>">
			<div class="hidden">
				<input type="hidden" name="csrf_token" value="<?php echo generate_form_token($base_url.'/admin/extensions.php?section=manage&amp;uninstall='.$id) ?>" />
			</div>
			<div class="ext-item databox">
				<h3 class="legend"><span><?php echo pun_htmlencode($ext_data['title']).((strpos($id, 'hotfix_') !== 0) ? ' v'.$ext_data['version'] : '') ?></span></h3>
				<p><span><?php printf($lang_admin['Extension by'], $ext_data['author']) ?></span><br /><span><?php echo pun_htmlencode($ext_data['description']) ?></span></p>
<?php if ($ext_data['uninstall_note'] != ''): ?>				<h4><?php echo $lang_admin['Uninstall note'] ?></h4>
				<p><?php echo pun_htmlencode($ext_data['uninstall_note']) ?></p>
<?php endif; ?>			</div>
			<div class="frm-info">
				<p class="warn"><?php echo $lang_admin['Installed extensions warn'] ?></p>
			</div>
			<div class="frm-buttons">
				<span class="submit"><input type="submit" class="button" name="uninstall_comply" value="<?php echo $lang_admin['Uninstall'] ?>" /></span>
				<span class="cancel"><input type="submit" class="button" name="uninstall_cancel" value="<?php echo $lang_admin['Cancel'] ?>" /></span>
			</div>
		</form>
	</div>

</div>
<?php

		require PUN_ROOT.'footer.php';
	}
}


// Enable or disable an extension
else if (isset($_GET['flip']))
{
	$id = preg_replace('/[^0-9a-z_]/', '', $_GET['flip']);

	// We validate the CSRF token. If it's set in POST and we're at this point, the token is valid.
	// If it's in GET, we need to make sure it's valid.
	if (!isset($_POST['csrf_token']) && (!isset($_GET['csrf_token']) || $_GET['csrf_token'] !== generate_form_token('flip'.$id)))
		csrf_confirm_form();

	($hook = get_hook('aex_flip_selected')) ? eval($hook) : null;

	// Fetch the current status of the extension
	$query = array(
		'SELECT'	=> 'e.disabled',
		'FROM'		=> 'extensions AS e',
		'WHERE'		=> 'e.id=\''.$pun_db->escape($id).'\''
	);

	($hook = get_hook('aex_qr_get_disabled_status')) ? eval($hook) : null;
	$result = $pun_db->query_build($query) or error(__FILE__, __LINE__);
	if (!$pun_db->num_rows($result))
		message($lang_common['Bad request']);

	// Are we disabling or enabling?
	$disable = $pun_db->result($result) == '0';

	$query = array(
		'UPDATE'	=> 'extensions',
		'SET'		=> 'disabled='.($disable ? '1' : '0'),
		'WHERE'		=> 'id=\''.$pun_db->escape($id).'\''
	);

	($hook = get_hook('aex_qr_update_disabled_status')) ? eval($hook) : null;
	$pun_db->query_build($query) or error(__FILE__, __LINE__);

	// Regenerate the hooks cache
	require_once PUN_ROOT.'include/cache.php';
	generate_hooks_cache();

	redirect(pun_link($pun_url['admin_extensions_manage']), ($disable ? $lang_admin['Extension disabled'] : $lang_admin['Extension enabled']).' '.$lang_admin['Redirect']);
}

($hook = get_hook('aex_new_action')) ? eval($hook) : null;


// Generate an array of installed extensions
$inst_exts = array();
$query = array(
	'SELECT'	=> 'e.*',
	'FROM'		=> 'extensions AS e',
	'ORDER BY'	=> 'e.title'
);

($hook = get_hook('aex_qr_get_all_extensions')) ? eval($hook) : null;
$result = $pun_db->query_build($query) or error(__FILE__, __LINE__);
while ($cur_ext = $pun_db->fetch_assoc($result))
	$inst_exts[$cur_ext['id']] = $cur_ext;


if ($section == 'install')
{
	// Setup breadcrumbs
	$pun_page['crumbs'] = array(
		array($pun_config['o_board_title'], pun_link($pun_url['index'])),
		array($lang_admin['Forum administration'], pun_link($pun_url['admin_index'])),
		$lang_admin['Install extensions']
	);

	($hook = get_hook('aex_section_install_pre_header_load')) ? eval($hook) : null;

	define('PUN_PAGE_SECTION', 'extensions');
	define('PUN_PAGE', 'admin-extensions-install');

	require PUN_ROOT.'header.php';

?>
<div id="pun-main" class="main sectioned admin">

<?php echo generate_admin_menu(); ?>

	<div class="main-head">
		<h1><span>{ <?php echo end($pun_page['crumbs']) ?> }</span></h1>
	</div>

	<div class="main-content frm">
		<div class="frm-head">
			<h2><span><?php echo $lang_admin['Extensions available'] ?></span></h2>
		</div>
<?php

	$num_exts = 0;
	$num_failed = 0;
	$pun_page['item_num'] = 1;
	$pun_page['ext_item'] = array();
	$pun_page['ext_error'] = array();

	// Loop through any available hotfixes
	if (isset($pun_updates['hotfix']))
	{
		// If there's only one hotfix, add one layer of arrays so we can foreach over it
		if (!is_array(current($pun_updates['hotfix'])))
			$pun_updates['hotfix'] = array($pun_updates['hotfix']);

		foreach ($pun_updates['hotfix'] as $hotfix)
		{
			if (!array_key_exists($hotfix['attributes']['id'], $inst_exts))
			{
				$pun_page['ext_item'][] = '<div class="hotfix-item databox">'."\n\t\t\t".'<h3 class="legend"><span>'.pun_htmlencode($hotfix['content']).'</span></h3>'."\n\t\t\t".'<p><span>'.sprintf($lang_admin['Extension by'], 'PunBB').'</span><br /><span>'.$lang_admin['Hotfix description'].'</span></p>'."\n\t\t\t".'<p class="actions"><a href="'.$base_url.'/admin/extensions.php?install_hotfix='.urlencode($hotfix['attributes']['id']).'">'.$lang_admin['Install hotfix'].'</a></p>'."\n\t\t".'</div>';
				++$num_exts;
			}
		}
	}

	$d = dir(PUN_ROOT.'extensions');
	while (($entry = $d->read()) !== false)
	{
		if ($entry{0} != '.' && is_dir(PUN_ROOT.'extensions/'.$entry))
		{
			if (preg_match('/[^0-9a-z_]/', $entry))
			{
				$pun_page['ext_error'][] = '<div class="ext-error databox db'.++$pun_page['item_num'].'">'."\n\t\t\t\t".'<h3 class="legend"><span>'.sprintf($lang_admin['Extension loading error'], pun_htmlencode($entry)).'<span></h3>'."\n\t\t\t\t".'<p>'.$lang_admin['Illegal ID'].'</p>'."\n\t\t\t".'</div>';
				++$num_failed;
				continue;
			}
			else if (!file_exists(PUN_ROOT.'extensions/'.$entry.'/manifest.xml'))
			{
				$pun_page['ext_error'][] = '<div class="ext-error databox db'.++$pun_page['item_num'].'">'."\n\t\t\t\t".'<h3 class="legend"><span>'.sprintf($lang_admin['Extension loading error'], pun_htmlencode($entry)).'<span></h3>'."\n\t\t\t\t".'<p>'.$lang_admin['Missing manifest'].'</p>'."\n\t\t\t".'</div>';
				++$num_failed;
				continue;
			}

			// Parse manifest.xml into an array
			$ext_data = xml_to_array(@file_get_contents(PUN_ROOT.'extensions/'.$entry.'/manifest.xml'));
			if (empty($ext_data))
			{
				$pun_page['ext_error'][] = '<div class="ext-error databox db'.++$pun_page['item_num'].'">'."\n\t\t\t\t".'<h3 class="legend"><span>'.sprintf($lang_admin['Extension loading error'], pun_htmlencode($entry)).'<span></h3>'."\n\t\t\t\t".'<p>'.$lang_admin['Failed parse manifest'].'</p>'."\n\t\t\t".'</div>';
				++$num_failed;
				continue;
			}

			// Validate manifest
			$errors = validate_manifest($ext_data, $entry);
			if (!empty($errors))
			{
				$pun_page['ext_error'][] = '<div class="ext-error databox db'.++$pun_page['item_num'].'">'."\n\t\t\t\t".'<h3 class="legend"><span>'.sprintf($lang_admin['Extension loading error'], pun_htmlencode($entry)).'</span></h3>'."\n\t\t\t\t".'<p>'.implode(' ', $errors).'</p>'."\n\t\t\t".'</div>';
				++$num_failed;
			}
			else
			{
				if (!array_key_exists($entry, $inst_exts) || version_compare($inst_exts[$entry]['version'], $ext_data['extension']['version'], '!='))
				{
					$pun_page['ext_item'][] = '<div class="ext-item databox">'."\n\t\t\t".'<h3 class="legend"><span>'.pun_htmlencode($ext_data['extension']['title']).' v'.$ext_data['extension']['version'].'</span></h3>'."\n\t\t\t".'<p><span>'.sprintf($lang_admin['Extension by'], pun_htmlencode($ext_data['extension']['author'])).'</span>'.(($ext_data['extension']['description'] != '') ? '<br /><span>'.pun_htmlencode($ext_data['extension']['description']).'</span>' : '').'</p>'."\n\t\t\t".'<p class="actions"><a href="'.$base_url.'/admin/extensions.php?install='.urlencode($entry).'">'.$lang_admin['Install extension'].'</a></p>'."\n\t\t".'</div>';
					++$num_exts;
				}
			}
		}
	}
	$d->close();

	($hook = get_hook('aex_section_install_pre_display_ext_list')) ? eval($hook) : null;

	if ($num_exts)
		echo "\t\t".implode("\n\t\t", $pun_page['ext_item'])."\n";
	else
	{

?>
		<div class="frm-info">
			<p><?php echo $lang_admin['No available extensions'] ?></p>
		</div>
<?php

	}

	// If any of the extensions had errors
	if ($num_failed)
	{

?>
		<div class="dataset">
			<div class="ext-error databox db1">
				<p class="important"><?php echo $lang_admin['Invalid extensions'] ?></p>
			</div>
			<?php echo implode("\n\t\t\t", $pun_page['ext_error'])."\n" ?>
		</div>
<?php

	}

?>
	</div>

</div>
<?php

	require PUN_ROOT.'footer.php';
}
else
{
	// Setup breadcrumbs
	$pun_page['crumbs'] = array(
		array($pun_config['o_board_title'], pun_link($pun_url['index'])),
		array($lang_admin['Forum administration'], pun_link($pun_url['admin_index'])),
		$lang_admin['Manage extensions']
	);

	($hook = get_hook('aex_section_manage_pre_header_load')) ? eval($hook) : null;

	define('PUN_PAGE_SECTION', 'extensions');
	define('PUN_PAGE', 'admin-extensions-manage');

	require PUN_ROOT.'header.php';

?>
<div id="pun-main" class="main sectioned admin">

<?php echo generate_admin_menu(); ?>

	<div class="main-head">
		<h1><span>{ <?php echo end($pun_page['crumbs']) ?> }</span></h1>
	</div>

	<div class="main-content frm">
		<div class="frm-head">
			<h2><span><?php echo $lang_admin['Installed extensions'] ?></span></h2>
		</div>
<?php

	if (!empty($inst_exts))
	{

?>
		<div class="frm-info">
			<p class="warn"><?php echo $lang_admin['Installed extensions warn'] ?></p>
		</div>
<?php

		while (list($id, $ext) = @each($inst_exts))
		{
			$pun_page['ext_actions'] = array(
				'<a href="'.$base_url.'/admin/extensions.php?section=manage&amp;flip='.$id.'&amp;csrf_token='.generate_form_token('flip'.$id).'">'.($ext['disabled'] != '1' ? $lang_admin['Disable'] : $lang_admin['Enable']).'</a>',
				'<a href="'.$base_url.'/admin/extensions.php?section=manage&amp;uninstall='.$id.'">'.$lang_admin['Uninstall'].'</a>'
			);

			($hook = get_hook('aex_section_manage_pre_ext_actions')) ? eval($hook) : null;

?>
		<div class="ext-item databox<?php if ($ext['disabled'] == '1') echo ' extdisabled' ?>">
			<h3 class="legend"><span><?php echo pun_htmlencode($ext['title']).((strpos($id, 'hotfix_') !== 0) ? ' v'.$ext['version'] : '') ?><?php if ($ext['disabled'] == '1') echo ' ( <span>'.$lang_admin['Extension disabled'].'</span> )' ?></span></h3>
			<p><span><?php printf($lang_admin['Extension by'], $ext['author']) ?></span><?php if ($ext['description'] != ''): ?><br /><span><?php echo pun_htmlencode($ext['description']) ?></span><?php endif; ?></p>
			<p class="actions"><?php echo implode('', $pun_page['ext_actions']) ?></p>
		</div>
<?php

		}
	}
	else
	{

?>
		<div class="frm-info">
			<p><?php echo $lang_admin['No installed extensions'] ?></p>
		</div>
<?php

	}

?>
	</div>

</div>
<?php

	require PUN_ROOT.'footer.php';
}

($hook = get_hook('aex_end')) ? eval($hook) : null;
