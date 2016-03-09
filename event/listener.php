<?php
/**
*
* @package No Duplicate phpBB SEO
* @version $Id$
* @copyright (c) 2014 www.phpbb-seo.com
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

namespace phpbbseo\related\event;

/**
* @ignore
*/
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
* Event listener
*/
class listener implements EventSubscriberInterface
{
	/** @var \phpbbseo\usu\core */
	protected $usu_core;

	/* @var \phpbb\user */
	protected $user;

	/* @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\auth\auth */
	protected $auth;

	/* @var \phpbb\template\template */
	protected $template;

	/* @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\cache\service */
	protected $cache;

	/**
	* Current $phpbb_root_path
	* @var string
	*/
	protected $phpbb_root_path;

	/**
	* Current $php_ext
	* @var string
	*/
	protected $php_ext;

	/* @var \phpbb\content_visibility */
	protected $content_visibility;

	/* @var \phpbb\pagination */
	protected $pagination;

	protected $posts_per_page = 1;

	protected $usu_rewrite = false;

	protected $forum_exclude = array();

	protected $fulltext = false;

	/* Limit in chars for the last post link text. */
	protected $char_limit = 25;

	/**
	* Constructor
	*
	* @param \phpbb\config\config			$config				Config object
	* @param \phpbb\auth\auth			$auth				Auth object
	* @param \phpbb\template\template		$template			Template object
	* @param \phpbb\user				$user				User object
	* @param \phpbb\cache\service			$cache				Cache driver
	* @param \phpbb\db\driver\driver_interface	$db				Database object
	* @param string					$phpbb_root_path		Path to the phpBB root
	* @param string					$php_ext			PHP file extension
	* @param \phpbbseo\usu\core			$usu_core			usu core object
	*/
	public function __construct(\phpbb\config\config $config, \phpbb\auth\auth $auth, \phpbb\template\template $template, \phpbb\user $user, \phpbb\cache\service $cache, \phpbb\db\driver\driver_interface $db, $phpbb_root_path, $php_ext, \phpbbseo\usu\core $usu_core = null)
	{
		global $phpbb_container; // god save the hax

		$this->config = $config;
		$this->usu_core = $usu_core;
		$this->usu_rewrite = !empty($this->config['seo_usu_on']) && !empty($usu_core) && !empty($this->usu_core->seo_opt['sql_rewrite']) ? true : false;

		$this->user = $user;
		$this->auth = $auth;
		$this->cache = $cache;
		$this->db = $db;
		$this->template = $template;

		$this->content_visibility = $phpbb_container->get('content.visibility');
		$this->pagination = $phpbb_container->get('pagination');
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $php_ext;

		$this->posts_per_page = $this->config['posts_per_page'];

		//  better to always check, since it's fast
		if ($this->db->get_sql_layer() != 'mysql4' && $this->db->get_sql_layer() != 'mysqli')
		{
			$this->fulltext = false;
		}
		else
		{
			$this->fulltext = !empty($this->config['seo_related_fulltext']);
		}
	}

	static public function getSubscribedEvents()
	{
		return array(
			'core.viewtopic_modify_page_title' => 'core_viewtopic_modify_page_title',
		);
	}

