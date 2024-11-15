<?php
/**
 * MyBB 1.8 Merge System
 * Copyright 2019 MyBB Group, All Rights Reserved
 *
 * Website: http://www.mybb.com
 * License: http://www.mybb.com/download/merge-system/license/
 */

// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

class DZX25_Converter_Module_Users extends Converter_Module_Users {
	
	var $settings = array(
			'friendly_name' => 'users',
			'progress_column' => 'uid',
			'encode_table' => 'common_member',
			'username_column' => 'username',
			'email_column' => 'email',
			'postnum_column' => 'cposts',
			'default_per_screen' => 1000,
	);
	
	/**
	 * Total users queried from the MyBB Database used in the users module
	 */
	public $total_mybbusers = 0;
	
	public $user_found = false;
	
	// Timezone, will get from MyBB setting.
	public $setting_timezone = 0;

	// Override some default values.
	function __construct($converter_class)
	{
		parent::__construct($converter_class);
		
		// Alter some default values.
		if(defined("DZX25_CONVERTER_USERS_LASTTIME"))
		{
			$this->default_values['lastactive'] = DZX25_CONVERTER_USERS_LASTTIME;
			$this->default_values['lastvisit'] = DZX25_CONVERTER_USERS_LASTTIME;
		}
		$this->default_values['dstcorrection'] = 2;
		$this->default_values['classicpostbit'] = 1;
		$this->default_values['subscriptionmethod'] = 0;	// Changed from 2 to 0 to perform no email being sent by subscriptions.
		$this->default_values['pmnotify'] = 0;	// Changed from 1 to 0 to not notifying by email.
		$this->default_values['pmfolders'] = '0**$%%$1**$%%$2**$%%$3**$%%$4**';	// From 1820, 0 => Inbox, 1 => Unread, 2 => Sent, 3 => Draft, 4 => trash
	}
	
	function pre_setup()
	{
		parent::pre_setup();
		
		global $db;
		// Count the total number of users in our MyBB database.
		$query = $db->simple_select("users", "COUNT(*) as totalusers");
		$this->total_mybbusers = $db->fetch_field($query, "totalusers");
		$db->free_result($query);
		
		// Get timezone from MyBB setting.
		global $mybb;
		if($mybb->settings['timezoneoffset'])
		{
			$this->setting_timezone = intval($mybb->settings['timezoneoffset']);
		}
		else
		{
			$query = $db->simple_select("settings", "name,value");
			$this->setting_timezone = intval($db->fetch_field($query, "timezoneoffset"));
			$db->free_result($query);
		}
	}
	
	function import()
	{
		global $import_session;

		// Get members and their status, profiles, settings.
		$query = $this->old_db->query("
			SELECT 
				member.*, 
				ol.total AS oltimem, 
				membercount.oltime AS coltimeh, 
				membercount.posts AS cposts, 
				membercount.threads AS ctreads, 
				memberstatus.lastpost AS slastpost, 
				memberstatus.regip AS sregip, 
				memberstatus.lastip AS slastip, 
				memberstatus.lastvisit AS slastvisit, 
				memberstatus.lastactivity AS slastactivity, 
				memberstatus.invisible AS sinvisible, 
				memberprofile.birthyear AS pbirthyear, 
				memberprofile.birthmonth AS pbirthmonth, 
				memberprofile.birthday AS pbirthday, 
				memberprofile.site AS psite, 
				memberprofile.icq AS picq, 
				memberprofile.yahoo AS pyahoo, 
				memberforum.customstatus AS fcustomstatus, 
				memberforum.sightml AS fsightml
			FROM ".OLD_TABLE_PREFIX."common_member AS member
				LEFT JOIN ".OLD_TABLE_PREFIX."common_onlinetime AS ol
					ON (ol.uid = member.uid) 
				LEFT JOIN ".OLD_TABLE_PREFIX."common_member_count AS membercount
					ON (membercount.uid = member.uid) 
				LEFT JOIN ".OLD_TABLE_PREFIX."common_member_status AS memberstatus
					ON (memberstatus.uid = member.uid) 
				LEFT JOIN ".OLD_TABLE_PREFIX."common_member_profile AS memberprofile
					ON (memberprofile.uid = member.uid) 
				LEFT JOIN ".OLD_TABLE_PREFIX."common_member_field_forum AS memberforum
					ON (memberforum.uid = member.uid) 
			ORDER BY memberforum.uid ASC 
			LIMIT ".$this->trackers['start_users'].", ".$import_session['users_per_screen']
				);
		while($user = $this->old_db->fetch_array($query))
		{
			$this->insert($user);
		}
	}
	
