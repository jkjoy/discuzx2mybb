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

class DZX25_Converter_Module_Attachments extends Converter_Module_Attachments {
	
	var $settings = array(
			'friendly_name' => 'attachments',
			'progress_column' => 'aid',
			'default_per_screen' => 20,
	);
	
	/**
	 * Used to check if attachments can be read by parent class.
	 */
	public $path_column = "attachment";
	/**
	 * Used to check if attachments can be read by parent class.
	 */
	public $test_table = "forum_attachment_0";
	
	private $thread_cache = array();
	
	function get_upload_path()
	{
		// Get the default Discuz! attachment path/url. You should modify the path in this module's setting page.
		$uploadspath_attachment_dir = '';
		$uploadspath_attachment_url = '';
		$query = $this->old_db->simple_select("common_setting", "skey,svalue", "skey IN('attachdir','attachurl')");
		while($setting = $this->old_db->fetch_array($query))
		{
			if($setting['skey'] == 'attachdir')
			{
				$uploadspath_attachment_dir = $setting['svalue'];
			}
			if($setting['skey'] == 'attachurl')
			{
				$uploadspath_attachment_url = $setting['svalue'];
			}
		}
		$this->old_db->free_result($query);
		
		// We can't know the full web accessible address if the URL is relative.
		$upload_path = $uploadspath_attachment_url;
		if(stripos($upload_path, "http://") === 0 || stripos($upload_path, "https://") === 0 || stripos($upload_path, "ftp://") === 0)
		{
			return $upload_path;
		}
		
		// The last resort, use the path set in the old Discuz! database. However, it's may be unreliable.
		$upload_path = $uploadspath_attachment_dir;
		if(strpos($upload_path, "./") === 0)
		{
			$upload_path = MERGE_ROOT . substr($upload_path, 2);
		}
		$upload_path = realpath($upload_path);
		return $upload_path;
	}
	
	function import()
	{
		global $import_session;
		
		$query = $this->old_db->simple_select("forum_attachment", "*", "", array('limit_start' => $this->trackers['start_attachments'], 'limit' => $import_session['attachments_per_screen']));
		while($attachment = $this->old_db->fetch_array($query))
		{
			$tableid = $attachment['tableid'];
			if($tableid > 9 || $tableid < 0)
			{
				$this->debug->log->warning("import_attachments: wrong tableid '" . $tableid . "' of Discuz! attachment aid #" . $attachment['aid']);
				$this->increment_tracker("attachments");
				continue;
			}
			
			$query_attachment = $this->old_db->simple_select("forum_attachment_" . $tableid, "*", "aid = '{$attachment['aid']}'", array('limit' => 1));
			if(!$this->old_db->num_rows($query_attachment))
			{
				$this->old_db->free_result($query_attachment);
				$this->debug->log->warning("import_attachments: wrong Discuz! attachment aid #" . $attachment['aid'] . " in tableid '" . $tableid . "'");
				$this->increment_tracker("attachments");
				continue;
			}
			$attachment_info = $this->old_db->fetch_array($query_attachment);
			$this->old_db->free_result($query_attachment);
			
			$attachment['table_name'] = "forum_attachment_" . $tableid;
			
			$attachment['dateline'] = $attachment_info['dateline'];
			$attachment['filename'] = $attachment_info['filename'];
			$attachment['filesize'] = $attachment_info['filesize'];
			$attachment['attachment'] = $attachment_info['attachment'];
			$attachment['remote'] = $attachment_info['remote'];
			$attachment['description'] = $attachment_info['description'];
			$attachment['readperm'] = $attachment_info['readperm'];
			$attachment['price'] = $attachment_info['price'];
			$attachment['isimage'] = $attachment_info['isimage'];
			$attachment['width'] = $attachment_info['width'];
			$attachment['thumb'] = $attachment_info['thumb'];
			$attachment['picid'] = $attachment_info['picid'];
			
			$this->insert($attachment);
		}
	}
	
	public function insert($data)
	{
		parent::insert($data);
		
		flush();
	}
	
	function convert_data($data)
	{
		$insert_data = array();
		
		// Discuz! values.
		$insert_data['import_aid'] = $data['aid'];
		
		$post_details = $this->get_import->post_attachment_details($data['pid']);
		if(empty($post_details))
		{
			$insert_data['pid'] = 0;
			$insert_data['uid'] = 0;
		}
		else
		{
			$insert_data['pid'] = $post_details['pid'];
			$insert_data['uid'] = $post_details['uid'];
		}
		
		$insert_data['filename'] = $this->board->encode_to_utf8($data['filename'], $data['table_name'], "attachments");
		$insert_data['filesize'] = $data['filesize'];
		
		$month_dir = gmdate("Ym", $data['dateline']);
		$insert_data['attachname'] = $month_dir . "/post_".$insert_data['uid']."_".$data['dateline']."_".md5(random_str()).".attach";
		
		$insert_data['downloads'] = $data['downloads'];
		$insert_data['dateuploaded'] = $data['dateline'];
		$insert_data['visible'] = 1;

		$filetype = "application/octet-stream";
		$ext = get_extension($data['filename']);
		if(!empty($ext))
		{
			require_once dirname(__FILE__)."/mime_types.php";
			
			$filetype = MIME_TYPE::get_mime_type($ext);
			if(empty($filetype))
			{
				$filetype = "application/octet-stream";
			}
		}
			
		$insert_data['filetype'] = $filetype;
		// Check if this is an image
		if($data['isimage'] == 1)
		{
			$insert_data['thumbnail'] = 'SMALL';
		}
		else
		{
			$insert_data['thumbnail'] = '';
		}
		
		return $insert_data;
	}
	
