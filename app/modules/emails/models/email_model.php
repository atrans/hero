<?php
/**
* Email Model 
*
* Contains all the methods used to create, update, and delete system emails.
*
* @author Electric Function, Inc.
* @copyright Electric Function, Inc.
* @package Electric Framework
*
*/

class Email_model extends CI_Model
{
	private $cache;
	
	function Email_model()
	{
		parent::CI_Model();
	}
	
	/**
	* Send Mail from the Queue
	*/
	function mail_queue () {
		$CI =& get_instance();
		set_time_limit(500);
		
		$mail_queue_limit = setting('mail_queue_limit');
		if (empty($mail_queue_limit) or !is_numeric($mail_queue_limit) or $mail_queue_limit > 5000) {
			$mail_queue_limit = 450;
		}
		
		// get mail from queue		
		$this->db->select('*');
		$this->db->from('mail_queue');
		$this->db->order_by('date','ASC');
		$this->db->limit($mail_queue_limit);
		$result = $this->db->get();
		
		if ($result->num_rows() == 0) {
			// nothing in the queue
			// delete all mail queue files
			$mail_queue_folder = setting('path_writeable') . 'mail_queue';
			
			$CI->load->helper('directory');
			$files = directory_map($mail_queue_folder);
			
			if (is_array($files)) {
				foreach ($files as $file) {
					// is this a queue file?
					if (strpos($file, '.email') !== FALSE) {
						// unnecessary but basic checks to make sure we won't wipe out the entire file system
						if (!empty($mail_queue_folder) and strpos($mail_queue_folder,'.') !== 0 and $file != '.' and strpos($file,'.') !== 0) {
							unlink($mail_queue_folder . '/' . $file);
						}
					}
				}
			}
			
			return FALSE;
		}
		
		// store the previous body here, so we don't keep having to access the
		// writeable/mail_queue/*.email files
		$previous_body = '';
		$previous_body_file = '';
		
		foreach ($result->result_array() as $mail) {
			$config = array();
			
			$config['mailtype'] = ($mail['is_html'] == '1') ? 'html' : 'text';
			$config['wordwrap'] = ($mail['wordwrap'] == '1') ? FALSE : TRUE;
			
			$CI->email->initialize($config);
			
			// To: 
			if (strpos($mail['to'],',') !== FALSE) {
				// we have multiple emails
				$emails = explode(',', $mail['to']);
				$mail['to'] = array();
				foreach ($emails as $email) {
					$mail['to'][] = $email;
				}
			}
			
			$CI->email->to($mail['to']);
			
			// From: 
			$CI->email->from($mail['from_email'], $mail['from_name']);
			
			// Build Subject
			$subject = $mail['subject'];
			$CI->email->subject($subject);
			
			// Build Body
			if (empty($previous_body_file) or empty($previous_body) or ($mail['body'] != $previous_body_file)) {
				// read body from file
				$CI->load->helper('file');
				$mail_queue_folder = setting('path_writeable') . 'mail_queue';
				
				$body = read_file($mail_queue_folder . '/' . $mail['body']);
				
				$previous_body_file = $mail['body'];
				$previous_body = $body;
			}
			else {
				$body = $previous_body;
			}
			
			$CI->email->message((string)$body);
			
			// Send!
			$CI->email->send();
			$CI->email->clear();
			
			$this->db->delete('mail_queue', array('mail_queue_id' => $mail['mail_queue_id']));
		}
	}
	
	/**
	* Update Layout
	*
	* Updates the main email_layout.thtml template.  It will be created if it doesn't exist,
	*
	* @param string $html
	*/
	function update_layout ($html) {
		$this->load->helper('file');
		
		$filename = setting('path_email_templates') . '/email_layout.thtml';
		
		// create file if it doesn't exist
		if (!file_exists($filename)) {
			write_file($filename, '');
		}
		
		// update file with $html
		if (write_file($filename, $html)) {
			return TRUE;
		}
		else {
			if (!is_writeable($filename)) {
				die(show_error('email_layout.thtml is not writeable.  Please make sure ' . setting('path_email_templates') . ' and all its file are writeable.'));
			}
			return FALSE;
		}
	}
	
