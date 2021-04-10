<?php

if (!defined("IN_MYBB")) {
	die("Nice try but wrong place, smartass. Be a good boy and use navigation.");
}

if (defined('IN_ADMINCP')) {
	function markpost_info()
	{
		global $lang;
		$lang->load('markpost');
		return array(
			'name' => $lang->markpost_title,
			'description' => $lang->markpost_desc,
			'website' => 'https://mybb.group/Thread-Bookmark-Post',
			'author' => 'effone</a>, <a href="https://creativeandcritical.net">Laird</a> & <a href="https://ougc.network">Omar G.',
			'authorsite' => 'https://eff.one',
			'version' => '1.0.0',
			'compatibility' => '18*',
			'codename' => 'markpost',
		);
	}

	function markpost_activate()
	{
		require MYBB_ROOT . "inc/adminfunctions_templates.php";
		find_replace_templatesets('postbit_posturl', '#<strong><a href=#', '<!-- markpost -->{$post[\'mark\']}<!-- /markpost --><strong><a href=');
	}

	function markpost_deactivate()
	{
		require MYBB_ROOT . "inc/adminfunctions_templates.php";
		find_replace_templatesets('postbit_posturl', '#\<!--\smarkpost\s--\>(.+)\<!--\s\/markpost\s--\>#is', '', 0);
	}

	function markpost_install()
	{
		global $db, $lang;
		$lang->load('markpost');

		// Install templates
		$templates = array();
		foreach (glob(MYBB_ROOT . 'inc/plugins/markpost/*.htm') as $template) {
			$templates[] = array(
				'title' => $db->escape_string(strtolower(basename($template, '.htm'))),
				'template' => $db->escape_string(@file_get_contents($template)),
				'sid' => -2,
				'version' => 100,
				'dateline' => TIME_NOW,
			);
		}
		if (!empty($templates)) {
			$db->insert_query_multiple('templates', $templates);
		}

		$db->query(
			"CREATE TABLE IF NOT EXISTS {$db->table_prefix}markpost (
			mid int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
			uid int(10) UNSIGNED NOT NULL,
			pid int(10) UNSIGNED NOT NULL,
			tid int(10) UNSIGNED NOT NULL,
			notes varchar(400) NOT NULL default '',
			dateline int(10) UNSIGNED NOT NULL,
			PRIMARY KEY (`mid`),
			INDEX (`uid`, `dateline`)
			);"
		);

		// Build Plugin Settings
		$markpost_group = array(
			"name" => "markpost",
			"title" => $lang->markpost_title,
			"description" => $lang->markpost_desc,
			"disporder" => "9",
			"isdefault" => "0",
		);
		$db->insert_query("settinggroups", $markpost_group);
		$gid = $db->insert_id();
		$markpost_opts = array(
			['per_page', 'numeric', '8'],
			['force_redirect', 'onoff', '1']
		);
		$disporder = 0;
		$markpost_settings = array();

		foreach ($markpost_opts as $markpost_opt) {
			$markpost_opt[0] = 'markpost_' . $markpost_opt[0];
			$markpost_opt = array_combine(['name', 'optionscode', 'value'], $markpost_opt);
			$markpost_opt['title'] = $lang->{$markpost_opt['name'] . "_title"};
			$markpost_opt['description'] = $lang->{$markpost_opt['name'] . "_desc"};
			$markpost_opt['disporder'] = $disporder++;
			$markpost_opt['gid'] = intval($gid);
			$markpost_settings[] = $markpost_opt;
		}
		$db->insert_query_multiple('settings', $markpost_settings);
		rebuild_settings();
	}

	function markpost_is_installed()
	{
		global $db;
		$query = $db->simple_select("settinggroups", "gid", "name='markpost'");
		$gid = $db->fetch_field($query, "gid");

		return !empty($gid);
	}

	function markpost_uninstall()
	{
		global $mybb, $db;

		if ($db->table_exists('markpost')) {
			if ($mybb->request_method != 'post') {
				global $page, $lang;
				$lang->load('markpost');
				$page->output_confirm_action('index.php?module=config-plugins&action=deactivate&uninstall=1&plugin=markpost', $lang->markpost_uninstall_message, $lang->markpost_uninstall);
			} else {
				// Only remove the database tables if the admin has selected NOT to keep data.
				if (!isset($mybb->input['no'])) {
					$db->query("drop TABLE {$db->table_prefix}markpost");
				}

				$db->delete_query("settings", "name LIKE '%markpost%'");
				$db->delete_query("settinggroups", "name='markpost'");

				// Delete Templates
				$templates = array();
				foreach (glob(MYBB_ROOT . 'inc/plugins/markpost/*.htm') as $template) {
					$templates[] = "'" . strtolower(basename($template, '.htm')) . "'";
				}
				if (!empty($templates)) $db->delete_query('templates', 'title IN (' . implode(',', $templates) . ')');
			}
		}
	}
} else {
	$plugins->add_hook('postbit', 'markpost_stamp');
	$plugins->add_hook('showthread_start', 'markpost_commit');
	$plugins->add_hook('usercp_menu', 'markpost_ucp_nav', 35);
	$plugins->add_hook('usercp_start', 'markpost_listdown');
	$plugins->add_hook('global_start', 'markpost_template_cache');

	function markpost_stamp(&$post)
	{
		global $mybb;
		if ((int)$post['pid'] > 0 & (int)$mybb->user['uid'] > 0 && (int)$post['tid'] > 0) {
			global $db, $templates, $lang, $postcounter;

			$lang->load('markpost');
			static $postmarked = array();
			if (!isset($postmarked[$mybb->user['uid']][$post['tid']])) {
				$markdata = array();
				$query = $db->simple_select('markpost', 'pid', 'uid=' . $mybb->user['uid'] . ' AND tid=' . $post['tid']);
				while ($marked = $db->fetch_array($query)) {
					$markdata[] = $marked['pid'];
				}

				// Remove any unwanted duplicate entries from  array as well as database
				$dupes = array_unique(array_intersect($markdata, array_unique(array_diff_key($markdata, array_unique($markdata)))));
				if (!empty($dupes)) {
					foreach ($dupes as $dupe) {
						$db->query(
							"DELETE FROM {$db->table_prefix}markpost WHERE uid=" . $mybb->user['uid'] . " AND pid={$dupe} and mid not in
							( SELECT * FROM 
								(SELECT MIN(mid) FROM {$db->table_prefix}markpost WHERE uid={$mybb->user['uid']} AND pid={$dupe}) AS temp
							)"
						);
					}
				}
				$postmarked[$mybb->user['uid']][$post['tid']] = array_unique($markdata);
			} else {
				$markdata = $postmarked[$mybb->user['uid']][$post['tid']];
			}

			$un = (in_array($post['pid'], $markdata)) ? 'un' : '';
			$post_number = my_number_format($postcounter);
			$marktext = $lang->{'markpost_' . $un . 'mark_text'};
			$marktip = $lang->{'markpost_' . $un . 'mark_tip'};

			eval("\$post['mark'] = \"" . $templates->get("postbit_marklink") . "\";");
			eval("\$post['posturl'] = \"" . $templates->get("postbit_posturl") . "\";"); // Stupid hook is late. Regenerate
		}
	}

	function markpost_commit()
	{
		global $mybb, $lang, $db;

		$mybb->input['action'] = $mybb->get_input('action');
		if ($mybb->input['action'] == "mark" || $mybb->input['action'] == "unmark") {
			$uid = $mybb->user['uid'];
			$tid = $mybb->get_input('tid', MyBB::INPUT_INT);
			$pid = $mybb->get_input('pid', MyBB::INPUT_INT);

			if (!$uid || !$tid || !$pid) {
				error_no_permission();
			}
			if ($mybb->input['action'] == 'mark') {
				$db->insert_query('markpost', array('pid' => $pid, 'uid' => $uid, 'tid' => $tid, 'dateline' => TIME_NOW));
			} else {
				$db->delete_query("markpost", "pid='{$pid}' AND uid='{$uid}'");
			}
			if ($db->affected_rows()) {
				$state = 'success';
			} else {
				$state = 'failure';
			}

			$lang->load('markpost');
			redirect("showthread.php?tid={$tid}&pid={$pid}#pid{$pid}", $lang->{'markpost_' . $mybb->input['action'] . $state . '_message'}, '', (bool)$mybb->settings['markpost_force_redirect']);
		}
	}

	function markpost_ucp_nav()
	{
		if (markpost_has_marked()) {
			global $usercpmenu, $templates, $lang;
			$lang->load("markpost");
			eval("\$navitem = \"" . $templates->get("usercp_nav_postmarks") . "\";");
			$usercpmenu = preg_replace('~(.*)' . preg_quote('</', '~') . '~su', '${1}' . $navitem . '</', $usercpmenu);
		}
	}

	function markpost_has_marked($return_count = false, $uid = 0)
	{
		if (!$uid) {
			global $mybb;
			if ($mybb->user['uid'] > 0) {
				$uid = $mybb->user['uid'];
			} else {
				error_no_permission();
			}
		}

		// Build where clause
		$where = markpost_build_clause($uid);
		global $db;

		$select = $return_count ? "COUNT(m.mid) AS marked" : "DISTINCT 1";
		$query = $db->simple_select(
			"markpost m LEFT JOIN {$db->table_prefix}threads t ON (m.tid=t.tid) LEFT JOIN {$db->table_prefix}posts p ON (m.pid=p.pid)",
			$select,
			$where
		);
		return $return_count ? $db->fetch_field($query, "marked") : !empty($db->fetch_array($query));
	}

	function markpost_build_clause($uid)
	{
		global $mybb;

		// Build where clause
		$where = ["m.uid='{$uid}'"];
		$visible_states = [1];

		if (is_moderator(0, 'canviewdeleted', $uid)) {
			$visible_states[] = -1;
		}

		if (is_moderator(0, 'canviewunapprove', $uid)) {
			$visible_states[] = 0;
		}

		$visible_states = implode(',', $visible_states);
		$where[] = "t.visible IN ({$visible_states})";
		$where[] = "p.visible IN ({$visible_states})";

		// not required to check for uid really but markpost_has_marked() allows for custom uid, so to be consistent with current code.. note that get_unviewable_forums() always checks for the current user
		if ((int)$uid === (int)$mybb->user['uid'] && $unviewable_forums = get_unviewable_forums()) {
			$where[] = "t.fid NOT IN ({$unviewable_forums})";
		}

		if ($inactiveforums = get_inactive_forums()) {
			$where[] = "t.fid NOT IN ({$inactiveforums})";
		}

		$onlyusfids = array();
		$group_permissions = forum_permissions();
		foreach ($group_permissions as $fid => $forum_permissions) {
			if ($forum_permissions['canonlyviewownthreads'] == 1) {
				$onlyusfids[] = $fid;
			}
		}
		if ($onlyusfids) {
			$where[] = '(t.fid IN(' . implode(',', $onlyusfids) . ') AND t.uid="' . $uid . '" OR t.fid NOT IN(' . implode(',', $onlyusfids) . '))';
		}

		return implode(' AND ', $where);
	}

	function markpost_listdown()
	{
		global $mybb, $lang;
		if ($mybb->get_input('action') == "postmarks") {
			$lang->load('markpost');
			$marked = markpost_has_marked(true);

			if ($marked > 0) {
				global $db, $templates, $theme, $header, $footer, $headerinclude, $usercpnav, $parser;
				add_breadcrumb($lang->nav_usercp, "usercp.php");
				add_breadcrumb($lang->markpost_title);

				$postmarks = "";
				$alt_row = alt_trow(true);
				$perpage = (int)$mybb->settings['markpost_per_page'];
				if (empty($perpage) || $perpage < 1) $perpage = 8;
				$pagenum = $mybb->get_input('page', MyBB::INPUT_INT);
				if ($pagenum > 0) $offset = ($pagenum - 1) * $perpage;
				if ($pagenum <= 0 || $pagenum > ceil($marked / $perpage)) // Reset range out
				{
					$offset = 0;
					$pagenum = 1;
				}

				// Build where clause
				$where = markpost_build_clause($mybb->user['uid']);
				$query = $db->query("
					SELECT p.uid as poster, p.subject, p.message as post, p.dateline as postdate,
					t.uid as originator, t.dateline as threaddate, t.subject as tsubject, t.fid, f.name as forum,
					m.pid, m.tid, m.dateline as markdate
					FROM {$db->table_prefix}markpost m
					LEFT JOIN {$db->table_prefix}posts p on (m.pid = p.pid)
					LEFT JOIN {$db->table_prefix}threads t on (m.tid = t.tid)
					LEFT JOIN {$db->table_prefix}forums f on (t.fid = f.fid)
					WHERE {$where}
					ORDER BY m.dateline DESC
					LIMIT {$perpage} OFFSET {$offset}
				");

				while ($mark = $db->fetch_array($query)) {
					foreach (['mark', 'post', 'thread'] as $stamp) {
						$mark[$stamp . 'date'] = my_date('relative', $mark[$stamp . 'date']);
					}

					foreach (['subject', 'tsubject'] as $subj) {
						$trail = (my_strlen($mark[$subj]) > 50) ? "..." : "";
						$mark[$subj] = htmlspecialchars_uni(my_substr($mark[$subj], 0, 50) . $trail);
					}

					$mark['poster'] = get_user($mark['poster']);
					$parser_options = array(
						'allow_html' => 0,
						'allow_mycode' => 1,
						'allow_smilies' => 0,
						'allow_imgcode' => 0,
						'me_username' => $mark['poster']['username'],
						'filter_badwords' => 1
					);
					$mark['post'] = strip_tags($parser->text_parse_message($mark['post'], $parser_options));
					if (my_strlen($mark['post']) > 160) {
						$mark['post'] = my_substr($mark['post'], 0, 160) . "...";
					}
					$mark['poster'] = build_profile_link(format_name(htmlspecialchars_uni($mark['poster']['username']), $mark['poster']['usergroup'], $mark['poster']['displaygroup']), $mark['poster']['uid']);

					$mark['originator'] = get_user($mark['originator']);
					$mark['originator'] = build_profile_link(format_name(htmlspecialchars_uni($mark['originator']['username']), $mark['originator']['usergroup'], $mark['originator']['displaygroup']), $mark['originator']['uid']);
					eval("\$postmarks .= \"" . $templates->get("usercp_postmarks_post") . "\";");
					$alt_row = alt_trow();
				}

				$multipage = multipage($marked, $perpage, $pagenum, "usercp.php?action=postmarks");
				eval("\$postmarks_data = \"" . $templates->get("usercp_postmarks") . "\";");
				output_page($postmarks_data);
			} else {
				error($lang->markpost_nomarked_found, $lang->markpost_nomatk_title);
			}
		}
	}

	function markpost_template_cache()
	{
		global $db, $templatelist;
		if (!isset($templatelist)) {
			$templatelist = '';
		} else {
			$templatelist .= ', ';
		}
		$templatelist .= implode(', ', array_map(function ($tpl) use ($db) {
			return $db->escape_string(strtolower(basename($tpl, '.htm')));
		}, (glob(MYBB_ROOT . "inc/plugins/markpost/*.htm"))));
	}
}