	function generate_raw_filename($attachment)
	{
		return "forum/" . $attachment['attachment'];
	}
	
	function after_insert($unconverted_data, $converted_data, $aid)
	{
		global $mybb, $import_session, $lang, $db;
		
		// Transfer attachment
		if(!SKIP_ATTACHMENT_FILES)
		{
			// Try to save attachements in folders by yearmonth as MyBB does.
			$full_attachment_path = $mybb->settings['uploadspath'].'/'.$converted_data['attachname'];
			$full_upload_path = dirname($full_attachment_path);
			if(!is_dir($full_upload_path))
			{
				if(mkdir($full_upload_path, 0755, true))
				{
					$this->debug->log->event("import_attachments: folder created in path \"" . $full_upload_path . "\"");
				}
				else
				{
					// Cannot create yearmonth folder, reset attachments to be just under MyBB's upload path.
					$this->board->set_error_notice_in_progress("import_attachments: can't create folder in path \"" . $full_upload_path . "\", place it just under MyBB's upload path");
					$converted_data['attachname'] = basename($converted_data['attachname']);
					$db->update_query("attachments", array('attachname' => $converted_data['attachname']), "aid='{$aid}'");
				}
			}
			
			/** At this point, 
			 * $mybb->settings['uploadspath'].'/'.$converted_data['attachname']
			 * should be equal to 
			 * $full_upload_path.'/'.basename($converted_data['attachname'])
			 */
			
			$file_data = $this->get_file_data($unconverted_data);
			if(!empty($file_data))
			{
				$attachrs = @fopen($mybb->settings['uploadspath'].'/'.$converted_data['attachname'], 'w');
				if($attachrs)
				{
					@fwrite($attachrs, $file_data);
				}
				else
				{
					$this->board->set_error_notice_in_progress($lang->sprintf($lang->module_attachment_error, $aid));
				}
				@fclose($attachrs);
				
				@my_chmod($mybb->settings['uploadspath'].'/'.$converted_data['attachname'], '0777');
				
				if($import_session['attachments_create_thumbs']) {
					require_once MYBB_ROOT."inc/functions_image.php";
					$ext = my_strtolower(my_substr(strrchr($converted_data['filename'], "."), 1));
					if($ext == "gif" || $ext == "png" || $ext == "jpg" || $ext == "jpeg" || $ext == "jpe")
					{
						$thumbname = str_replace(".attach", "_thumb.$ext", $converted_data['attachname']);
						$thumbnail = generate_thumbnail($mybb->settings['uploadspath'].'/'.$converted_data['attachname'], $mybb->settings['uploadspath'], $thumbname, $mybb->settings['attachthumbh'], $mybb->settings['attachthumbw']);
						if($thumbnail['code'] == 4)
						{
							$thumbnail['filename'] = "SMALL";
						}
						$db->update_query("attachments", array("thumbnail" => $thumbnail['filename']), "aid='{$aid}'");
					}
				}
				
				if(defined("DZX25_CONVERTER_DZX_UPLOAD_RECHECK_MIME_TYPE") && DZX25_CONVERTER_DZX_UPLOAD_RECHECK_MIME_TYPE)
				{
					// Update attachment's mime_type.
					$filetype = mime_content_type($mybb->settings['uploadspath'].'/'.$converted_data['attachname']);
					if(!empty($filetype))
					{
						$db->update_query("attachments", array('filetype' => $filetype), "aid='{$aid}'");
					}
				}
			}
			else
			{
				$this->board->set_error_notice_in_progress($lang->sprintf($lang->module_attachment_not_found, $aid));
			}
		}
		
		if(!isset($this->thread_cache[$converted_data['pid']])) {
			$query = $db->simple_select("posts", "tid", "pid={$converted_data['pid']}");
			$this->thread_cache[$converted_data['pid']] = $db->fetch_field($query, "tid");
		}
		// TODO: This may not work with SQLite/PgSQL
		$db->write_query("UPDATE ".TABLE_PREFIX."threads SET attachmentcount = attachmentcount + 1 WHERE tid = '".$this->thread_cache[$converted_data['pid']]."'");
	}
	