	function convert_data($data)
	{
		global $import_session;
		
		$insert_data = array();
		
		/**
		 * We don't update these fields for such a user: username, email, regdate, regip, passwordconvert, passwordconvertsalt, passwordconverttype.
		 * $data: user data to import.
		 * $mybb_user: array, ucenter user data; string, suggested username for duplicated user; false, new user.
		 * $insert_data: data to be queried.
		 */ 
		
		// Given a username and email, check if current user is in our MyBB.
		$mybb_user = $this->get_mybb_user($data);

		if($this->user_found)
		{
			// Any field should be reserved, since base class may have set default values for them.
			foreach($mybb_user as $key => $value)
			{
				$insert_data[$key] = $value;
			}
			
			// Found the username and email in MyBB database, update its settings, permissions and profiles.
			$insert_data['mybbuid'] = $mybb_user['uid'];
			$update_lastip = false;
			
			// Discuz! values starts here.
			// Compare lastactive and lastvisit to see if any modification of these fields is needed.
			if($mybb_user['lastactive'] < $data['slastactivity'])
			{
				$insert_data['lastactive'] = $data['slastactivity'];
				$update_lastip = true;
			}
			if($mybb_user['lastvisit'] < $data['slastvisit'])
			{
				$insert_data['lastvisit'] = $data['slastvisit'];
				$update_lastip = true;
			}
			if($update_lastip)
			{
				$insert_data['lastip'] = my_inet_pton($data['slastip']);
				$update_lastip = true;
			}
			if($mybb_user['lastpost'] < $data['slastpost'])
			{
				$insert_data['lastpost'] = $data['slastpost'];
			}
			
			// Cumulate posts and thread numbers.
			$insert_data['postnum'] += $data['cposts'];
			//$insert_data['threadnum'] += $data['cthreads'];
			$insert_data['threadnum'] = isset($data['cthreads']) ? (int)$data['cthreads'] : 0;
			
			// Overwrite `email` field since user data in UCenter may have a cut off. The validation was done in get_mybb_user() function.
			$insert_data['email'] = $this->board->encode_to_utf8($data['email'], "common_member", "users");
		}
		else
		{
			if($mybb_user === false)
			{
				// Given the username and email, no user is found in MyBB database, add it;
				$insert_data['username'] = $this->board->encode_to_utf8($data['username'], "common_member", "users");
			}
			else
			{
				// It's a user with a duplicated username, add it.
				$insert_data['username'] = $mybb_user;
			}
			
			// Discuz! values.
			// Set some field values, will not be set later.
			$insert_data['lastactive'] = $data['slastactivity'];
			$insert_data['lastvisit'] = $data['slastvisit'];
			$insert_data['lastip'] = my_inet_pton($data['slastip']);
			
			$insert_data['postnum'] = $data['cposts'];
			$insert_data['threadnum'] = $data['cthreads'];
			
			$insert_data['lastpost'] = $data['slastpost'];
			
			// These data should be reserved, since base class has default values for them.
			$insert_data['email'] = $this->board->encode_to_utf8($data['email'], "common_member", "users");
			$insert_data['regdate'] = $data['regdate'];
			$insert_data['lastactive'] = $data['lastactivity'] == 0 ? $data['regdate'] : $data['lastactivity'];
			$insert_data['lastvisit'] = $data['lastvisit'] == 0 ? $data['regdate'] : $data['lastvisit'];
			if(substr($data['regip'], 0, 1) == "M" || substr($data['regip'], 0, 1) == "h")
			{
				// Manully added user or user ip is hidden.
				$insert_data['regip'] = '';
			}
			else
			{
				$insert_data['regip'] = my_inet_pton($data['regip']);
			}
			$insert_data['lastip'] = my_inet_pton($data['lastip']);
			$insert_data['passwordconvert'] = $data['password'];
			$insert_data['passwordconvertsalt'] = '';
			$insert_data['passwordconverttype'] = 'dzx25';
		}
		
		// Import_ fields
		$insert_data['import_uid'] = $data['uid'];
		if($this->user_found)
		{
			$insert_data['import_usergroup'] = $mybb_user['usergroup'];
			$insert_data['import_additionalgroups'] = $mybb_user['additionalgroups'];
		}
		
		// Discuz! values
		// Usergroup
		if(!$this->user_found || ($this->user_found && $mybb_user['usergroup'] == 0) || (defined("DZX25_CONVERTER_USERS_GROUPS_OVERWRITE") && DZX25_CONVERTER_USERS_GROUPS_OVERWRITE))
		{
			$insert_data['import_usergroup'] = $data['groupid'];
			$insert_data['usergroup'] = $this->board->get_gid($data['groupid']);
			if($data['extgroupids'])
			{
				$addtional_groups = implode(",", array_unique(array_filter(explode("\t", $data['extgroupids']))));
				$insert_data['import_additionalgroups'] = $addtional_groups;
				$insert_data['additionalgroups'] = $this->board->get_group_id($addtional_groups, array(MYBB_ADMINS, MYBB_SMODS, $insert_data['usergroup']));
				if(!empty($insert_data['additionalgroups']))
				{
					unset($insert_data['additionalgroups']);
				}
			}
		}
		
		// Other fields.
		if(!$this->user_found || ($this->user_found && $mybb_user['usergroup'] == 0) || (defined("DZX25_CONVERTER_USERS_PROFILE_OVERWRITE") && DZX25_CONVERTER_USERS_PROFILE_OVERWRITE))
		{
			$insert_data['usertitle'] = $this->board->encode_to_utf8($data['fcustomstatus'], "common_member_field_forum", "users");
			$insert_data['website'] = $data['psite'];
			$insert_data['icq'] = $data['picq'];
			$insert_data['yahoo'] = $data['pyahoo'];
			
			if($data['pbirthyear'] && $data['pbirthmonth'] && $data['pbirthday'])
			{
				$insert_data['birthday'] = "{$data['pbirthday']}-{$data['pbirthmonth']}-{$data['pbirthyear']}";
			}
			
			$insert_data['signature'] = $this->board->encode_to_utf8($data['fsightml'], "common_member_field_forum", "users");
			$insert_data['signature'] = $this->bbcode_parser->convert_sig($insert_data['signature'], $import_session['encode_to_utf8'] ? 'utf-8' : $this->board->fetch_table_encoding($this->settings['encode_table']));
			$insert_data['invisible'] = $data['sinvisible'];
			$insert_data['receivefrombuddy'] = $data['onlyacceptfriendpm'];
			$insert_data['timezone'] = $data['timeoffset'] == 9999 ? $this->setting_timezone : $data['timeoffset'];
			$insert_data['dstcorrection'] = $this->default_values['dstcorrection'];
			
			$online_time = $data['coltimeh'] * 60 > $data['oltimem'] ? $data['coltimeh'] * 60 : $data['oltimem'];
			$online_time *= 60;
			if(!$this->user_found || ($this->user_found && $mybb_user['timeonline'] < $online_time))
			{
				$insert_data['timeonline'] = $online_time;
			}
		}
		
		return $insert_data;
	}
	
