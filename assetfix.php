<?php
/**
 * @version       $Id: 
 * @package       Joomla.AssetFix
 * @copyright     Copyright (C) 2005 - 2012 Open Source Matters, Inc. All rights reserved.
 * @author        Matias Aguirre
 * @email         maguirre@matware.com.ar
 * @link          http://www.matware.com.ar/
 * @license       GNU General Public License version 2 or later; see LICENSE
 */
ini_set('display_errors','1');

// Set flag that this is a parent file.
// We are a valid Joomla entry point.
define('_JEXEC', 1);

// Setup the base path related constants.
define('JPATH_BASE', dirname(__FILE__));
define('JPATH_SITE', JPATH_BASE);
define('JPATH_CONFIGURATION',JPATH_BASE);
define('JPATH_LIBRARIES', dirname(dirname(__FILE__)).'/joomla-platform'   );

// Bootstrap the application.
require JPATH_LIBRARIES . '/libraries/import.php';

// Import the JApplicationWeb class from the platform.
jimport('joomla.application.cli');

/**
 * This class checks some common situations that occur when the asset table is corrupted.
 */
// Instantiate the application.
class AssetFix extends JApplicationCli
{
	/**
	 * Overrides the parent doExecute method to run the web application.
	 *
	 * This method should include your custom code that runs the application.
	 *
	 * @return  void
	 *
	 * @since   11.3
	 */

	public function __construct()
	{
		// Call the parent __construct method so it bootstraps the application class.
		parent::__construct();
		require_once JPATH_CONFIGURATION.'/configuration.php';

		jimport('joomla.database.database');

		// Add the logger.
		JLog::addLogger(
			// Pass an array of configuration options
			array(
				// Set the name of the log file
				'text_file' => JPATH_BASE.'/assetfix.log.php'
			)
		);

		// System configuration.
		$config = JFactory::getConfig();

		// Note, this will throw an exception if there is an error
		// Creating the database connection.
		$this->dbo = JDatabase::getInstance(
			array(
				'driver' => $config->get('dbtype'),
				'host' => $config->get('host'),
				'user' => $config->get('user'),
				'password' => $config->get('password'),
				'database' => $config->get('db'),
				'prefix' => $config->get('dbprefix'),
			)
		);
	}

	protected function doExecute()
	{
		// Backup the tables to modify
		$tables = array('#__assets', '#__categories', '#__content');
		$this->doBackup($tables);

		// Cleanup the asset table
		$this->populateDatabase('./sql/assets.sql');

		// Fixing the extensions assets
		$this->fixExtensionsAssets();

		// Fixing the categories assets
		$this->fixCategoryAssets();

		// Fixing the content assets
		$this->fixContentAssets();
	}

	/**
	 * Backup tables
	 *
	 * @param   array         $tables   Array with the tables to backup
	 * @return	boolean
	 * @since   2.5.0
	 * @throws	Exception
	 */
	protected function doBackup($tables) {

		// Rename the tables
		$count = count($tables);

		for($i=0;$i<$count;$i++) {

			$table = $tables[$i];
			$rename = $tables[$i]."_backup";

			$exists = $this->_existsTable($rename);

			if ($exists == 0) {
				$this->_copyTable($table, $rename);
			}
		}
	}

	/**
	 * Copy table to old site to new site
	 *
	 * @return	boolean
	 * @since 1.1.0
	 * @throws	Exception
	 */
	protected function _copyTable($from, $to=null) {

		// System configuration.
		$config = JFactory::getConfig();
		$database = $config->get('db');

		if (!$to) $to = $from;
		$from = preg_replace ('/#__/', $this->dbo->getPrefix(), $from);
		$to = preg_replace ('/#__/', $this->dbo->getPrefix(), $to);

		$success = $this->_cloneTable($from, $to);
		if ($success) {
			$query = "INSERT INTO {$to} SELECT * FROM {$from}";
			$this->dbo->setQuery($query);
			$this->dbo->query();

			// Check for query error.
			$error = $this->dbo->getErrorMsg();
			if ($error) {
				throw new Exception($error);
			}
			$success = true;
		}

		return $success;
	}

