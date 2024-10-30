<?php
/*
Plugin Name: Copyscape Post Checker
Plugin URI: http://fusecurity.com/copyscape/
Description: This plugin will allow administrators to chek posts against copyscape via the copyscape API
Version: 1.1
Author: Fuse Development Group
Author URI: http://fusecurity.com/
License: http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

add_action('publish_post', 'copyscape_check');
add_action('admin_menu', 'cs_plugin_menu');

register_activation_hook(__FILE__,'csdb_install');
register_deactivation_hook(__FILE__, 'csdb_uninstall');

define('COPYSCAPE_USERNAME', get_option('cs_user'));
define('COPYSCAPE_API_KEY', get_option('cs_api_key'));
define('COPYSCAPE_ADMIN_EMAIL', get_option('admin_email'));
define('COPYSCAPE_SRCH_TYPE', get_option('srch_type'));

function csdb_uninstall()
{
	global $wpdb;
	$tbl_name = $wpdb->prefix."copyscape";
	$query = mysql_query("DROP TABLE $tbl_name");
}

function csdb_install()
{
	global $wpdb;
	$tbl_name = $wpdb->prefix."copyscape";

	if($wpdb->get_var("show tables like '$tbl_name'") != $tbl_name)
	{
		$sql = "CREATE TABLE ".$tbl_name." (
			admin_email text NOT NULL,
			cs_key text NOT NULL,
			cs_user text NOT NULL,
			cs_srch_type text NOT NULL
			);";

		require_once(ABSPATH.'wp-admin/includes/upgrade.php');
		dbDelta($sql);
		$rows_affected = $wpdb->insert($tbl_name, array('admin_email' => 'test@admin.com', 'cs_key' => 'your_key', 'cs_user' => 'your_username', 'cs_srch_type' => 'txt'));
	}
}

function cs_plugin_menu()
{
	add_options_page('Copyscape Options', 'Copyscape', 'manage_options', 'copyscape', 'cs_plugin_options');
}

function copyscape_check($post_ID)
{
	$post_info = get_post($post_ID);

	global $wpdb;

	$tbl_name = $wpdb->prefix."copyscape";
	$sql = "select cs_srch_type from $tbl_name";

	switch($wpdb->get_var($sql,0))
	{
		case "txt":
			$response = copyscape_api_text_search($post_info->post_content, 'ISO-8859-1');
		break;

		case "url":
			$response = copyscape_api_url_search($post_info->guid, 'ISO-8859-1');
		break;
	}

	if($response['count']>0)
	{
		$my_post = array();
		$my_post['ID'] = $post_ID;
		$my_post['post_status'] = 'draft';

		//Update the post into the database
		wp_update_post($my_post);
		mail(COPYSCAPE_ADMIN_EMAIL, "Plaigarism Detected!" , 'a post has been found to be denied by the copyscape plugin.');
	}

	return $post_ID;
}

function cs_plugin_options()
{
	global $wpdb;

	if($_POST['copyscape_api_key'] && $_POST['copyscape_username'] && $_POST['admin_email'])
	{
		$key = filter_var($_POST['copyscape_api_key']);
		$name = filter_var($_POST['copyscape_username']);
		$email = filter_var($_POST['admin_email']);
		$srch = filter_var($_POST['srch_type']);

		$tbl_name = $wpdb->prefix."copyscape";
		$q = mysql_query("update $tbl_name set admin_email = '$email', cs_key = '$key', cs_user = '$name', cs_srch_type = '$srch'");
	}

	$tbl_name = $wpdb->prefix."copyscape";
	$sql = "select cs_key,cs_user,admin_email,cs_srch_type from $tbl_name";

	if (!current_user_can('manage_options'))
	{
		wp_die( __('You do not have sufficient permissions to access this page.') );
	}

	$balance = copyscape_api_check_balance();

	switch($wpdb->get_var($sql,3))
	{
		case "txt": $srch1 = "checked"; break;
		case "url": $srch2 = "checked"; break;
	}

	echo '	<div class="wrap">
			<div style="float:left;padding-right:40px;">
			<h2>Copyscape Options</h2>
			<form method="post">
			<p><b>API Key</b><br /><input type="text" name="copyscape_api_key" value="'.$wpdb->get_var($sql,0).'"></p>
			<p><b>Username</b><Br /><input type="text" name="copyscape_username" value="'.$wpdb->get_var($sql,1).'"></p>
			<p><b>Admin Email</b><br /><input type="text" name="admin_email" value="'.$wpdb->get_var($sql,2).'"></p>
			<p><b>Search type</b><br /><input type="radio" name="srch_type" value="txt"'.$srch1.'> Search Post Content <br/><input type="radio" name="srch_type" value="url"'.$srch2.'> Search by Post URL</p>
			<p><input class="button-primary" type="submit" id="save_copyscape_options" value="Save Settings"></p>
			</form>
			</div>';


	echo '		<div style="float:left;padding-right:40px;">
			<h2>Balance Check</h2>
			<p><b>Total: </b>'.$balance['total'].'<Br/>
			<b>Today: </b>'.$balance['today'].'</p>
			</div>
		</div>';
}


/* copyscape API from copyscape.com */
function copyscape_api_url_search($url, $full=null)
{
	$params['q']=$url;

	if (isset($full))
	{
		$params['c']=$full;
	}
		
	return copyscape_api_call('csearch', $params, array(2 => array ('result' => 'array')));
}
	