	function finish()
	{
		global $import_session, $db;
		
		// Generate redirect file for users module, if permitted.
		if(defined("DZX25_CONVERTER_GENERATE_REDIRECT") && DZX25_CONVERTER_GENERATE_REDIRECT && !empty($import_session['DZX25_Redirect_Files_Path']) && $import_session['total_users'])
		{
			// Check numbers of user with import_uid > 0. We can't handle that if some imported users have been merged multiple times in one converter's running.
			$query = $db->simple_select("users", "COUNT(*) as count", "import_uid > 0");
			$total_imported_users = $db->fetch_field($query, 'count');
			$db->free_result($query);
			
			require_once dirname(__FILE__). '/generate_redirect.php';
			
			$redirector = new DZX25_Redirect_Generator();
			$redirector->generate_file('users', $total_imported_users);
			
			$redirector->write_file("\t\t\t'uids' => array(\n");
			
			$start = 0;
			while($start < $total_imported_users)
			{
				$count = 0;
				$query = $db->simple_select("users", "uid,import_uid", "import_uid > 0", array('limit_start' => $start, 'limit' => 1000));
				while($user = $db->fetch_array($query))
				{
					$record = "\t\t\t\t\t";
					$record .= "'{$user['import_uid']}' => {$user['uid']}";
					$redirector->write_record($record);
					$count++;
				}
				$start += $count;
			}
			@$db->free_result($query);
			$redirector->write_file("\t\t\t),\n");
			$redirector->write_file("\t\t\t'usernames' => array(\n");
			
			$start = 0;
			while($start < $total_imported_users)
			{
				$count = 0;
				$query = $db->simple_select("users", "uid,username", "import_uid > 0", array('limit_start' => $start, 'limit' => 1000));
				while($user = $db->fetch_array($query))
				{
					$user['username'] = str_replace('\'', '\\\'', $user['username']);
					$record = "\t\t\t\t\t";
					$record .= "'{$user['username']}' => {$user['uid']}";
					$redirector->write_record($record);
					$count++;
				}
				$start += $count;
			}
			@$db->free_result($query);
			$redirector->write_file("\t\t\t),\n");
			
			$redirector->finish_file();
		}
	}
	