	function dz_get_attachment_fileurl($import_aid)
	{
		$query = $this->old_db->simple_select("forum_attachment", "tableid", "aid = '{$import_aid}'", array('limit' => 1));
		$tableid = $this->old_db->fetch_field($query, "tableid");
		$this->old_db->free_result($query);
		
		$query = $this->old_db->simple_select("forum_attachment_" . $tableid, "attachment", "aid = '{$import_aid}'", array('limit' => 1));
		$attachment = $this->old_db->fetch_field($query, "attachment");
		$this->old_db->free_result($query);
		
		if(empty($attachment))
		{
			return false;
		}
		return $attachment;
	}
	
	function finish()
	{
		global $import_session, $db;
		
		// Generate redirect file for users module, if permitted.
		if(defined("DZX25_CONVERTER_GENERATE_REDIRECT") && DZX25_CONVERTER_GENERATE_REDIRECT && !empty($import_session['DZX25_Redirect_Files_Path']) && $import_session['total_attachments'])
		{
			// Check numbers of forums with import_fid > 0.
			$query = $db->simple_select("forums", "COUNT(*) as count", "import_fid > 0");
			$total_imported_forums = $db->fetch_field($query, 'count');
			$db->free_result($query);
			
			// Check numbers of threads with import_tid > 0.
			$query = $db->simple_select("threads", "COUNT(*) as count", "import_tid > 0");
			$total_imported_threads = $db->fetch_field($query, 'count');
			$db->free_result($query);
			
			// Check numbers of attachments with import_tid > 0.
			$query = $db->simple_select("attachments", "COUNT(*) as count", "import_aid > 0");
			$total_imported_attachments = $db->fetch_field($query, 'count');
			$db->free_result($query);
			
			require_once dirname(__FILE__). '/generate_redirect.php';
			
			$redirector = new DZX25_Redirect_Generator();
			$redirector->generate_file('attachments', $total_imported_forums + $total_imported_threads + $total_imported_attachments);
			
			$redirector->write_file("\t\t\t'forum' => array(\n");
			
			$start = 0;
			while($start < $total_imported_forums)
			{
				$count = 0;
				$query = $db->simple_select("forums", "fid,import_fid", "import_fid > 0", array('limit_start' => $start, 'limit' => 1000));
				while($forum = $db->fetch_array($query))
				{
					$record = "\t\t\t\t\t";
					$record .= "'{$forum['import_fid']}' => {$forum['fid']}";
					$redirector->write_record($record);
					$count++;
				}
				$start += $count;
			}
			@$db->free_result($query);
			$redirector->write_file("\t\t\t),\n");
			$redirector->write_file("\t\t\t'thread' => array(\n");
			
			$start = 0;
			while($start < $total_imported_threads)
			{
				$count = 0;
				$query = $db->simple_select("threads", "tid,import_tid", "import_tid > 0", array('limit_start' => $start, 'limit' => 1000));
				while($thread = $db->fetch_array($query))
				{
					$record = "\t\t\t\t\t";
					$record .= "'{$thread['import_tid']}' => {$thread['tid']}";
					$redirector->write_record($record);
					$count++;
				}
				$start += $count;
			}
			@$db->free_result($query);
			$redirector->write_file("\t\t\t),\n");
			$redirector->write_file("\t\t\t'attachment' => array(\n");
			
			$redirector_attfiles = new DZX25_Redirect_Generator();
			$redirector_attfiles->generate_file('attachment_files', $total_imported_attachments);
			
			$start = 0;
			while($start < $total_imported_attachments)
			{
				$count = 0;
				$query = $db->simple_select("attachments", "aid,import_aid", "import_aid > 0", array('limit_start' => $start, 'limit' => 1000));
				while($attachment = $db->fetch_array($query))
				{
					$record = "\t\t\t\t\t";
					$record .= "'{$attachment['import_aid']}' => {$attachment['aid']}";
					$redirector->write_record($record);
					
					// Attachment files.
					$attachment_file = $this->dz_get_attachment_fileurl($attachment['import_aid']);
					if($attachment_file !== false)
					{
						$attachment_file = str_replace('\'', '\\\'', $attachment_file);
						$record_file = "\t\t\t";
						$record_file .= "'{$attachment_file}' => {$attachment['aid']}";
						$redirector_attfiles->write_record($record_file);
					}
					
					$count++;
				}
				$start += $count;
			}
			@$db->free_result($query);
			$redirector->write_file("\t\t\t),\n");
			
			$redirector->finish_file();
			$redirector_attfiles->finish_file();
		}
	}
	
	function fetch_total()
	{
		global $import_session;
		
		// Get number of attachments
		if(!isset($import_session['total_attachments']))
		{
			$query = $this->old_db->simple_select("forum_attachment", "COUNT(*) as count", "");
			$import_session['total_attachments'] = $this->old_db->fetch_field($query, 'count');
			$this->old_db->free_result($query);
		}
		
		return $import_session['total_attachments'];
	}
}


