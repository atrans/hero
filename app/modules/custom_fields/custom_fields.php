<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

/**
* Custom Fields Module Definition
*
* Declares the module, update code, etc.
*
* @author Electric Function, Inc.
* @package Electric Publisher
*
*/

class Custom_fields extends Module {
	var $version = '1.0';
	var $name = 'custom_fields';

	function __construct () {
		// set the active module
		$this->active_module = $this->name;
		
		parent::__construct();
	}
	
	function update($db_version) {
		if ($db_version < 1.0) {
			$this->CI->settings_model->make_writeable_folder(setting('path_custom_field_uploads'),TRUE);
		}
		
		return $this->version;
	}
}