	public function core_viewtopic_modify_page_title($event)
	{
		global $topic_tracking_info;

		$topic_data = $event['topic_data'];
		$forum_id = $event['forum_id'];

		$related_result = false;
		$enable_icons = 0;
		$allforums = !$forum_id ? true : !empty($this->config['seo_related_allforums']);
		$limit = max(1, !empty($this->config['seo_related_limit']) ? (int) $this->config['seo_related_limit'] : 1);
		$sql = $this->build_query($topic_data, $forum_id);

		if ($sql && ($result = $this->db->sql_query_limit($sql, $limit)))
		{
			// Grab icons
			$icons = $this->cache->obtain_icons();
			$attachement_icon = $this->user->img('icon_topic_attach', $this->user->lang['TOTAL_ATTACHMENTS']);
			$s_attachement = $this->auth->acl_get('u_download');
			$last_pages = array();
			$has_at_least_one_icon = false;

			while($row = $this->db->sql_fetchrow($result))
			{
				$related_forum_id = (int) $row['forum_id'];
				$related_topic_id = (int) $row['topic_id'];
				$enable_icons = max($enable_icons, $row['enable_icons']);

				if ($this->auth->acl_get('f_list', $related_forum_id))
				{
					$row['topic_title'] = censor_text($row['topic_title']);

					if ($this->usu_rewrite)
					{
						$this->usu_core->set_url($row['forum_name'], $related_forum_id, $this->usu_core->seo_static['forum']);
						$this->usu_core->prepare_iurl($row, 'topic', $row['topic_type'] == POST_GLOBAL ? $this->usu_core->seo_static['global_announce'] : $this->usu_core->seo_url['forum'][$related_forum_id]);
					}

					// Replies
					$replies = $this->content_visibility->get_count('topic_posts', $row, $related_forum_id) - 1;
					$unread_topic = (isset($topic_tracking_info[$related_topic_id]) && $row['topic_last_post_time'] > $topic_tracking_info[$related_topic_id]) ? true : false;
					$view_topic_url = append_sid("{$this->phpbb_root_path}viewtopic.$this->php_ext", "f=$related_forum_id&amp;t=$related_topic_id");
					$topic_unapproved = (($row['topic_visibility'] == ITEM_UNAPPROVED || $row['topic_visibility'] == ITEM_REAPPROVE) && $this->auth->acl_get('m_approve', $row['forum_id']));
					$posts_unapproved = ($row['topic_visibility'] == ITEM_APPROVED && $row['topic_posts_unapproved'] && $this->auth->acl_get('m_approve', $row['forum_id']));
					$topic_deleted = $row['topic_visibility'] == ITEM_DELETED;

					$u_mcp_queue = ($topic_unapproved || $posts_unapproved) ? append_sid("{$this->phpbb_root_path}mcp.$this->php_ext", 'i=queue&amp;mode=' . (($topic_unapproved) ? 'approve_details' : 'unapproved_posts') . "&amp;t=$related_topic_id", true, $this->user->session_id) : '';
					$u_mcp_queue = (!$u_mcp_queue && $topic_deleted) ? append_sid("{$this->phpbb_root_path}mcp.$this->php_ext", 'i=queue&amp;mode=deleted_topics&amp;t=' . $related_topic_id, true, $this->user->session_id) : $u_mcp_queue;

					// Get folder img, topic status/type related information
					$folder_img = $folder_alt = $topic_type = '';
					topic_status($row, $replies, $unread_topic, $folder_img, $folder_alt, $topic_type);

					$_start = '';
					if (!empty($this->config['seo_no_dupe_on']))
					{
						if (($replies + 1) > $this->posts_per_page)
						{
							$_start = floor($replies / $this->posts_per_page) * $this->posts_per_page;
							$_start = $_start ? "&amp;start=$_start" : '';
						}
						$u_last_post = append_sid("{$this->phpbb_root_path}viewtopic.$this->php_ext", "f=$related_forum_id&amp;t=$related_topic_id$_start") . '#p' . $row['topic_last_post_id'];
					}
					else
					{
						$u_last_post = append_sid("{$this->phpbb_root_path}viewtopic.$this->php_ext", "f=$related_forum_id&amp;t=$related_topic_id&amp;p=" . $row['topic_last_post_id']) . '#p' . $row['topic_last_post_id'];
					}
					$topic_icon_img = $topic_icon_img_width = $topic_icon_img_height = '';
					if (!empty($icons[$row['icon_id']]))
					{
						$topic_icon_img = $icons[$row['icon_id']]['img'];
						$topic_icon_img_width = $icons[$row['icon_id']]['width'];
						$topic_icon_img_height = $icons[$row['icon_id']]['height'];
						$has_at_least_one_icon = true;
					}

					$this->template->assign_block_vars('related', array(
						'TOPIC_TITLE'			=> $row['topic_title'],
						'U_TOPIC'			=> $view_topic_url,
						'U_FORUM'			=> $allforums ? append_sid("{$this->phpbb_root_path}viewforum.$this->php_ext", "f=$related_forum_id") : '',
						'FORUM_NAME'			=> $row['forum_name'],
						'REPLIES'			=> $replies,
						'VIEWS'				=> $row['topic_views'],
						'FIRST_POST_TIME'		=> $this->user->format_date($row['topic_time']),
						'LAST_POST_TIME'		=> $this->user->format_date($row['topic_last_post_time']),
						'TOPIC_AUTHOR_FULL'		=>  get_username_string('full', $row['topic_poster'], $row['topic_first_poster_name'], $row['topic_first_poster_colour']),
						'LAST_POST_AUTHOR_FULL'		=>  get_username_string('full', $row['topic_last_poster_id'], $row['topic_last_poster_name'], $row['topic_last_poster_colour']),
						'U_LAST_POST'			=> $u_last_post,
						'TOPIC_IMG_STYLE'		=> $folder_img,
						'TOPIC_FOLDER_IMG_SRC'		=> $this->user->img($folder_img, $folder_alt, false, '', 'src'),
						'TOPIC_FOLDER_IMG'		=> $this->user->img($folder_img, $folder_alt, false),
						'TOPIC_FOLDER_IMG_ALT'		=> $this->user->lang[$folder_alt],
						'TOPIC_ICON_IMG'		=> $topic_icon_img,
						'TOPIC_ICON_IMG_WIDTH'		=> $topic_icon_img_width,
						'TOPIC_ICON_IMG_HEIGHT'		=> $topic_icon_img_height,
						'UNAPPROVED_IMG'		=> ($topic_unapproved || $posts_unapproved) ? $this->user->img('icon_topic_unapproved', ($topic_unapproved) ? 'TOPIC_UNAPPROVED' : 'POSTS_UNAPPROVED') : '',
						'ATTACH_ICON_IMG'		=> ($row['topic_attachment'] && $s_attachement) ? $attachement_icon : '',
						'S_TOPIC_REPORTED'		=> (!empty($row['topic_reported']) && $this->auth->acl_get('m_report', $related_forum_id)) ? true : false,
						'S_UNREAD_TOPIC'		=> $unread_topic,
						'S_POST_ANNOUNCE'		=> ($row['topic_type'] == POST_ANNOUNCE) ? true : false,
						'S_POST_GLOBAL'			=> ($row['topic_type'] == POST_GLOBAL) ? true : false,
						'S_POST_STICKY'			=> ($row['topic_type'] == POST_STICKY) ? true : false,
						'S_TOPIC_LOCKED'		=> ($row['topic_status'] == ITEM_LOCKED) ? true : false,
						'S_TOPIC_UNAPPROVED'		=> $topic_unapproved,
						'S_POSTS_UNAPPROVED'		=> $posts_unapproved,
						'S_HAS_POLL'			=> ($row['poll_start']) ? true : false,
						'S_TOPIC_DELETED'		=> $topic_deleted,
						'U_MCP_REPORT'			=> append_sid("{$this->phpbb_root_path}mcp.$this->php_ext", 'i=reports&amp;mode=reports&amp;f=' . $related_forum_id . '&amp;t=' . $related_topic_id, true, $this->user->session_id),
						'U_MCP_QUEUE'			=> $u_mcp_queue,
					));

					$this->pagination->generate_template_pagination($view_topic_url, 'related.pagination', 'start', $replies + 1, $this->posts_per_page, 1, true, true);
					$related_result = true;
				}
			}
			if (!isset($this->user->lang['RELATED_TOPICS'])){
				$this->user->lang['RELATED_TOPICS'] = 'Related Topics';
				if ($this->config['default_lang'] == 'de'){
					$this->user->lang['RELATED_TOPICS'] = 'Ähnliche Beiträge';
				}
			}

			$this->db->sql_freeresult($result);
		}

		if ($related_result)
		{
			$this->template->assign_vars(array(
				'S_RELATED_RESULTS'	=> $related_result,
				'LAST_POST_IMG'		=> $this->user->img('icon_topic_latest', 'VIEW_LATEST_POST'),
				'NEWEST_POST_IMG'	=> $this->user->img('icon_topic_newest', 'VIEW_NEWEST_POST'),
				'REPORTED_IMG'		=> $this->user->img('icon_topic_reported', 'TOPIC_REPORTED'),
				'UNAPPROVED_IMG'	=> $this->user->img('icon_topic_unapproved', 'TOPIC_UNAPPROVED'),
				'DELETED_IMG'		=> $this->user->img('icon_topic_deleted', 'TOPIC_DELETED'),
				'GOTO_PAGE_IMG'		=> $this->user->img('icon_post_target', 'GOTO_PAGE'),
				'POLL_IMG'		=> $this->user->img('icon_topic_poll', 'TOPIC_POLL'),
				'S_TOPIC_ICONS'		=> $enable_icons && $has_at_least_one_icon,
			));
		}
	}

