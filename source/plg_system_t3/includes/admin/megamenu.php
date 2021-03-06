<?php
/** 
 *------------------------------------------------------------------------------
 * @package       T3 Framework for Joomla!
 *------------------------------------------------------------------------------
 * @copyright     Copyright (C) 2004-2013 JoomlArt.com. All Rights Reserved.
 * @license       GNU General Public License version 2 or later; see LICENSE.txt
 * @authors       JoomlArt, JoomlaBamboo, (contribute to this project at github 
 *                & Google group to become co-author)
 * @Google group: https://groups.google.com/forum/#!forum/t3fw
 * @Link:         http://t3-framework.org 
 *------------------------------------------------------------------------------
 */

class T3AdminMegamenu
{
	public static function display()
	{
		T3::import('menu/megamenu');
		$input = JFactory::getApplication()->input;
		
		//params
		$tplparams = $input->get('tplparams', '', 'raw');
		
		//menu type
		$menutype = $input->get('t3menu', 'mainmenu');
		
		//viewLevels
		$viewLevels  = self::getViewLevels();
		$accessLevel = $input->get('t3acl', array(), 'array');

		$viewLevels  = array_merge($viewLevels, (array)$accessLevel);
		$viewLevels  = array_unique($viewLevels);
		sort($viewLevels);

		//languages
		$languages = array(trim($input->get('t3lang', '*')));
		if($languages[0] != '*'){
			$languages[] = '*';
		}
		
		//check config
		$file          = T3_TEMPLATE_PATH . '/etc/megamenu.ini';
		$currentconfig = json_decode(@file_get_contents ($file), true);
		if(!$currentconfig){ //just for compatible
			$currentconfig = $tplparams instanceof JRegistry ? json_decode($tplparams->get('mm_config', ''), true) : null;
		}
		
		$mmkey                = $menutype . (empty($viewLevels) ? '' : implode('-', $viewLevels)); //just for compatible
		$mmconfig             = ($currentconfig && isset($currentconfig[$mmkey])) ? $currentconfig[$mmkey] : array();
		$mmconfig['editmode'] = true;
		$mmconfig['access']   = $viewLevels;
		$mmconfig['language'] = $languages;
		
		//build the menu
		$menu   = new T3MenuMegamenu($menutype, $mmconfig);
		$buffer = $menu->render(true);
		
		// replace image path
		$base      = JURI::base(true) . '/';
		$protocols = '[a-zA-Z0-9]+:'; //To check for all unknown protocals (a protocol must contain at least one alpahnumeric fillowed by :
		$regex     = '#(src)="(?!/|' . $protocols . '|\#|\')([^"]*)"#m';
		$buffer    = preg_replace($regex, "$1=\"$base\$2\"", $buffer);
		
		//remove invisibile content	
		$buffer = preg_replace(array(
			'@<style[^>]*?>.*?</style>@siu',
			'@<script[^>]*?.*?</script>@siu'
		), array(
			'',
			''
		), $buffer);

		//output the megamenu key to save
		echo $buffer . '<input id="megamenu-key" type="hidden" name="mmkey" value="' . $mmkey . '"/>';
	}
	
	public static function save()
	{
		$input         = JFactory::getApplication()->input;
		$mmconfig      = $input->getString('config');
		$mmkey         = $input->get('mmkey', $input->get('menutype', 'mainmenu'));
		$file          = T3_TEMPLATE_PATH . '/etc/megamenu.ini';
		$currentconfig = json_decode(@file_get_contents($file), true);
		
		if (!$currentconfig) {
			$currentconfig = array();
		}

		$currentconfig[$mmkey] = json_decode($mmconfig, true);
		$currentconfig         = json_encode($currentconfig);
		$return                = JFile::write($file, $currentconfig);

		die(json_encode(array(
					'status' => $return,
					'message' => JText::_($return ? 'T3_NAVIGATION_SAVE_SUCCESSFULLY' : 'T3_NAVIGATION_SAVE_FAILED')
				)
			)
		);
	}
	
	/**
	 *
	 * Ge all available modules
	 */
	public static function menus()
	{
		$db = JFactory::getDbo();
		$query = $db->getQuery(true)
			->select('menutype AS value, title AS text')
			->from($db->quoteName('#__menu_types'))
			->order('title');
		$db->setQuery($query);
		$menus = $db->loadObjectList();

		$query = $db->getQuery(true)
			->select('menutype, language')
			->from($db->quoteName('#__menu'))
			->where('home = 1');
		$db->setQuery($query);
		$menulangs = $db->loadAssocList('menutype');

		if(is_array($menus) && is_array($menulangs)){
			foreach ($menus as $menu) {
				$menu->language = isset($menulangs[$menu->value]) ? $menulangs[$menu->value]['language'] : '*';
			}
		}
		
		return is_array($menus) ? $menus : array();
	}