	function new_email ($hook, $parameters = array(), $to = array(), $bcc = array(), $subject, $body, $is_html = TRUE) {
		$insert_fields = array(
							'hook_name' => $hook,
							'email_parameters' => serialize($parameters),
							'email_subject' => $subject,
							'email_recipients' => serialize($to),
							'email_bccs' => serialize($bcc),
							'email_is_html' => $is_html
						);
						
		$this->db->insert('emails', $insert_fields);
		
		$email_id = $this->db->insert_id();
		
		if ($is_html == TRUE) {
			// add the standard formatting to the body
			$body = '{extends file="email_layout.thtml"}
			
{block name="body"}' . "\n\n" . $body . "\n\n" . '{/block}';
		}
		
		// create template files
		$this->load->helper('file');
		if (!write_file(setting('path_email_templates') . '/' . $hook . '_' . $email_id . '_subject.thtml', $subject)) {
			if (!is_writeable(setting('path_email_templates') . '/' . $hook . '_' . $email_id . '_subject.thtml')) {
				die(show_error(setting('path_email_templates') . '/' . $hook . '_' . $email_id . '_subject.thtml is not writeable.  Please make sure ' . setting('path_email_templates') . ' and all its file are writeable.'));
			}
		}
		if (!write_file(setting('path_email_templates') . '/' . $hook . '_' . $email_id . '_body.thtml', $body)) {
			if (!is_writeable(setting('path_email_templates') . '/' . $hook . '_' . $email_id . '_body.thtml')) {
				die(show_error(setting('path_email_templates') . '/' . $hook . '_' . $email_id . '_body.thtml is not writeable.  Please make sure ' . setting('path_email_templates') . ' and all its file are writeable.'));
			}
		}
		
		// update record
		$update_fields = array(
						'email_subject_template' => $hook . '_' . $email_id . '_subject.thtml',
						'email_body_template' => $hook . '_' . $email_id . '_body.thtml'
						);
						
		$this->db->update('emails', $update_fields, array('email_id' => $email_id));
				
		return $email_id;
	}
	
	function update_email ($email_id, $hook, $parameters = array(), $to = array(), $bcc = array(), $subject, $body, $is_html = TRUE) {
		$update_fields = array(
							'hook_name' => $hook,
							'email_parameters' => serialize($parameters),
							'email_subject' => $subject,
							'email_recipients' => serialize($to),
							'email_bccs' => serialize($bcc),
							'email_is_html' => $is_html
						);
						
		$this->db->update('emails', $update_fields, array('email_id' => $email_id));
		
		// create template files
		$this->load->helper('file');
		if (!write_file(setting('path_email_templates') . '/' . $hook . '_' . $email_id . '_subject.thtml', $subject)) {
			if (!is_writeable(setting('path_email_templates') . '/' . $hook . '_' . $email_id . '_subject.thtml')) {
				die(show_error(setting('path_email_templates') . '/' . $hook . '_' . $email_id . '_subject.thtml is not writeable.  Please make sure ' . setting('path_email_templates') . ' and all its file are writeable.'));
			}
		}
		if (!write_file(setting('path_email_templates') . '/' . $hook . '_' . $email_id . '_body.thtml', $body)) {
			if (!is_writeable(setting('path_email_templates') . '/' . $hook . '_' . $email_id . '_body.thtml')) {
				die(show_error(setting('path_email_templates') . '/' . $hook . '_' . $email_id . '_body.thtml is not writeable.  Please make sure ' . setting('path_email_templates') . ' and all its file are writeable.'));
			}
		}
						
		return TRUE;
	}
	
	function delete_email ($email_id) {
		$this->db->update('emails', array('email_deleted' => '1'), array('email_id' => $email_id));
		
		return TRUE;
	}
	
	function get_email ($email_id) {
		if (isset($this->cache[$email_id])) {
			return $this->cache[$email_id];
		}
	
		$email = $this->get_emails(array('id' => $email_id));
		
		if (empty($email)) {
			return FALSE;
		}
		
		$this->cache[$email_id] = $email[0];
		return $email[0];
	}
	
	function get_emails ($filters = array()) {
		if (isset($filters['hook'])) {
			$this->db->where('hook_name', $filters['hook']);
		}
		
		if (isset($filters['id'])) {
			$this->db->where('email_id', $filters['id']);
		}
	
		$this->db->where('email_deleted','0');
		$result = $this->db->get('emails');
		
		if ($result->num_rows() == 0) {
			return FALSE;
		}
		
		$emails = array();
		foreach ($result->result_array() as $email) {
			$recipients = unserialize($email['email_recipients']);
			$bccs = unserialize($email['email_bccs']);
			
			$other_recipients = array();
			foreach ($recipients as $to) {
				if ($to != 'member' and $to != 'admin') {
					$other_recipients[] = $to;
				}
			}
			$other_recipients = implode(', ', $other_recipients);
			
			$other_bccs = array();
			foreach ($bccs as $bcc) {
				if ($bcc != 'member' and $bcc != 'admin') {
					$other_bccs[] = $bcc;
				}
			}
			$other_bccs = implode(', ', $bccs);
			
			$parameters = (!empty($email['email_parameters'])) ? unserialize($email['email_parameters']) : FALSE;
			
			$parameters_string = array();

			if (!empty($parameters)) {
				foreach ($parameters as $param => $value) {
					$parameters_string[] = '[' . $param . ' ' . $value . ']';
				}
			}
			$parameters_string = implode(', ', $parameters_string);
		
			$emails[] = array(
								'id' => $email['email_id'],
								'hook' => $email['hook_name'],
								'parameters' => $parameters,
								'parameters_string' => $parameters_string,
								'subject' => $email['email_subject'],
								'subject_template' => $email['email_subject_template'],
								'body_template' => $email['email_body_template'],
								'recipients' => $recipients,
								'bccs' => $bccs,
								'is_html' => ($email['email_is_html'] == '1') ? TRUE : FALSE,
								'other_recipients' => $other_recipients,
								'other_bccs' => $other_bccs
							);
		}
		
		return $emails;
	}
}