	/**
	* build_query
	* @param	array	$topic_data	shuld at least provide with topic_id and topic_title
	* @param 	mixed 	$forum_id 	The forum id to search in (false / 0 / null to search into all forums)
	*/
	private function build_query($topic_data, $forum_id = false)
	{
		if (!($match = $this->prepare_match($topic_data['topic_title'])))
		{
			return false;
		}

		if (!$forum_id || !empty($this->config['seo_related_allforums']))
		{
			// Only include those forums the user is having read access to...
			$related_forum_ids = $this->auth->acl_getf('f_read', true);

			if (!empty($related_forum_ids))
			{
				$related_forum_ids = array_keys($related_forum_ids);

				if (!empty($this->forum_exclude))
				{
					$related_forum_ids = array_diff($related_forum_ids, $this->forum_exclude);
				}

				$forum_sql = !empty($related_forum_ids) ? $this->db->sql_in_set('t.forum_id', $related_forum_ids, false) . ' AND ' : '';
			}
			else
			{
				$forum_sql = !empty($this->forum_exclude) ? $this->db->sql_in_set('t.forum_id', $this->forum_exclude, true) . ' AND ' : '';
			}
		}
		else
		{
			if (in_array($forum_id, $this->forum_exclude))
			{
				return false;
			}

			$forum_sql = ' t.forum_id = ' . (int) $forum_id . ' AND ';
		}

		$sql_array = array(
			'SELECT'	=> 't.*, f.forum_name, f.enable_icons',
			'FROM'		=> array(
				TOPICS_TABLE	=> 't',
				FORUMS_TABLE	=> 'f'
			),
			'WHERE'		=> "$forum_sql f.forum_id = t.forum_id",
		);

		if ($this->fulltext)
		{
			$sql_array['SELECT'] .= ", MATCH (t.topic_title) AGAINST ('" . $this->db->sql_escape($match) . "') relevancy";
			$sql_array['WHERE'] .= " AND MATCH (t.topic_title) AGAINST ('" . $this->db->sql_escape($match) . "')";
			$sql_array['ORDER_BY'] = 'relevancy DESC';
		}
		else
		{
			$sql_like = $this->buil_sql_like($match, 't.topic_title');

			if (!$sql_like) {
				return false;
			}

			$sql_array['WHERE'] .= " AND $sql_like";
			$sql_array['ORDER_BY'] = 't.topic_id DESC';
		}

		$sql_array['WHERE'] .= " AND t.topic_status <> " . ITEM_MOVED . "
			AND t.topic_id <> " . (int) $topic_data['topic_id'];

		return $this->db->sql_build_query('SELECT', $sql_array);
	}