	function fetch_total()
	{
		global $import_session;
		
		// Get number of members
		if(!isset($import_session['total_users']))
		{
			$query = $this->old_db->simple_select("common_member", "COUNT(*) as count");
			$import_session['total_users'] = $this->old_db->fetch_field($query, 'count');
			$this->old_db->free_result($query);
		}
		
		return $import_session['total_users'];
	}

	/**
	 * Customized user insertion into database, in order to use the system's internal tracker.
	 *
	 * @param array $data The insert array going into the MyBB database
	 * @return int|bool The new id or false if it's a duplicated user
	 */
	public function insert($data)
	{
		global $db, $output;
		
		try {
			++$this->total_users;
			++$this->total_mybbusers;
			$this->user_found = false;
			
			$this->debug->log->datatrace('$data', $data);
			
			$output->print_progress("start", $data[$this->settings['progress_column']]);
			
			// Call our currently module's process function
			$data = $this->convert_data($data);
			
			// Avoid wrong lastactive and lastvisit times (mybb sees "0" or "" as currently online)
			// unsetting the value works as the default value above sets it to the current timestamp
			if(empty($data['lastactive']))
			{
				unset($data['lastactive']);
			}
			if(empty($data['lastvisit']))
			{
				unset($data['lastvisit']);
			}
			
			$uid = 0;
			if($this->user_found)
			{
				// Storing the uid of a user to be updated.
				$uid = $data['mybbuid'];
				unset($data['mybbuid']);
			}
			
			// Should loop through and fill in any values that aren't set based on the MyBB db schema or other standard default values and escape them properly
			$insert_array = $this->prepare_insert_array($data, 'users');
			if (!isset($insert_array['threadnum']) || $insert_array['threadnum'] === '') {
				$insert_array['threadnum'] = 0;
			}
			$this->debug->log->datatrace('$insert_array', $insert_array);
			
			if($this->user_found)
			{
				$output->print_progress("merge_user", array('import_uid' => $insert_array['import_uid'], 'duplicate_uid' => $uid));
				
				// Update a user record.
				$db->update_query("users", $insert_array, "uid = '{$uid}'");
			}
			else
			{
				// Add a new user.
				$db->insert_query("users", $insert_array);
				$uid = $db->insert_id();
			}
			
			$this->increment_tracker('users');
			
			if(!$this->user_found)
			{
				$output->print_progress("end");
			}
			
			return $uid;
		} catch (Exception $e) {
			// Log the exception
			$this->debug->log->error('Error inserting user: ' . $e->getMessage());
			return false;
		}
	}
		
 
	
	/**
	 * Check if a previously imported user match the crireria.
	 * Will set $this->user_found to true. After a update, this var should be set back to false.
	 *
	 * @param array $data The insert array going into the MyBB database
	 * @return array|string|bool The user data found in MyBB, if any. Or the suggested username if a duplicated user is found. Or false if it's a new user, where it shouldn't occur.
	 */
	public function get_mybb_user($user)
	{
		global $db, $import_session;
		
		$username = $user[$this->settings['username_column']];
		$encoded_username = $this->board->encode_to_utf8($user[$this->settings['username_column']], $this->settings['encode_table'], "users");
		
		// Check if the user, with duplicated username, exists in our MyBB database.
		$where = "username='".$db->escape_string($username)."' OR username='".$db->escape_string($encoded_username)."'";
		$query = $db->simple_select("users", "*", $where, array('limit' => 1));
		$duplicate_user = $db->fetch_array($query);
		$db->free_result($query);
		
		// Using strtolower and my_strtolower to check, instead of in the query, is exponentially faster
		// If we used LOWER() function in the query the index wouldn't be used by MySQL
		if(strtolower($duplicate_user['username']) == strtolower($username) || $this->board->converter_my_strtolower($duplicate_user['username']) == $this->board->converter_my_strtolower($encoded_username))
		{
			// Have to check email in UTF-8 format also.
			$encoded_email = $this->board->encode_to_utf8($user[$this->settings['email_column']], $this->settings['encode_table'], "users");
			$email_pos = empty($duplicate_user['email']) ? -1 : strpos($encoded_email, $duplicate_user['email']);
			$email_length = strlen($duplicate_user['email']);
			if($encoded_email == $duplicate_user['email'] || (defined("DZUCENTER_CONVERTER_USERS_FIX_EMAIL") && DZUCENTER_CONVERTER_USERS_FIX_EMAIL && $email_pos !== false && $email_pos == 0 && $email_length == 32))
			{
				$this->user_found = true;
				return $duplicate_user;
			}
			else
			{
				// Duplicated username with different emails, will add this user.
				return $duplicate_user['username']."_".$import_session['board']."_import".$this->total_mybbusers;
			}
		}
		
		// Not a duplicated user. will add this user.
		return false;
	}
}


