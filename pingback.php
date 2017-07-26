<?php
/*
	Copyright (C) 2009,2010 GungHo Technologies LLC
	released under GPLv3 - please refer to the file copyright.txt
*/

/*
Plugin Name: Deep Link Engine
Plugin URI: http://www.deeplinkengine.com
Description: Each time you create a new post it shows a list of possible pingback enabled blogs with relevant contents to your blog entry. You can then choose ones you will ping back and it will do this automatically. It also collects statistics about pinged blogs and relevant information so future blog posts will show even more relevant blogs to ping back to.
Version: 1.8.0
Author: Auto Content Cash
Author URI: http://www.autocontentcash.com
*/

/* Debugging: deep-link-engine/ directory and file deep-link-engine/logfile.txt
 * has to be accessible by the apache user
 */
//error_reporting(E_ALL);
//define("PB11_DEBUG", 1);
error_reporting(0);

if (!version_compare("5", PHP_VERSION, "<"))
{
	die('requires PHP 5 or higher');
}

// Needed for mass update
set_time_limit(0);

global $pb11_db_version, $pb11_version, $pb11_displayname;

// Define plugin version
$pb11_displayname = "Deep Link Engine";
$pb11_db_version = "1.31";
$pb11_version = "1.7.3";

require_once "pingback_core.php";
require_once "class.googlepr.php";

