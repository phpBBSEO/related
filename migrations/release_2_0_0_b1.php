<?php
/**
*
* @package Related Topics phpBB SEO
* @version $Id$
* @copyright (c) 2014 www.phpbb-seo.com
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

namespace phpbbseo\related\migrations;

class release_2_0_0_b1 extends \phpbb\db\migration\migration
{
	const SQL_FULLTEXT_NAME = 'topic_tft';

	public function effectively_installed()
	{
		return !empty($this->config['seo_related_on']);
	}

	static public function depends_on()
	{
		return array('\phpbb\db\migration\data\v310\rc1');
	}

	public function update_schema()
	{
		global $db;

		$db_tools = new \phpbb\db\tools($db);
		$indexes = $db_tools->sql_list_index(TOPICS_TABLE);
		$fulltext = 0;

		if (!in_array(self::SQL_FULLTEXT_NAME, $indexes))
		{
			// do not use db_tools since it does not support to add FullText indexes
			// we also use quite a basic approach to make sure that the index is not refused for bad reasons
			$sql = 'ALTER TABLE ' . TOPICS_TABLE . '
				ADD FULLTEXT ' . self::SQL_FULLTEXT_NAME . ' (topic_title)';
			$db->sql_return_on_error(true);
			$db->sql_query($sql);

			// make *sure* about the index !
			$indexes = $db_tools->sql_list_index(TOPICS_TABLE);
			$fulltext = in_array(self::SQL_FULLTEXT_NAME, $indexes) ? 1 : 0;
			$db->sql_return_on_error(false);
		}

		set_config('seo_related_fulltext', $fulltext);

		return array();
	}

	public function revert_schema()
	{
		return array(
			'drop_keys'	=> array(
				TOPICS_TABLE	=> array(
					self::SQL_FULLTEXT_NAME,
				),
			),
		);
	}
	public function update_data()
	{
		return array(
			array('config.add', array('seo_related_on', 1)),
		);
	}
}