	/**
	* prepare_match : Prepares the word list to search for
	* @param	string	$text		the string of all words to search for, eg topic_title
	* @param	int		$min_lenght	word with less than $min_lenght letters will be dropped
	* @param	int		$max_lenght	word with more than $max_lenght letters will be dropped
	*/
	private function prepare_match($text, $min_lenght = 3, $max_lenght = 14)
	{
		$word_list = array();
		$text = trim(preg_replace('`[\s]+`', ' ', $text));

		if (!empty($text))
		{
			$word_list = array_unique(explode(' ', utf8_strtolower($text)));

			foreach ($word_list as $k => $word)
			{
				$len = utf8_strlen(trim($word));

				if (($len < $min_lenght) || ($len > $max_lenght))
				{
					unset($word_list[$k]);
				}
			}
		}

		if (!empty($word_list) && !empty($this->config['seo_related_check_ignore']))
		{
			// add stop words to $user to allow reuse
			if (empty($this->user->stop_words))
			{
				$words = array();

				if (file_exists("{$this->user->lang_path}{$this->user->lang_name}/search_ignore_words.$this->php_ext"))
				{
					// include the file containing ignore words
					include("{$this->user->lang_path}{$this->user->lang_name}/search_ignore_words.$this->php_ext");
				}

				$this->user->stop_words = & $words;
			}

			$word_list = array_diff($word_list, $this->user->stop_words);
		}

		return !empty($word_list) ? implode(' ', $word_list) : '';
	}

	/**
	* buil_sql_like
	* @param	string	$text		the string of all words to search for, prepared with prepare_match
	* @param	string	$text		the table field we are matching against
	* @param	int		$limit		maxximum number of words to use in the query
	*/
	private function buil_sql_like($text, $field, $limit = 3)
	{
		$sql_like = array();
		$i = 0;
		$text = explode(' ', trim(preg_replace('`[\s]+`', ' ', $text)));

		if (!empty($text))
		{
			foreach ($text as $word)
			{
				$sql_like[] = "'%" . $this->db->sql_escape(trim($word)) . "%'";
				$i++;

				if ($i >= $limit)
				{
					break;
				}
			}
		}

		$result = false;
		$escape = '';
		$operator = 'LIKE';

		if (!empty($sql_like))
		{
			switch ($this->db->get_sql_layer())
			{
				case 'mysql':
				case 'mysql4':
				case 'mysqli':
					$result = '(t.topic_title LIKE ' . implode(' OR ', $sql_like) . ')';
					break;
				case 'oracle': // untested
				case 'mssql': // untested
				case 'mssql_odbc': // untested
				case 'mssqlnative': // untested
				case 'firebird': // untested
					$escape = " ESCAPE '\\'";
					// no break;
				case 'postgres':
					if ($this->db->get_sql_layer() === 'postgres')
					{
						$operator = 'ILIKE';
					}
					// no break;
				case 'sqlite': // untested
					$result = '(' . implode(' OR ', $this->sql_like_field($sql_like, $field, $operator, $escape)) . ')';
					break;
			}
		}

		return $result;
	}

	/**
	* sql_like_field
	* @param	array	$sql_like	the escaped words to match
	* @param	string	$field		the field to match against
	* @param	string	$operator	the operator to use
	* @param	string	$escape		the optional escape string
	*/
	private function sql_like_field($sql_like, $field, $operator = 'LIKE', $escape = '')
	{
		$result = array();

		foreach ($sql_like as $word)
		{
			$result[] = "($field $operator $word $escape)";
		}

		return $result;
	}
}