if(!class_exists("Pingbacker"))
{
	// Main class of the plugin
	class Pingbacker extends Pingbacker_Core
	{
		// Path to this plugin
		protected $plugin_path;

		function __construct()
		{
			parent::__construct();
			// Initialize plugin path
			$this->plugin_path = WP_PLUGIN_URL.'/'.
				str_replace(basename( __FILE__),"",plugin_basename(__FILE__));
			// Attach to the publish_post hook
			add_action("publish_post", array($this, "publish"), 100);
			// Attach to admin menu to create extra box (meta)
			add_action("admin_menu", array($this, "init_menu"));
			// Attach to scripts/css processing to add CSS and
			// Javascript to the header
			add_action("admin_print_styles-post-new.php",
					array($this, "init_styles"));
			add_action("admin_print_scripts-post-new.php",
					array($this, "init_scripts"));
			add_action("admin_print_styles-post.php",
					array($this, "init_styles"));
			add_action("admin_print_scripts-post.php",
					array($this, "init_scripts"));
			add_action("admin_print_scripts-options-general.php",
					array($this, "init_scripts_options"));
			// Attach to the handlers of the new post and edit post actions
			add_action("load-post.php",
					array($this, "init_action_edit"));
			add_action("load-post-new.php",
					array($this, "init_action_new"));
			// Attach to the AJAX handler
			add_action("wp_ajax_pingbacker",
					array($this, "ajax_callback"));
			// Finally attach ourselves to the post save handler
			add_action("save_post",
					array($this, "post_saving"), 10, 2);
			add_action("delete_post",
					array($this, "post_delete"));
		}

		function __destruct()
		{
			parent::__destruct();
		}

		function init_action_new()
		{
			$this->debug("setting postID=-1");
			$postID = -1;
			setcookie("pb11_ID", "$postID");
			$_COOKIE['pb11_ID'] = $postID;
		}

		function init_action_edit()
		{
			if(isset($_REQUEST["post"]))
			{
				$this->debug("setting postID={$_REQUEST['post']}");
				$postID = intval($_REQUEST["post"]);
				if (!headers_sent())
				{
					// Probably auto-blogger if headers already sent
					@setcookie("pb11_ID", "$postID");
				}
				$_COOKIE['pb11_ID'] = $postID;
			}
		}

		function init_menu()
		{
			global $pb11_displayname;

			if(isset($_REQUEST["post"]))
			{
				$id = $_REQUEST["post"];
				$post = get_post($id);
				if($post->post_status == "publish")
				{
					if(!headers_sent())
					{
						// Probably auto-blogger if headers already sent
						@setcookie("pb11_ID", $id);
					}
					$_COOKIE['pb11_ID'] = $id;
					$this->debug("Post already published");
				}
			}
			// Add our box to the top
			add_meta_box("pingbacker", $pb11_displayname,
					array($this, "pingbacker_box"), "post", "normal", "high");
		}

		function init_scripts()
		{
			// Add jqGrid Javascript
			wp_enqueue_script("jqgrid_lang", $this->plugin_path .
					"jqgrid/i18n/grid.locale-en.js", array("jquery"));
			wp_enqueue_script("jqgrid", $this->plugin_path .
					"jqgrid/jquery.jqGrid.min.js", array("jqgrid_lang"));
			// Add plug-in Javascript
			wp_enqueue_script("pingbacker", $this->plugin_path .
					"pingback.js", array("jqgrid", "jqgrid_lang"));
		}

		function init_scripts_options()
		{
			// Add jqGrid Javascript
			wp_enqueue_script("jqgrid_lang", $this->plugin_path .
					"jqgrid/i18n/grid.locale-en.js", array("jquery"));
			wp_enqueue_script("jqgrid", $this->plugin_path .
					"jqgrid/jquery.jqGrid.min.js", array("jqgrid_lang"));
			// Add plug-in Javascript
			wp_enqueue_script("pingbacker", $this->plugin_path .
					"pingback_options.js", array("jqgrid", "jqgrid_lang"));
		}

		function init_styles()
		{
			// Add jqGrid CSS
			wp_enqueue_style("jqgrid-ui", $this->plugin_path .
					"jqgrid/custom-theme/jquery-ui-1.7.2.custom.css");
			wp_enqueue_style("jqgrid", $this->plugin_path .
					"jqgrid/ui.jqgrid.css");
		}

		function pingbacker_box()
		{
			echo "".
			'<table id="pb11_inner" style="width:100%">
			<tr>
				<td id="pb11_tags_w" style="width:40%" valign="top">';
			// Use nonce for verification
			echo "".
			'<input type="hidden" name="pingbacker_nonce"
			id="pingbacker_nonce" value="' .
			wp_create_nonce(plugin_basename(__FILE__)) . '" />';
			// Table containing tags
			echo "".
			'<table id="pb11_tags"></table><div id="pb11_tags_p"></div></td>';
			// Table containing results
			echo "".
			'<td id="pb11_results_w" style="width:60%" valign="top">
				<table id="pb11_results"></table>
				<div id="pb11_results_p"></div>
			</td></tr>
			</table>';
		}

		function publish($postID)
		{
			global $wpdb;
			global $current_user;
			global $pb11_double;

			// Inner HTML tags
			$start_tag = '<!-- pingbacker_start -->';
			$end_tag = '<!-- pingbacker_end -->';
			// If we are here
			if (isset($pb11_double) && $pb11_double === true)
			{
				$this->debug("Double entry for $postID - skipping");
				return;
			}
			$pb11_double = true;
			$this->debug("Publishing post $postID");
			// Get the tags and results if this is automatic poster
			$this->update_tags($postID);
			$this->test_results($postID);
			$orig_post = get_post($postID);
			$new_post = (object)null;
			$new_post->ID = $orig_post->ID;
			$content = $this->removeOldPings($orig_post->post_content);
			$new_post->post_content = $content . $start_tag . get_option("pb_post_header");
			$table_name = "{$wpdb->prefix}pb11_sites";
			get_currentuserinfo();
			// Make the found blogs with trackback priority
			// Blog posts without TB have "!" prefix
			$SQL = $wpdb->prepare("SELECT * FROM $table_name
					WHERE postID=%d AND userID=%d AND deleted='n' ORDER BY pburl DESC",
				$postID, $current_user->ID);
			$rows = $wpdb->get_results($SQL);
			require_once ABSPATH . WPINC . "/class-IXR.php";
			$new_post->post_content .= get_option("pb_post_start");
			$maxlinks = intval(get_option("max_pb_links"));
			foreach($rows as $row)
			{
				$list_item = get_option("pb_post_inner");
				$list_item = str_replace("%1%", trim($row->title), $list_item);
				$list_item = str_replace("%2%", $row->url, $list_item);
				$new_post->post_content .= $list_item;
				$maxlinks--;
				if($maxlinks <= 0) break;
			}
			$new_post->post_content .= get_option("pb_post_end") . $end_tag;
			wp_update_post($new_post);
			// Wait some time to update actual post
			sleep(1);
			$maxlinks = intval(get_option("max_pb_links"));
			foreach($rows as $row)
			{
				if(preg_match("/^!/", $row->pburl))
				{
					$ping_url = substr($row->pburl, 1);
				}
				else
				{
					$ping_url = $row->pburl;
				}
				$client = new IXR_Client($ping_url);
				$GUID = get_permalink($orig_post->ID);
				$this->debug("Pinging $ping_url for GUID $GUID with url {$row->url}");
				if(!$client->query("pingback.ping", $orig_post->guid, $row->url))
				{
					$this->debug("Error pinging {$ping_url}:".$client->getErrorCode());
				}
				// Only ping until maximum number of links are reached
				$maxlinks--;
				if($maxlinks <= 0) break;
			}
			$pb11_double = false;
		}

		function post_saving($postID, $post)
		{
			global $wpdb;
			global $current_user;

			get_currentuserinfo();
			$this->debug("postID=$postID, userID={$current_user->ID}");
			if(!isset($_COOKIE['pb11_ID']) ||
				(intval($_COOKIE["pb11_ID"]) != intval($postID)))
			{
				$table_name = "{$wpdb->prefix}pb11_tags";
				$wpdb->hide_errors();
				// If this is the first save
				// update all references to unidentified tags
				$wpdb->update($table_name, array("postID" => $postID),
						array("postID" => -1, "userID" => $current_user->ID),
						array("%d"), array("%d", "%d"));
				$this->debug("updated session current postID to $postID");
				if(!headers_sent())
				{
					// Probably auto-blogger if headers already sent
					@setcookie("pb11_ID", $postID);
				}
				$_COOKIE['pb11_ID'] = $postID;
			}
		}

		function post_delete($postID)
		{
			global $wpdb;
			global $current_user;

			get_currentuserinfo();
			$this->debug("postID=$postID, userID={$current_user->ID}");
			$table_name = "{$wpdb->prefix}pb11_sites";
			$wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE postID=%d AND userID=%d",
				$postID, $current_user->ID));
			$table_name = "{$wpdb->prefix}pb11_tags";
			$wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE postID=%d AND userID=%d",
				$postID, $current_user->ID));
			$table_name = "{$wpdb->prefix}pb11_status";
			$wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE postID=%d AND userID=%d",
				$postID, $current_user->ID));
			$table_name = "{$wpdb->prefix}pb11_posts";
			$wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE postID=%d",
				$postID));
		}

		private function checkPostContent($post)
		{
			global $wpdb;

			// Check if empty post
			if(trim($post->post_content) == "")
			{
				$this->debug("post content is empty");
				return false;
			}
			// Check if post has been modified
			$table_name = "{$wpdb->prefix}pb11_posts";
			$wpdb->hide_errors();
			$modified = $wpdb->get_row(
					"SELECT * from $table_name WHERE postID={$post->ID}");
			if(is_object($modified) and isset($modified->modified) and
					$modified->modified == $post->post_modified_gmt)
			{
				// No new tags - not modified post
				$this->debug("no new tags - post not modified");
				return false;
			}
			// Update modified time
			if(!is_object($modified) or !isset($modified->modified))
			{
				$this->debug("post modified -> is new entry");
				// New entry
				$wpdb->insert($table_name, array("postID" => $post->ID,
							"modified" => $post->post_modified_gmt),
						array("%d", "%s"));
			}
			else
			{
				$this->debug("post modified -> existing entry");
				// Update
				$wpdb->update($table_name, array(
							"modified" => $post->post_modified_gmt),
						array("postID" => $post->ID), array("%s"), array("%d"));
			}
			return true;
		}

		private function removeOldPings($content)
		{
			// Inner HTML tags
			$start_tag = '<!-- pingbacker_start -->';
			$end_tag = '<!-- pingbacker_end -->';
			$oldpings_1 = strpos($content, $start_tag);
			if($oldpings_1 !== false)
			{
				$oldpings_2 = strrpos($content, $end_tag);
				$new_content = substr($content, 0, $oldpings_1);
				$new_content .= substr($content, $oldpings_2 + strlen($end_tag));
				$this->debug("removed pings from the published post\n{$new_content}");
				return $new_content;
			}
			return $content;
		}

		private function tagthe($postID)
		{
			global $wpdb;

			$post = get_post($postID);
			if(!$this->checkPostContent($post))
			{
				// No new tags
				return array();
			}
			$this->debug("postID=$postID");
			$content = "text=" . rawurlencode($this->removeOldPings($post->post_content));
			if($this->test_xml() === false)
			{
				$use_json = true;
				$content .= "&view=json";
			}
			$content = $this->get_uri("http://tagthe.net/api", "POST", $content);
			if($content === false)
			{
				return false;
			}
			if(!isset($use_json))
			{
				$xml = $this->xml2array($content);
				if($xml === false)
				{
					$this->debug("xml2array returned error");
					return false;
				}
				$tags = array();
				for($i = 0; $i < count($xml[0][0][0]) - 2; $i++)
				{
					$tags[] = $xml[0][0][0][$i]["value"];
				}
			}
			else
			{
				$json = $this->json_decode($content);
				if(!is_object($json))
				{
					$this->debug("json_decode returned error");
					return false;
				}
				$tags = array();
				foreach($json->memes[0]->dimensions->topic as $tag)
				{
					$tags[] = $tag;
				}
			}
			$this->debug("found tags \n" . print_r($tags, true));
			return $tags;
		}

		private function yahoo($postID)
		{
			global $wpdb, $pb11_displayname;

			$post = get_post($postID);
			if(!$this->checkPostContent($post))
			{
				// No new tags
				return array();
			}
			$yahooID = get_option("pb_yahooid");
			$yahooURL = "http://search.yahooapis.com/ContentAnalysisService/V1/termExtraction";
			$content = "appid=" . rawurlencode($yahooID);
			$content .= "&context=" . rawurlencode($this->removeOldPings($post->post_content));
			if($this->test_xml() === false)
			{
				$use_json = true;
				$content .= "&output=json";
			}
			$content = $this->get_uri($yahooURL, "POST", $content);
			if($content === false)
			{
				return false;
			}
			if(!isset($use_json))
			{
				$this->debug("Got content:\n{$content}");
				$xml = $this->xml2array($content);
				if($xml === false)
				{
					$this->debug("xml2array returned error");
					return false;
				}
				$tags = array();
				if($xml[0]["name"] == "Error")
				{
					$this->debug("invalid or empty Yahoo ID detected");
					return false;
				}
				for($i = 0; $i < count($xml[0]) - 2; $i++)
				{
					$tags[] = $xml[0][$i]["value"];
				}
			}
			else
			{
				$json = $this->json_decode($content);
				if(!is_object($json))
				{
					$this->debug("json_decode returned error");
					return false;
				}
				$tags = array();
				foreach($json->ResultSet->Result as $tag)
				{
					$tags[] = $tag;
				}
			}
			$this->debug("found tags \n" . print_r($tags, true));
			return $tags;
		}

		private function update_tags($postID)
		{
			global $wpdb;
			global $current_user;

			get_currentuserinfo();
			$table_name = "{$wpdb->prefix}pb11_tags";
			$wpdb->hide_errors();
			$this->debug("postID is $postID");
			switch(intval(get_option("pb_engine")))
			{
				case 0:
					$tags = $this->tagthe($postID);
					break;
				case 1:
					$tags = $this->yahoo($postID);
					break;
				default:
					$this->debug("engine not implemented!");
					$tags = false;
					break;
			}
			if($tags !== false)
			{
				$maxtags = intval(get_option("max_pb_tags"));
				foreach($tags as $tag)
				{
					$exists = $wpdb->get_row(
						"SELECT COUNT(*) AS cnt from $table_name WHERE
						postID={$postID} AND userID={$current_user->ID}
						AND tag='{$tag}'");
					if (is_object($exists) and isset($exists->cnt) and
						intval($exists->cnt) == 1)
					{
						continue;
					}
					$wpdb->insert($table_name, array("postID" => $postID,
								"userID" => $current_user->ID,
								"tag" => $tag), array("%d", "%d", "%s"));
					if(--$maxtags <= 0) break;
				}
			}
		}

		private function ajax_tags()
		{
			global $wpdb;
			global $current_user;

			get_currentuserinfo();
			$table_name = "{$wpdb->prefix}pb11_tags";
			$wpdb->hide_errors();
			$postID = intval($_COOKIE["pb11_ID"]);
			$this->debug("postID is $postID");
			if (isset($_POST['oper']))
			{
				switch($_POST['oper'])
				{
					case 'add':
						{
							$wpdb->insert($table_name, array("postID" => $postID,
										"userID" => $current_user->ID,
										"tag" => "{$_POST["tag"]}"),
									array("%d", "%d", "%s")) or
								print("ERROR: possible duplicate tag while adding");
							$this->debug(
									"inserted tag \"{$_POST["tag"]}\" for postID=$postID");
							exit();
						}
						break;
					case 'del':
						{
							$del_ids = urldecode($_POST["id"]);
							$wpdb->query("UPDATE $table_name SET deleted='y' WHERE id IN ($del_ids)");
							$this->debug(
									"deleted tags for ids=$del_ids");
							exit();
						}
						break;
					case 'edit':
						{
							$edit_ids = urldecode($_POST["id"]);
							$edit_id = explode(",", $edit_ids);
							$wpdb->update($table_name, array("tag" => $_POST["tag"]),
									array("id" => $edit_id[0]), array("%s"), array("%d"))
								or
								print("ERROR: cannot modify tag - check for duplicates");
							exit();
						}
						break;
					default:
						break;								}
			}
			if(intval($postID) != -1)
			{
				$this->update_tags($postID);
			}
			$page = $_POST['page'];
			$limit = $_POST['rows'];
			$sidx = $_POST['sidx'];
			$sord = $_POST['sord'];
			if(!$sidx) $sidx = 1;
			$row = $wpdb->get_row(
					"SELECT COUNT(*) AS count FROM $table_name
					WHERE postID=$postID AND userID={$current_user->ID} AND deleted='n'");
			$count = intval($row->count);
			if($count > 0) {
				$total_pages = ceil($count/$limit);
			} else {
				$total_pages = 0;
			}
			if($page > $total_pages) $page=$total_pages;
			$start = $limit*$page - $limit;
			if(intval($start) < 0) $start = 0;
			$this->debug(
					"getting tags for postID={$postID} and userID={$current_user->ID}");
			$SQL = $wpdb->prepare("SELECT * FROM $table_name
					WHERE postID=%d AND userID=%d AND deleted='n' ORDER BY $sidx $sord",
				$postID, $current_user->ID);
			$rows = $wpdb->get_results($SQL);
			if(stristr($_SERVER["HTTP_ACCEPT"],"application/xhtml+xml")) {
				header("Content-type: application/xhtml+xml;charset=utf-8");
			} else {
				header("Content-type: text/xml;charset=utf-8");
			}
			$et = ">";
			echo "<?xml version='1.0' encoding='utf-8'?$et\n";
			echo "<rows>";
			echo "<page>".$page."</page>";
			echo "<total>".$total_pages."</total>";
			echo "<records>".$count."</records>";
			foreach($rows as $row) {
				echo "<row id='". $row->id . "'>";
				echo "<cell><![CDATA[". $row->tag ."]]></cell>";
				echo "<cell><![CDATA[". $row->updated."]]></cell>";
				echo "</row>";
			}
			echo "</rows>";
			exit();
		}

		private function find_pingback($url, &$anchors, &$blog_title = null)
		{
			$html = $this->get_uri($url);
			if($html === false)
			{
				$this->debug("Socket error loading page - returning false");
				return false;
			}
			if(isset($blog_title))
			{
				preg_match("/<title>([^<]+)<\/title>/i", $html, $matches);
				$blog_title = $matches[1];
				$this->debug("Found title $blog_title");
			}
			preg_match("/rel\\s*=\\s*[\"']pingback[\"']\\s+href\\s*=\\s*['\"](.*)['\"]/i", $html, $matches);
			if(count($matches) > 0)
			{
				$anchors = preg_match_all("/<a.*?\/a>/i", $html, $dummy);
				if(isset($blog_title) and strlen(trim($blog_title)) == 0)
				{
					// Put blog title to URL if not set
					$blog_title = $matches[1];
				}
				if(stripos($html, 'trackback</a>') === false)
				{
					$this->debug("Found no trackback anchor - marking result");
					return "!".$matches[1];
				}
				return $matches[1];
			}
			return false;
		}

		private function get_status($postID = null)
		{
			global $wpdb;
			global $current_user;

			get_currentuserinfo();
			$status_table = "{$wpdb->prefix}pb11_status";
			$wpdb->hide_errors();
			if (!isset($postID))
			{
				$postID = intval($_COOKIE["pb11_ID"]);
			}
			$this->debug("postID is $postID");
			$SQL = $wpdb->prepare("SELECT status FROM $status_table ".
					"WHERE postID=%d AND userID=%d",
				$postID, $current_user->ID);
			$rows = $wpdb->get_results($SQL);
			if(count($rows) == 1)
			{
				return intval($rows[0]->status);
			}
			return 100;
		}

		private function get_mass_status()
		{
			global $wpdb;
			global $current_user;

			get_currentuserinfo();
			$status_table = "{$wpdb->prefix}pb11_status";
			$wpdb->hide_errors();
			$SQL = $wpdb->prepare("SELECT status, extra FROM $status_table ".
					"WHERE postID=-1 AND userID=%d", $current_user->ID);
			$rows = $wpdb->get_results($SQL);
			if(count($rows) == 1)
			{
				$extra = $rows[0]->extra;
				if (strpos($extra, ':') !== false)
				{
					$extra_arr = explode(':', $extra);
					$post_status = $this->get_status(intval($extra_arr[1]));
					$extra = $extra_arr[0];
					if ($post_status != 100)
					{
						$extra .= " ({$post_status}%)";
					}
				}
				return intval($rows[0]->status) . ":" . $extra;
			}
			return 100;
		}

		private function update_status($postID, $status, $extra = null)
		{
			global $wpdb;
			global $current_user;

			if (isset($extra))
			{
				$extra = ' - ' . $extra;
			}
			$this->debug("updating status to $status for postID=$postID and userID={$current_user->ID}");
			$status_table = "{$wpdb->prefix}pb11_status";
			$SQL = $wpdb->prepare("SELECT COUNT(*) AS cnt FROM $status_table ".
					"WHERE postID=%d AND userID=%d",
				$postID, $current_user->ID);
			$rows = $wpdb->get_results($SQL);
			if(intval($rows[0]->cnt) == 0)
			{
				$this->debug("adding new entry in status table");
				$SQL = $wpdb->prepare("INSERT INTO $status_table(userID, postID, status, extra) ".
					"VALUES(%d, %d, %d, %s)", $current_user->ID, $postID, $status, $extra);
				$wpdb->query($SQL);
			}
			else
			{
				$this->debug("updating existing entry in status table");
				$SQL = $wpdb->prepare("UPDATE $status_table SET status=%d,extra=%s WHERE userID=%d AND postID=%d",
					$status, $extra, $current_user->ID, $postID);
				$wpdb->query($SQL);
			}
		}

		private function fill_results($postID)
		{
			global $wpdb;
			global $current_user;

			$tags_name = "{$wpdb->prefix}pb11_tags";
			$sites_name = "{$wpdb->prefix}pb11_sites";
			$SQL = $wpdb->prepare("SELECT * FROM $tags_name
					WHERE postID=%d AND userID=%d",
				$postID, $current_user->ID);
			$rows = $wpdb->get_results($SQL);
			$count = 0;
			$gpr = new GooglePR();
			foreach($rows as $row)
			{
				if($row->processed == "Y" or $row->deleted == "y")
				{
					$count++;
					$this->update_status($postID, intval(100.0*($count/count($rows))));
					continue;
				}
				switch(intval(get_option("pb_type")))
				{
					case 0:
						// RSS feed - fresh blogs
						$raw_url = "http://blogsearch.google.com/blogsearch_feeds?hl=en&q=%s&ie=utf-8&num=%d&output=rss";
						$raw_tag = str_replace(" ", "+", $row->tag);
						$url = sprintf($raw_url, $raw_tag, get_option("max_pb_shown"));
						$this->debug("Getting RSS for '$raw_tag' using $url");
						$rss = $this->get_uri($url);
						if($rss === false)
						{
							$this->debug("Socket error getting RSS feed - continuing");
							continue;
						}
						$docxml = new DOMDocument();
						$docxml->loadXML($rss);
						$nodes = $docxml->getElementsByTagName("item");
						$minicount = 0;
						if($nodes->length > 0)
						{
							foreach($nodes as $blog)
							{
								$blog_link = $blog->getElementsByTagName("link");
								$blog_link = $blog_link->item(0)->nodeValue;
								$exists = $wpdb->get_row(
									"SELECT COUNT(*) AS cnt from $sites_name WHERE
									postID={$postID} AND userID={$current_user->ID}
									AND url='{$blog_link}'");
								if (is_object($exists) and isset($exists->cnt) and
								intval($exists->cnt) == 1)
								{
									$this->debug("Duplicate link in the table ($blog_link)");
									$minicount++;
									$this->update_status($postID, intval(100.0*(($count + $minicount / 10)/count($rows))));
									continue;
								}
								$blog_title = $blog->getElementsByTagName("title");
								$blog_title = $blog_title->item(0)->nodeValue;
								if(strlen(trim($blog_title)) == 0)
								{
									$blog_title = $blog_link;
								}
								$anchors = 0;
								$pb = $this->find_pingback($blog_link, $anchors);
								if($pb)
								{
									$this->debug("Found pingback $pb for $blog_link");
									$this->debug("Blog title '$blog_title'");
									$googlepr = $gpr->GetPR($blog_link);
									$SQL = $wpdb->prepare("INSERT INTO $sites_name(id, url, pburl, title, userID, postID, ext_links, googlepr) ".
									"VALUES(NULL, '%s', '%s', '%s', %d, %d, %d, %d)", $blog_link, $pb, $blog_title,
									$current_user->ID, $postID, $anchors, $googlepr);
									$wpdb->query($SQL);
								}
								$minicount++;
								$this->update_status($postID, intval(100.0*(($count + $minicount / 10)/count($rows))));
							}
						}
						break;
					case 1:
						// Relevance search
						$raw_url = "http://blogsearch.google.com/blogsearch?hl=en&ie=UTF-8&q=%s&lr=&num=%d";
						$raw_tag = str_replace(" ", "+", $row->tag);
						$url = sprintf($raw_url, $raw_tag, get_option("max_pb_shown"));
						$this->debug("Getting html for '$raw_tag' using $url");
						$html = $this->get_uri($url);
						if($html === false)
						{
							$this->debug("Socket error getting HTML page - continuing");
							continue;
						}
						$nodes_cnt = preg_match_all("/<a\s+href\s*=\s*[\"']([^\"']+)[\"']\s+id\s*=\s*[\"']p-[0-9]+[\"']\s*>/i", $html, $matches);
						$this->debug("Found $nodes_cnt different URLs");
						$minicount = 0;
						if($nodes_cnt > 0)
						{
							for($i = 0; $i < $nodes_cnt; $i++)
							{
								$blog_link = $matches[1][$i];
								$anchors = 0;
								$blog_title = "";
								$exists = $wpdb->get_row(
									"SELECT COUNT(*) AS cnt from $sites_name WHERE
									postID={$postID} AND userID={$current_user->ID}
									AND url='{$blog_link}'");
								if (is_object($exists) and isset($exists->cnt) and
								intval($exists->cnt) == 1)
								{
									$this->debug("Duplicate link in the table ($blog_link)");
									$minicount++;
									$this->update_status($postID, intval(100.0*(($count + $minicount / 10)/count($rows))));
									continue;
								}
								$pb = $this->find_pingback($blog_link, $anchors, $blog_title);
								if($pb)
								{
									$this->debug("Found pingback $pb for $blog_link");
									$this->debug("Blog title '$blog_title'");
									$googlepr = $gpr->GetPR($blog_link);
									$SQL = $wpdb->prepare("INSERT INTO $sites_name(id, url, pburl, title, userID, postID, ext_links, googlepr) ".
									"VALUES(NULL, '%s', '%s', '%s', %d, %d, %d, %d)", $blog_link, $pb, $blog_title,
									$current_user->ID, $postID, $anchors, $googlepr);
									$wpdb->query($SQL);
								}
								$minicount++;
								$this->update_status($postID, intval(100.0*(($count + $minicount / 10)/count($rows))));
							}
						}
						break;
					default:
						break;
				}
				$SQL = $wpdb->prepare("UPDATE $tags_name SET processed='%s' WHERE id=%d", "Y", $row->id);
				$wpdb->query($SQL);
				$count++;
				$this->update_status($postID, intval(100.0*($count/count($rows))));
			}
		}

		private function test_results($postID)
		{
			global $wpdb;
			global $current_user;

			$sites_name = "{$wpdb->prefix}pb11_sites";
			$tags_name = "{$wpdb->prefix}pb11_tags";
			$row = $wpdb->get_row(
					"SELECT MAX(updated) AS updated FROM $tags_name
					WHERE postID=$postID AND userID={$current_user->ID}");
			$tags_updated = strtotime($row->updated);
			$this->debug("Maximum update from $tags_name is $tags_updated");
			$row = $wpdb->get_row(
					"SELECT MIN(updated) AS updated FROM $sites_name
					WHERE postID=$postID AND userID={$current_user->ID}");
			if(($sites_updated = strtotime($row->updated)) === false)
			{
				$sites_updated = 0;
			}
			$this->debug("Minimum update from $sites_name is $sites_updated");
			if($sites_updated >= $tags_updated)
			{
				$this->debug("No rechecking necessary");
				return;
			}
			$this->fill_results($postID);
		}

		private function ajax_mass_process($check = true)
		{
			global $wpdb;
			global $current_user;
			global $pb11_double;

			get_currentuserinfo();
			$wpdb->hide_errors();
			$table_name = "{$wpdb->prefix}pb11_sites";
			$this->update_status(-1, 0);
			$blogs = urldecode($_POST['data']);
			$blogs = explode(',', $blogs);
			$cnt = 0;
			foreach ($blogs as $postID)
			{
				$cnt++;
				$extra = 'updating ' . $cnt . ' of ' . count($blogs) .
					':' . $postID;
				$this->update_status(-1, intval(99.0*($cnt/count($blogs))), $extra);
				if ($check === true)
				{
					$this->publish($postID);
				}
				else
				{
					$post = get_post($postID);
					$good_links = array();
					if ($_POST['remove'] == 'false')
					{
						$start_tag = '<!-- pingbacker_start -->';
						$end_tag = '<!-- pingbacker_end -->';
						$tag1 = strpos($post->post_content, $start_tag);
						$tag2 = strpos($post->post_content, $end_tag);
						$posted = substr($post->post_content, $tag1, $tag2-$tag1);
						$GUID = get_permalink($postID);
						preg_match_all('/<a.+href=[\'"](?<link>[^\'"]+)[\'"]/iUs', $posted, $matches);
						preg_match_all('/<a.+>(?<title>[^<]+)</iUs', $posted, $titles);
						$this->debug(print_r($matches, true));
						$this->debug(print_r($titles, true));
						$cnt = 0;
						foreach($matches['link'] as $link)
						{
							$html = $this->get_uri($link);
							if($html === false)
							{
								$this->debug("Socket error getting HTML page - continuing");
								continue;
							}
							if (stripos($html, $GUID) !== false)
							{
								$this->debug("Found match for {$link}");
								$good_links[$link] = $titles['title'][$cnt++];
							}
							else
							{
								$wpdb->query("UPDATE $table_name SET deleted='y' WHERE url='$link'");
							}
						}
					}
					$new_post = (object)null;
					$new_post->ID = $post->ID;
					$content = $this->removeOldPings($post->post_content);
					$new_post->post_content = $content;
					if (count($good_links) > 0)
					{
						$new_post->post_content = $start_tag . get_option("pb_post_header");
						$new_post->post_content .= get_option("pb_post_start");
						foreach($good_links as $link => $title)
						{
							$list_item = get_option("pb_post_inner");
							$list_item = str_replace("%1%", trim($title), $list_item);
							$list_item = str_replace("%2%", $link, $list_item);
							$new_post->post_content .= $list_item;
						}
						$new_post->post_content .= get_option("pb_post_end") . $end_tag;
					}
					$pb11_double = true;
					wp_update_post($new_post);
				}
			}
		}

		private function ajax_notify_process()
		{
			global $wpdb;
			global $current_user;

			get_currentuserinfo();
			// Check official notification list entry
			$table_name = "{$wpdb->prefix}pb11_status";
			$wpdb->hide_errors();
			$type = $_POST['grid'] === 'notify_email' ? 'submit' : 'confirm';
			switch ($type)
			{
				case 'confirm':
					$sql = $wpdb->prepare("INSERT INTO ${table_name} SET extra='%s', userID=%d,
					postID=%d", $_POST['code'], $current_user->ID, 0);
					if (!$wpdb->query($sql))
					{
						$sql = $wpdb->prepare("UPDATE ${table_name} SET extra='%s' WHERE userID=%d
						AND postID=%d", $_POST['code'], $current_user->ID, 0);
						$wpdb->query($sql);
					}
					break;
				case 'submit':
					$notify = $_POST['name'] . ':' . $_POST['email'];
					$sql = $wpdb->prepare("INSERT INTO ${table_name} SET extra='%s', userID=%d,
					postID=%d", $notify, $current_user->ID, 0);
					if (!$wpdb->query($sql))
					{
						$sql = $wpdb->prepare("UPDATE ${table_name} SET extra='%s' WHERE userID=%d
						AND postID=%d", $notify, $current_user->ID, 0);
						$wpdb->query($sql);
					}
					$postData = array(
						'meta_web_form_id' => '1943244335',
						'meta_split_id' => '',
						'listname' => 'autocash_subs',
						'redirect' => 'http://autocontentcash.com/blog/deep-link-engine.php',
						'meta_adtracking' => 'wordpress_com',
						'meta_message' => '1',
						'meta_required' => 'name,email',
						'meta_forward_vars' => '',
						'meta_tooltip' => '',
						'name' => $_POST['name'],
						'from' => $_POST['email']);
					$post = '';
					foreach ($postData as $key => $value)
					{
						if (!empty($post))
						{
							$post .= '&';
						}
						$post .= $key . '=' . rawurlencode($value);
					}
					// Ignore results
					$this->get_uri('http://www.aweber.com/scripts/addlead.pl',
							'POST', $post);
					$this->get_uri('http://deeplinkengine.com/confirm.php',
							'POST', $post);
					break;
				default:
					break;
			}
		}

		private function ajax_mass($check = true)
		{
			global $wpdb;
			global $current_user;

			$this->debug("\$check={$check}");
			get_currentuserinfo();
			$this->debug("Got current user");
			//$wpdb->hide_errors();
			$sidx = $_POST['sidx'];
			$sord = strtoupper($_POST['sord']);
			$this->debug("order={$sord} orderby={$sidx}");
			$args = array(
				'nopaging' => 1,
				'order' => "{$sord}",
				'orderby' => "{$sidx}");
			$postslist = get_posts($args);
			$this->debug("get_posts returned " . count($postslist));
			$rows = array();
			foreach ($postslist as $post)
			{
				// See if this blog already has pingbacks
				$start_tag = '<!-- pingbacker_start -->';
				$end_tag = '<!-- pingbacker_end -->';
				$tag1 = strpos($post->post_content, $start_tag);
				$tag2 = strpos($post->post_content, $end_tag);
				if ($check && $tag1 !== false && $tag2 !== false)
				{
					// Skip it, already has pingbacks
					continue;
				}
				if (!$check && $tag1 === false && $tag2 === false)
				{
					// Skip it, has no pingbacks
					continue;
				}
				$row = (object)'';
				$row->id = $post->ID;
				$row->title = $post->post_title;
				$row->date = $post->post_modified;
				$rows[] = $row;
			}
			$page = $_POST['page'];
			$limit = $_POST['rows'];
			if(!$sidx) $sidx = 1;
			$count = count($rows);
			if($count > 0) {
				$total_pages = ceil($count/$limit);
			} else {
				$total_pages = 0;
			}
			if($page > $total_pages) $page=$total_pages;
			$start = $limit*$page - $limit;
			if(intval($start) < 0) $start = 0;
			if(stristr($_SERVER["HTTP_ACCEPT"],"application/xhtml+xml")) {
				header("Content-type: application/xhtml+xml;charset=utf-8");
			} else {
				header("Content-type: text/xml;charset=utf-8");
			}
			$et = ">";
			echo "<?xml version='1.0' encoding='utf-8'?$et\n";
			echo "<rows>";
			echo "<page>".$page."</page>";
			echo "<total>".$total_pages."</total>";
			echo "<records>".$count."</records>";
			foreach($rows as $row) {
				echo "<row id='". $row->id . "'>";
				echo "<cell><![CDATA[". $row->title ."]]></cell>";
				echo "<cell><![CDATA[". $row->date ."]]></cell>";
				echo "</row>";
			}
			echo "</rows>";
			exit();
		}

		private function ajax_results()
		{
			global $wpdb;
			global $current_user;

			get_currentuserinfo();
			$table_name = "{$wpdb->prefix}pb11_sites";
			$wpdb->hide_errors();
			$postID = intval($_COOKIE["pb11_ID"]);
			$this->debug("postID is $postID");
			if (isset($_POST['oper']) && $_POST["oper"] == "del")
			{
				$del_ids = urldecode($_POST["id"]);
				$wpdb->query("UPDATE $table_name SET deleted='y' WHERE id IN ($del_ids)");
				$this->debug(
						"deleted results for ids=$del_ids");
				exit();
			}
			$this->update_status($postID, 0);
			$this->test_results($postID);
			$this->update_status($postID, 100);
			$page = $_POST['page'];
			$limit = $_POST['rows'];
			$sidx = $_POST['sidx'];
			$sord = $_POST['sord'];
			if(!$sidx) $sidx = 1;
			$row = $wpdb->get_row(
					"SELECT COUNT(*) AS count FROM $table_name
					WHERE postID=$postID AND userID={$current_user->ID} AND deleted='n'");
			$count = intval($row->count);
			if($count > 0) {
				$total_pages = ceil($count/$limit);
			} else {
				$total_pages = 0;
			}
			if($page > $total_pages) $page=$total_pages;
			$start = $limit*$page - $limit;
			if(intval($start) < 0) $start = 0;
			$this->debug(
					"getting results for postID={$postID} and userID={$current_user->ID}");
			$SQL = $wpdb->prepare("SELECT * FROM $table_name
					WHERE postID=%d AND userID=%d AND deleted='n' ORDER BY $sidx $sord",
				$postID, $current_user->ID);
			$rows = $wpdb->get_results($SQL);
			if(stristr($_SERVER["HTTP_ACCEPT"],"application/xhtml+xml")) {
				header("Content-type: application/xhtml+xml;charset=utf-8");
			} else {
				header("Content-type: text/xml;charset=utf-8");
			}
			$et = ">";
			echo "<?xml version='1.0' encoding='utf-8'?$et\n";
			echo "<rows>";
			echo "<page>".$page."</page>";
			echo "<total>".$total_pages."</total>";
			echo "<records>".$count."</records>";
			foreach($rows as $row) {
				echo "<row id='". $row->id . "'>";
				echo "<cell><![CDATA[". $row->url ."]]></cell>";
				echo "<cell><![CDATA[". $row->title ."]]></cell>";
				echo "<cell>". $row->googlepr ."</cell>";
				echo "<cell>". number_format($row->ext_links) ."</cell>";
				echo "</row>";
			}
			echo "</rows>";
			exit();
		}

		function ajax_callback()
		{
			wp_verify_nonce($_POST["_wpnonce"],plugin_basename(__FILE__)) or
				die("ERROR: Security check");
			$this->debug("security check OK");
			switch($_POST["grid"])
			{
				case "results":
					$this->debug("calling ajax_results");
					$this->ajax_results();
					break;
				case "tags":
					$this->debug("calling ajax_tags");
					$this->ajax_tags();
					break;
				case "status":
					sleep(2);
					$this->debug("got status request, returning ".$this->get_status());
					echo $this->get_status();
					exit();
					break;
				case "mass_status":
					sleep(2);
					$this->debug("got mass_status request, returning ".$this->get_mass_status());
					echo $this->get_mass_status();
					exit();
					break;
				case "mass":
					$this->debug("calling ajax_mass");
					$this->ajax_mass();
					break;
				case "chk_mass":
					$this->debug("calling ajax_mass(false)");
					$this->ajax_mass(false);
					break;
				case "mass_process":
					$this->debug("calling ajax_mass_process");
					$this->ajax_mass_process();
					break;
				case "chk_mass_process":
					$this->debug("calling ajax_mass_process(false)");
					$this->ajax_mass_process(false);
					break;
				case "notify_email":
				case "notify_submit":
					$this->debug("calling ajax_notify_process");
					$this->ajax_notify_process();
					break;
				default:
					die("ERROR: Grid method not implemented ({$_POST['grid']})");
					break;
			}
		}
	}
}

function pingbacker_table($table_name, $createSQL)
{
	global $wpdb;
	global $pb11_db_version;

	$table_name = "{$wpdb->prefix}".$table_name;
	// First time install
	if($wpdb->get_var("show tables like '$table_name'") != $table_name)
	{
		$sql = "CREATE TABLE $table_name ($createSQL
		);";
		require_once ABSPATH . "wp-admin/includes/upgrade.php";
		dbDelta($sql);
		return;
	}
	// Upgrade
	$installed_ver = get_option("pb11_db_version");
	if($installed_ver != $pb11_db_version)
	{
		$sql = "CREATE TABLE $table_name ($createSQL
		);";
		require_once ABSPATH . "wp-admin/includes/upgrade.php";
		dbDelta($sql);
	}
}

function pingbacker_setup()
{
	global $pb11_db_version;

	// Add plugin options
	add_option("max_pb_shown", 10);
	add_option("max_pb_links", 20);
	add_option("max_pb_tags", 10);
	add_option("pb_engine", 0);
	add_option("pb_yahooid", "");
	add_option("pb_type", 0);
	add_option("pb_post_header", "<h4>Related Blogs</h4>");
	add_option("pb_post_start", '<ul class="pc_pingback">');
	add_option("pb_post_inner", '<li><a href="%2%">%1%</a></li>');
	add_option("pb_post_end", "</ul>");
	// MySql tables
	pingbacker_table("pb11_sites", "
			id bigint(11) NOT NULL AUTO_INCREMENT,
			userID bigint(11) NOT NULL,
			postID bigint(11) NOT NULL,
			url tinytext NOT NULL,
			pburl tinytext NOT NULL,
			title tinytext NOT NULL,
			googlepr tinyint(4) NOT NULL DEFAULT -1,
			ext_links smallint(6) NOT NULL DEFAULT -1,
			updated timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
			deleted enum('y','n') NOT NULL default 'n',
			PRIMARY KEY  id_sites (id),
			UNIQUE KEY  sites1 (userID,postID,url(256))");
	pingbacker_table("pb11_tags", "
			id bigint(11) NOT NULL AUTO_INCREMENT,
			userID bigint(11) NOT NULL,
			postID bigint(11) NOT NULL,
			tag char(128) NOT NULL,
			processed char(1) NULL,
			updated timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
			deleted enum('y','n') NOT NULL default 'n',
			PRIMARY KEY  id_tags (id),
			UNIQUE KEY  tags1 (userID,postID,tag)");
	pingbacker_table("pb11_posts", "
			postID bigint(11) NOT NULL,
			modified timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
			PRIMARY KEY  id_posts (postID)");
	pingbacker_table("pb11_status", "
			userID bigint(11) NOT NULL,
			postID bigint(11) NOT NULL,
			status tinyint(4) NOT NULL,
			extra varchar(255) NULL DEFAULT NULL,
			PRIMARY KEY  id_status (userID, postID)");
	$installed_ver = get_option("pb11_db_version");
	if(!isset($installed_ver))
	{
		add_option("pb11_db_version", $pb11_db_version);
	}
	if($installed_ver != $pb11_db_version)
	{
		update_option("pb11_db_version", $pb11_db_version);
	}
}

function pingbacker_menu()
{
	global $pb11_displayname, $pb11;

	// Set up options page
	$page = add_options_page("$pb11_displayname Options", "$pb11_displayname",
			1, "pingbacker11", "pingbacker_options");
	add_action("admin_print_scripts-$page", array($pb11, 'init_scripts_options'));
	add_action("admin_print_styles-$page", array($pb11, 'init_styles'));
}

function pingbacker_register_settings()
{
	register_setting("pingbacker_options", "max_pb_shown");
	register_setting("pingbacker_options", "max_pb_links");
	register_setting("pingbacker_options", "max_pb_tags");
	register_setting("pingbacker_options", "pb_engine");
	register_setting("pingbacker_options", "pb_yahooid");
	register_setting("pingbacker_options", "pb_type");
	register_setting("pingbacker_options", "pb_post_header");
	register_setting("pingbacker_options", "pb_post_start");
	register_setting("pingbacker_options", "pb_post_inner");
	register_setting("pingbacker_options", "pb_post_end");
}

function pingbacker_options()
{
	global $pb11_displayname, $wpdb;
	global $current_user;

	get_currentuserinfo();
	// Check official notification list entry
	$table_name = "{$wpdb->prefix}pb11_status";
	$wpdb->hide_errors();
	$sql = $wpdb->prepare("SELECT extra FROM {$table_name} WHERE userID=%d and postID=%d",
		$current_user->ID, 0);
	$row = $wpdb->get_row($sql);
	$sform = !isset($row->extra) || ($row->extra !== '7l.H#(_Aeh');
	echo <<<EOT
<div class="wrap">
	<div id="icon-options-general" class="icon32"><br /></div>
	<h2>$pb11_displayname Options</h2>
	<p>Hover over an option to see its description.</p>
	<form method="post" action="options.php">
EOT;
	settings_fields("pingbacker_options");
	echo <<<EOT
		<table class="form-table">
			<tr valign="top">
				<th scope="row"><label for="max_pb_shown">Maximum search results</label></th>
EOT;
	echo "\t\t\t\t".'<td><input type="text" id="max_pb_shown" name="max_pb_shown" class="small-text" value="' .
		get_option('max_pb_shown') .
		'" title="Maximum pingback suggestions the ' . $pb11_displayname .
		' will get from engine per tag" /> results</td>';
	echo <<<EOT
			</tr>
			<tr valign="top">
				<th scope="row"><label for="max_pb_links">Maximum links to post</label></th>
EOT;
	echo "\t\t\t\t".'<td><input type="text" id="max_pb_links" name="max_pb_links" class="small-text" value="' .
		get_option('max_pb_links') .
		'" title="Maximum pingback links the ' . $pb11_displayname .
		' will ever post per one blog entry" /> links</td>';
	echo <<<EOT
			</tr>
			<tr valign="top">
				<th scope="row"><label for="max_pb_tags">Maximum search tags</label></th>
EOT;
	echo "\t\t\t\t".'<td><input type="text" id="max_pb_tags" name="max_pb_tags" class="small-text" value="' .
		get_option('max_pb_tags') .
		'" title="Maximum tags the ' . $pb11_displayname .
		' will find in the blog post contents" /> tags</td>';
	echo <<<EOT
			</tr>
			<tr valign="top">
				<th scope="row">Tag finder engine</th>
EOT;
	echo '<td><p><label><input type="radio" name="pb_engine" class="tog" value="0"'.(intval(get_option('pb_engine')) == 0 ? ' checked="true"':'').' />&nbsp;<a href="http://tagthe.net" title="tagthe.net - Webservice that tags your resources" target="_blank">TagTheNet</a></label></p>';
	echo '<p><label><input type="radio" name="pb_engine" class="tog" value="1"'.(intval(get_option('pb_engine')) == 1 ? ' checked="true"':'').' />&nbsp;<a href="http://developer.yahoo.com/search/content/V1/termExtraction.html" title="Yahoo Term Extraction API" target="_blank">Yahoo</a></label></p></td>';
	echo <<<EOT
			</tr>
			<tr valign="top">
				<th scope="row"><label for="pb_yahooid">Yahoo <a href="https://developer.apps.yahoo.com/wsregapp/" title="Click to register a new App ID with Yahoo" target="_blank">App ID</a></label></th>
EOT;
	echo '<td><input type="text" id="pb_yahooid" name="pb_yahooid" class="regular-text" value="' . get_option("pb_yahooid") . '" title="Yahoo application ID to use with the API" /></td>';
	echo <<<EOT
			</tr>
			<tr valign="top">
				<th scope="row"><label for="pb_post_header">Header template</label></th>
EOT;
	echo '<td><input type="text" id="pb_post_header" name="pb_post_header" class="regular-text" value="' . str_replace('"', "'", get_option("pb_post_header")) . '" title="The header template inserted in blog post" /></td>';
	echo <<<EOT
			</tr>
			<tr valign="top">
				<th scope="row"><label for="pb_post_start">Start list</label></th>
EOT;
	echo '<td><input type="text" id="pb_post_start" name="pb_post_start" class="regular-text" value="' . str_replace('"', "'", get_option("pb_post_start")) . '" title="The start template inserted in blog post" /></td>';
	echo <<<EOT
			</tr>
			<tr valign="top">
				<th scope="row"><label for="pb_post_inner">List item</label></th>
EOT;
	echo '<td><input type="text" id="pb_post_inner" name="pb_post_inner" class="regular-text" value="' . str_replace('"', "'", get_option("pb_post_inner")) . '" title="The item template inserted in blog post (use %1% for title and %2% for URL)" /></td>';
	echo <<<EOT
			</tr>
			<tr valign="top">
				<th scope="row"><label for="pb_post_end">End list</label></th>
EOT;
	echo '<td><input type="text" id="pb_post_end" name="pb_post_end" class="regular-text" value="' . str_replace('"', "'", get_option("pb_post_end")) . '" title="The end template inserted in blog post" /></td>';
	echo <<<EOT
			</tr>
			<tr valign="top">
				<th scope="row">Search engine setting</th>
EOT;
	echo '<td><p><label title="'.$pb11_displayname.' will search for most recent blogs"><input type="radio" name="pb_type" class="tog" value="0"'.(intval(get_option('pb_type')) == 0 ? ' checked="true"':'').' />Most recent</label></p>';
	echo '<p><label title="'.$pb11_displayname.' will search for most relevant blogs"><input type="radio" name="pb_type" class="tog" value="1"'.(intval(get_option('pb_type')) == 1 ? ' checked="true"':'').' />Most relevant</label></p></td>';
	echo <<<EOT
			</tr>
		</table>
		<p class="submit">
			<input type="submit" class="button-primary" value="Save Changes" />
			<span style="margin-left:50px">&nbsp;</span>
			<input type="button" class="button-primary" onclick="pb11_mass_trigger(false);"
			title="Mass update blog posts with pingbacks" value="Perform Mass Update" />
			<span style="margin-left:50px">&nbsp;</span>
			<input type="button" class="button-primary" onclick="pb11_mass_trigger(true);"
			title="Verify blog posts with pingbacks" value="Perform Verify" />
		</p>
	</form>
</div>
<div id="pb11_background" style="position:fixed;top:0;left:0;width:100%;height:100%;background:black;z-index:1000;display:none;">
</div>
<div id="favorite_inside" style="display:none;"></div>
<div id="pb11_progress" style="position:absolute;border:1px solid #080808;top:0;left:0;width:50%;height:30px;z-index:1001;background:#f0f0f0;display:none;">
</div>
<span id="pb11_progressbar" style="position:absolute;color:white;font-weight:bold;vertical-align:middle;line-length:24px;text-align:center;text-shadow:1px 1px black, 1px 1px #333;width:48%;z-index:1002;height:24px;top:0;left:0;background:#5580a6;display:none;">
Processing (<span id="pb11_progressbartxt">0</span>%)&nbsp;<span id="pb11_progressbarext">&nbsp;</span>
</span>
<div id="pb11_window" style="position:absolute;top:0;left:0;width:60%;height:70%;z-index:1001;background:#f0f0f0;display:none;">
<div class="wrap">
EOT;
	echo "".
	'<input type="hidden" name="pingbacker_nonce"
	id="pingbacker_nonce" value="' .
	wp_create_nonce(plugin_basename(__FILE__)) . '" />';
	echo "".
	'<br /><table id="pb11_masslist"></table><div id="pb11_masslist_p"></div><br />
	<table width="100%">
	<tr>
		<td align="left">
			<span id="pb11_verify_delete" style="display:none;">
				<input type="checkbox" id="pb11_verify_delchk" name="pb11_verify_delchk"
				title="Delete all links from the selected blogs" value="1" />
				Remove links completely
			</span>
			&nbsp;
		</td>
		<td align="right" style="width:50%;">
			<input type="button" class="button-primary" id="pb11_mass_process"
			title="Confirm selection and start processing" value="Process" />
			<span style="margin-left:8px">&nbsp;</span>
			<input type="button" class="button-primary" onclick="pb11_mass_cancel();"
			title="Cancel processing" value="Cancel" />
			<span style="margin-left:16px">&nbsp;</span>
		</td>
	</tr>
	</table>
</div>
</div>';
	// No entry and the first run
	if ($sform)
	{
		echo <<<EOT
<div id="pb11_submit" style="position:absolute;top:0;left:0;width:720px;height:760px;z-index:1001;background:white;display:none;">
<form method="post" action="#" onsubmit="return false;">
EOT;
		echo "".
		'<input type="hidden" name="pingbacker_nonce"
		id="pingbacker_nonce" value="' .
		wp_create_nonce(plugin_basename(__FILE__)) . '" />';
		echo <<<EOT
<table border="0" width="100%" height="100%" style="margin-top:20px;">
	<tr>
		<td align="center">
		<b><font face="Arial" size="4">Thank you for
		downloading Deep Link Engine!</font></b>
		<p><font face="Arial" style="font-size: 11pt">This is a simple way to automatically get
		new backlinks to each and every blog post. We have some exciting new
		features planned. To registers your copy of Deep Link Engine enter your
		name and email below.</font></p>
		<p><font face="Arial" style="font-size: 11pt">You will be mailed a registration code that you
		can use on all of your blogs, plus you will get updates on other cool
		traffic getting Word Press plugins and tips we have to share.</font></p>
		<img border="0" src="http://www.autocontentcash.com/dle1/images/box_DeepLinking_med.jpg" width="292" height="367">
		</td>
	</tr>
	<tr>
		<td align="center">
		<font face="Arial" style="font-size: 11pt">Enter your name and email to register and get your
		access code to use on ALL of your blogs: </font>
		<table style="margin-top:8px;" cellpadding="0">
			<tr valign="top">
				<th scope="row" align="right"><font face="Arial">
				<label for="name">First Name:</label></font></th>
				<td>
				<font face="Arial">
				<span style="font-size: 11pt">
				<input id="pb11_name" name="name" class="text" value title="Your Name" type="text"> </span> </font></td>
			</tr>
			<tr valign="top">
				<th scope="row" align="right"><font face="Arial">
				<label for="email">Email:</label></font></th>
				<td>
				<font face="Arial">
				<span style="font-size: 11pt">
				<input id="pb11_email" name="email" class="text" value title="Your Email Address" type="text">
				</span>
				</font></td>
			</tr>
		</table>
		<span class="submit" align="center"><font face="Arial">
		<i>
		<span class="description"><font size="2">* We will not spam, rent, or loan your
		information!</font></span></i><span style="font-size: 11pt"><br />
		<input name="submit" class="button-primary" value="Register" onclick="return pb11_notify_trigger(this);" type="submit" />
		</span>
		</font>
		</span>
		</td>
	</tr>
	<tr>
		<td align="center">
		<br />
		<font face="Arial" style="font-size: 11pt">Enter your access code to unlock Deep Link Engine:
		</font>
		<table style="margin-top:8px;" cellpadding="0">
			<tr valign="middle">
				<th scope="row"><font face="Arial"><label for="code">Code:</label></font></th>
				<td><font face="Arial">
				<input id="pb11_code" name="code" class="text" value title="Your Access Code" type="text"></font></td>
			</tr>
		</table>
		<span class="submit"><font face="Arial">
		<input name="submit" class="button-primary" value="Confirm" onclick="return pb11_notify_trigger(this);" type="submit" />
		</font>
		</span>
		</td>
	</tr>
</table>
EOT;
		echo <<<EOT
	</form>
</div>
EOT;
		return;
	}
}

if(class_exists("Pingbacker"))
{
	// Create our main class
	add_action("plugins_loaded",
			create_function("", 'global $pb11; $pb11 = new Pingbacker();'));
	// Register setup function hook
	register_activation_hook(__FILE__, "pingbacker_setup");
}

if(is_admin())
{
	add_action("admin_menu", "pingbacker_menu");
	add_action("admin_init", "pingbacker_register_settings");
}

?>