	/**
	 *
	 * Ge all available modules
	 */
	public static function modules()
	{
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query
			->select('id, title, module, position')
			->from('#__modules')
			->where('published = 1')
			->where('client_id = 0')
			->order('title');
		$db->setQuery($query);
		$modules = $db->loadObjectList();
		
		return is_array($modules) ? $modules : array();
	}
	
	/**
	 *
	 * Ge template style params
	 */
	public static function getparams($id = null)
	{
		if (!$id) {
			$id = JFactory::getApplication()->input->getCmd('id', '');
		}
		
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query
			->select('template, params')
			->from('#__template_styles')
			->where('client_id = 0')
			->where('id = ' . $id);
		$db->setQuery($query);
		$template = $db->loadObject();
		
		if ($template) {
			$registry = new JRegistry;
			$registry->loadString($template->params);
			
			return $registry;
		}
		
		return null;
	}
	
	/**
	 *
	 * Show thememagic form
	 */
	public static function megamenu()
	{
		$tplparams = self::getparams();
		
		$url = JFactory::getURI();
		$url->delVar('t3action');
		$url->delVar('t3task');
		$referer = $url->toString();
		
		
		//Keepalive
		$config      = JFactory::getConfig();
		$lifetime    = ($config->get('lifetime') * 60000);
		$refreshTime = ($lifetime <= 60000) ? 30000 : $lifetime - 60000;
		
		// Refresh time is 1 minute less than the liftime assined in the configuration.php file.
		// The longest refresh period is one hour to prevent integer overflow.
		if ($refreshTime > 3600000 || $refreshTime <= 0) {
			$refreshTime = 3600000;
		}

		//check config
		$file          = T3_TEMPLATE_PATH . '/etc/megamenu.ini';
		$currentconfig = @file_get_contents ($file);
		if(!$currentconfig){ //just for compatible
			$currentconfig = $tplparams instanceof JRegistry ? $tplparams->get('mm_config', '') : null;
		}
		if(!$currentconfig){
			$currentconfig = '"{}"';
		}
		
		$langs = array(
			'addTheme' => JText::_('T3_TM_ASK_ADD_THEME'),
			'delTheme' => JText::_('T3_TM_ASK_DEL_THEME'),
			'overwriteTheme' => JText::_('T3_TM_ASK_OVERWRITE_THEME'),
			'correctName' => JText::_('T3_TM_ASK_CORRECT_NAME'),
			'themeExist' => JText::_('T3_TM_EXISTED'),
			'saveChange' => JText::_('T3_TM_ASK_SAVE_CHANGED'),
			'previewError' => JText::_('T3_TM_PREVIEW_ERROR'),
			'unknownError' => JText::_('T3_MSG_UNKNOWN_ERROR'),
			'lblCancel' => JText::_('JCANCEL'),
			'lblOk' => JText::_('T3_TM_LABEL_OK'),
			'lblNo' => JText::_('JNO'),
			'lblYes' => JText::_('JYES'),
			'lblDefault' => JText::_('JDEFAULT')
		);
		
		include T3_ADMIN_PATH . '/admin/megamenu/megamenu.tpl.php';
		exit;
	}
	
	public static function getViewLevels()
	{
		// Get all groups that the user is mapped to recursively.
		$userId     = 0; //guest
		$groups     = JAccess::getGroupsByUser($userId);
		$viewLevels = array();
		
		// Get a database object.
		$db = JFactory::getDbo();
		
		// Build the base query.
		$query = $db->getQuery(true)->select('id, rules')->from($db->quoteName('#__viewlevels'));
		
		// Set the query for execution.
		$db->setQuery($query);
		
		// Build the view levels array.
		foreach ($db->loadAssocList() as $level) {
			$viewLevels[$level['id']] = (array) json_decode($level['rules']);
		}
		
		// Initialise the authorised array.
		$authorised = array(1);
		
		// Find the authorised levels.
		foreach ($viewLevels as $level => $rule) {
			foreach ($rule as $id) {
				if (($id < 0) && (($id * -1) == $userId)) {
					$authorised[] = $level;
					break;
				}
				// Check to see if the group is mapped to the level.
				elseif (($id >= 0) && in_array($id, $groups)) {
					$authorised[] = $level;
					break;
				}
			}
		}
		
		return $authorised;
	}
}