 	/**
	 * Clone table structure from old site to new site
	 *
	 * @return	boolean
	 * @since 1.1.0
	 * @throws	Exception
	 */
	protected function _cloneTable($from, $to=null, $drop=true) {
		// System configuration.
		$config = JFactory::getConfig();
		$database = $config->get('db');

		if (!$to) $to = $from;
		$from = preg_replace ('/#__/', $this->dbo->getPrefix(), $from);
		$to = preg_replace ('/#__/', $this->dbo->getPrefix(), $to);

		$exists = $this->_existsTable($from);

		if($exists == 0) {
			$success = false;
		} else {
			$query = "CREATE TABLE {$to} LIKE {$from}";
			$this->dbo->setQuery($query);
			$this->dbo->query();

			// Check for query error.
			$error = $this->dbo->getErrorMsg();

			if ($error) {
				throw new Exception($error);
			}
			$success = true;
		}

		return $success;
	}

 	/**
	 * existsTable
	 *
	 * @return	boolean
	 * @since 1.1.0
	 * @throws	Exception
	 */
	function _existsTable($table)
	{
		// System configuration.
		$config = JFactory::getConfig();
		$database = $config->get('db');

		$table = preg_replace ('/#__/', $this->dbo->getPrefix(), $table);

		$this->dbo->setQuery("SELECT COUNT(*) AS count
			FROM information_schema.tables
			WHERE table_schema = '{$database}'
			AND table_name = '{$table}'");

		return $this->dbo->loadResult();		
	}

 	/**
	 * populateDatabase
	 *
	 * @return	boolean
	 * @since 1.1.0
	 * @throws	Exception
	 */
	function populateDatabase($sqlfile)
	{
		if( !($buffer = file_get_contents($sqlfile)) )
		{
			return -1;
		}

		$queries = $this->dbo->splitSql($buffer);

		foreach ($queries as $query)
		{
			$query = trim($query);
			if ($query != '' && $query {0} != '#')
			{
				$this->dbo->setQuery($query);
				$this->dbo->query();

				// Check for query error.
				$error = $this->dbo->getErrorMsg();

				if ($error) {
					throw new Exception($error);
				}

			}
		}

		return true;
	}


	protected function fixExtensionsAssets()
	{
		$this->dbo = JFactory::getDBO();

		// Fixing categories assets
		$query = $this->dbo->getQuery(true);
		$query->select('name, element');
		$query->from('#__extensions');
		$query->where("type = 'component'");
		$query->where("protected = 0");
		$query->group('element');
		$this->dbo->setQuery($query);
		$extensions = $this->dbo->loadObjectList();

		// Getting the asset table
		$assetfix = JTable::getInstance('asset');

		foreach($extensions as $extension) {
			$assetfix->id = 0;
			$assetfix->reset();

			$assetfix->loadByName($extension->element);

			if ($assetfix->id == 0) {
				// Setting the name and title
				$assetfix->title = $extension->name;
				$assetfix->name = $extension->element;

				// Getting the original rules
				$query = $this->dbo->getQuery(true);
				$query->select('rules');
				$query->from('#__assets_backup');
				$query->where("name = '{$extension->element}'");
				$this->dbo->setQuery($query);
				$rules = $this->dbo->loadResult();
				$assetfix->rules = $rules !== null ? $rules : '{}';

				// Setting the location of the new category
				$assetfix->setLocation(1, 'last-child');
				$assetfix->store();
			}
		}
	} // end method

	protected function fixCategoryAssets()
	{
		$this->dbo = JFactory::getDBO();

		// Fixing categories assets
		$query = $this->dbo->getQuery(true);
		$query->select('*');
		$query->from('#__categories');
		$query->where('id != 1');
		$query->order('parent_id');
		$this->dbo->setQuery($query);
		$categories = $this->dbo->loadObjectList();
	
		foreach($categories as $category) {

			// Fixing name of the extension
			$category->extension = $category->extension == 'com_contact_details' ? 'com_contact' : $category->extension;

			// Getting the asset table
			$assetfix = JTable::getInstance('asset');

			$assetfix->title = $category->title;
			$assetfix->name = "{$category->extension}.category.{$category->id}";

			// Getting the original rules
			$query = $this->dbo->getQuery(true);
			$query->select('rules');
			$query->from('#__assets_backup');
			$query->where("name = '{$assetfix->name}'");
			$this->dbo->setQuery($query);
			$assetfix->rules = $this->dbo->loadResult();

			// Setting the parent
			$parent = 0;
			if ($category->parent_id !== false) {
				if ($category->parent_id == 1) {
					$parentAsset = JTable::getInstance('asset');
					$parentAsset->loadByName($category->extension);
					$parent = $parentAsset->id;
				} else if ($category->parent_id > 1) {
					// Getting the correct parent
					$query = $this->dbo->getQuery(true);
					$query->select('a.id');
					$query->from('#__categories AS c');
					$query->join('LEFT', '#__assets AS a ON a.title = c.title');
					$query->where("c.id = {$category->parent_id}");
					$this->dbo->setQuery($query);
					$parent = $this->dbo->loadResult();
				}

				// Setting the location of the new category
				$assetfix->setLocation($parent, 'last-child');
			}

			$assetfix->store();

			// Fixing the category asset_id
			$query = $this->dbo->getQuery(true);
			$query->update($this->dbo->quoteName('#__categories'));
			$query->set($this->dbo->quoteName('asset_id') . ' = ' . (int)$assetfix->id);
			$query->where('id = ' . (int) $category->id);
			$this->dbo->setQuery($query);
			$this->dbo->query();
		}
	} //end method

	protected function fixContentAssets()
	{
		$this->dbo = JFactory::getDBO();

		// Fixing articles assets
		$query = $this->dbo->getQuery(true);
		$query->select('*');
		$query->from('#__content');
		$this->dbo->setQuery($query);
		$contents = $this->dbo->loadObjectList();


		foreach($contents as $article) {

			// Getting the asset table
			$assetfix = JTable::getInstance('asset');

			$assetfix->title = $article->title;
			$assetfix->name = "com_content.article.{$article->id}";

			// Getting the original rules
			$query = $this->dbo->getQuery(true);
			$query->select('rules');
			$query->from('#__assets_backup');
			$query->where("name = '{$assetfix->name}'");
			$this->dbo->setQuery($query);
			$assetfix->rules = $this->dbo->loadResult();

			// Setting the parent
			$parent = 0;
			if ($article->catid !== false) {

				if ($article->catid == 1) {
					$parentAsset = JTable::getInstance('asset');
					$parentAsset->loadByName('com_content');
					$parent = $parentAsset->id;
				} else if ($article->catid > 1) {
					// Getting the correct parent
					$query = $this->dbo->getQuery(true);
					$query->select('a.id');
					$query->from('#__categories AS c');
					$query->join('LEFT', '#__assets AS a ON a.title = c.title');
					$query->where("c.id = {$article->catid}");
					$this->dbo->setQuery($query);
					$parent = $this->dbo->loadResult();
				}

				// Setting the location of the new category
				$assetfix->setLocation($parent, 'last-child');
			}

			$assetfix->store();

			// Fixing the category asset_id
			$query = $this->dbo->getQuery(true);
			$query->update($this->dbo->quoteName('#__content'));
			$query->set($this->dbo->quoteName('asset_id') . ' = ' . (int)$assetfix->id);
			$query->where('id = ' . (int) $article->id);
			$this->dbo->setQuery($query);
			$this->dbo->query();
		}

	} // end method

} // end class

JCli::getInstance('AssetFix')->execute();