function copyscape_api_text_search($text, $encoding, $full=null)
{
	$params['e']=$encoding;

	if (isset($full))
	{
		$params['c']=$full;
	}

	return copyscape_api_call('csearch', $params, array(2 => array ('result' => 'array')), $text);
}
	
function copyscape_api_check_balance()
{
	return copyscape_api_call('balance');
}

	function copyscape_api_call($operation, $params=array(), $xmlspec=null, $postdata=null)
	{
		$url='http://www.copyscape.com/api/?u='.urlencode(COPYSCAPE_USERNAME).
			'&k='.urlencode(COPYSCAPE_API_KEY).'&o='.urlencode($operation);
		
		foreach ($params as $name => $value)
			$url.='&'.urlencode($name).'='.urlencode($value);
		
		$curl=curl_init();
		
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_TIMEOUT, 30);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_POST, isset($postdata));
		
		if (isset($postdata))
			curl_setopt($curl, CURLOPT_POSTFIELDS, $postdata);
		
		$response=curl_exec($curl);
		curl_close($curl);
		
		if (strlen($response))
			return copyscape_read_xml($response, $xmlspec);
		else
			return false;
	}
	
	function copyscape_read_xml($xml, $spec=null)
	{
		global $copyscape_xml_data, $copyscape_xml_depth, $copyscape_xml_ref, $copyscape_xml_spec;
		
		$copyscape_xml_data=array();
		$copyscape_xml_depth=0;
		$copyscape_xml_ref=array();
		$copyscape_xml_spec=$spec;
		
		$parser=xml_parser_create();
		
		xml_set_element_handler($parser, 'copyscape_xml_start', 'copyscape_xml_end');
		xml_set_character_data_handler($parser, 'copyscape_xml_data');
		
		if (!xml_parse($parser, $xml, true))
			return false;
			
		xml_parser_free($parser);
		
		return $copyscape_xml_data;
	}

	function copyscape_xml_start($parser, $name, $attribs)
	{
		global $copyscape_xml_data, $copyscape_xml_depth, $copyscape_xml_ref, $copyscape_xml_spec;
		
		$copyscape_xml_depth++;
		
		$name=strtolower($name);
		
		if ($copyscape_xml_depth==1)
			$copyscape_xml_ref[$copyscape_xml_depth]=&$copyscape_xml_data;
		
		else {
			if (!is_array($copyscape_xml_ref[$copyscape_xml_depth-1]))
				$copyscape_xml_ref[$copyscape_xml_depth-1]=array();
				
			if (@$copyscape_xml_spec[$copyscape_xml_depth][$name]=='array') {
				if (!is_array(@$copyscape_xml_ref[$copyscape_xml_depth-1][$name])) {
					$copyscape_xml_ref[$copyscape_xml_depth-1][$name]=array();
					$key=0;
				} else
					$key=1+max(array_keys($copyscape_xml_ref[$copyscape_xml_depth-1][$name]));
				
				$copyscape_xml_ref[$copyscape_xml_depth-1][$name][$key]='';
				$copyscape_xml_ref[$copyscape_xml_depth]=&$copyscape_xml_ref[$copyscape_xml_depth-1][$name][$key];

			} else {
				$copyscape_xml_ref[$copyscape_xml_depth-1][$name]='';
				$copyscape_xml_ref[$copyscape_xml_depth]=&$copyscape_xml_ref[$copyscape_xml_depth-1][$name];
			}
		}
	}

	function copyscape_xml_end($parser, $name)
	{
		global $copyscape_xml_depth, $copyscape_xml_ref;
		
		unset($copyscape_xml_ref[$copyscape_xml_depth]);

		$copyscape_xml_depth--;
	}
	
	function copyscape_xml_data($parser, $data)
	{
		global $copyscape_xml_depth, $copyscape_xml_ref;

		if (is_string($copyscape_xml_ref[$copyscape_xml_depth]))
			$copyscape_xml_ref[$copyscape_xml_depth].=$data;
	}
?>
