<?php
/**
* Maj Postit Plugin  - Joomla 4.x/5.x Plugin
* Version			: 4.2.2
* copyright 		: Copyright (C) 2023 ConseilGouz. All rights reserved.
* license    		: http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
*/
// No direct access to this file
defined('_JEXEC') or die;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Version;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;

class plgtaskMajPostitInstallerScript
{
    private $min_joomla_version      = '4.1.0';
    private $min_php_version         = '7.4';
    private $name                    = 'Plugin Maj Postit';
    private $exttype                 = 'plugin';
    private $extname                 = 'majpostit';
    private $previous_version        = '';
    private $dir           = null;
    private $lang;
    private $installerName = 'plgtaskmajpostitinstaller';
    public function __construct()
    {
        $this->dir = __DIR__;
        $this->lang = Factory::getApplication()->getLanguage();
        $this->lang->load($this->extname);
    }

    public function preflight($type, $parent)
    {
        if (! $this->passMinimumJoomlaVersion()) {
            $this->uninstallInstaller();
            return false;
        }

        if (! $this->passMinimumPHPVersion()) {
            $this->uninstallInstaller();
            return false;
        }
        // To prevent installer from running twice if installing multiple extensions
        if (! file_exists($this->dir . '/' . $this->installerName . '.xml')) {
            return true;
        }
    }

    public function postflight($type, $parent)
    {
        if (($type == 'install') || ($type == 'update')) { // remove obsolete dir/files
            $this->postinstall_cleanup();
        }

        switch ($type) {
            case 'install': $message = Text::_('ISO_POSTFLIGHT_INSTALLED');
                break;
            case 'uninstall': $message = Text::_('ISO_POSTFLIGHT_UNINSTALLED');
                break;
            case 'update': $message = Text::_('ISO_POSTFLIGHT_UPDATED');
                break;
            case 'discover_install': $message = Text::_('ISO_POSTFLIGHT_DISC_INSTALLED');
                break;
        }
        return true;
    }
    private function postinstall_cleanup()
    {

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $conditions = array(
            $db->qn('type') . ' = ' . $db->q('plugin'),
            $db->qn('folder') . ' = ' . $db->q('task'),
            $db->qn('element') . ' = ' . $db->quote('majpostit')
        );
        $fields = array($db->qn('enabled') . ' = 1');

        $query = $db->getQuery(true);
        $query->update($db->quoteName('#__extensions'))->set($fields)->where($conditions);
        $db->setQuery($query);
        try {
            $db->execute();
        } catch (RuntimeException $e) {
            Log::add('unable to enable plugin majpostit', Log::ERROR, 'jerror');
        }
        // disable system majpostit plugin
        $conditions = array(
            $db->qn('type') . ' = ' . $db->q('plugin'),
            $db->qn('folder') . ' = ' . $db->q('system'),
            $db->qn('element') . ' = ' . $db->quote('majpostit')
        );
        $fields = array($db->qn('enabled') . ' = 0');

        $query = $db->getQuery(true);
        $query->update($db->quoteName('#__extensions'))->set($fields)->where($conditions);
        $db->setQuery($query);
        try {
            $db->execute();
        } catch (RuntimeException $e) {
            Log::add('unable to disable plugin majpostit', Log::ERROR, 'jerror');
        }
        $conditions = array(
            $db->qn('type') . ' = ' . $db->q('plugin'),
            $db->qn('folder') . ' = ' . $db->q('system'),
            $db->qn('element') . ' = ' . $db->quote('majpostit')
        );
        $fields = array($db->qn('enabled') . ' = 0');

        $query = $db->getQuery(true);
        $query->update($db->quoteName('#__extensions'))->set($fields)->where($conditions);
        $db->setQuery($query);
        try {
            $db->execute();
        } catch (RuntimeException $e) {
            Log::add('unable to enable plugin majpostit', Log::ERROR, 'jerror');
        }
        // delete #__update_sites (keep showing update even if system majpostit is disabled)
        $query = $db->getQuery(true);
        $query->select('site.update_site_id')
        ->from($db->quoteName('#__extensions', 'ext'))
        ->join('LEFT', $db->quoteName('#__update_sites_extensions', 'site').' ON site.extension_id = ext.extension_id')
        ->where($db->quoteName('ext.type').'='.$db->quote('plugin'))
        ->where($db->quoteName('ext.folder').'='.$db->quote('system'))
        ->where($db->quoteName('ext.element').'='.$db->quote('majpostit'));
        $db->setQuery($query);
        $upd_id = $db->loadResult();
        if (!$upd_id) {
            return true;
        }
        $conditions = array(
            $db->qn('update_site_id') . ' = ' . $upd_id
        );
        $fields = array($db->qn('enabled') . ' = 0');

        $query = $db->getQuery(true);
        $query->delete($db->quoteName('#__update_sites'))->where($conditions);
        $db->setQuery($query);
        try {
            $db->execute();
        } catch (RuntimeException $e) {
            Log::add('unable to enable plugin majpostit', Log::ERROR, 'jerror');
        }
        // remove obsolete update sites
        $query = $db->getQuery(true)
            ->delete('#__update_sites')
            ->where($db->quoteName('location') . ' like "%432473037d.url-de-test.ws/%"');
        $db->setQuery($query);
        $db->execute();
        // Simple Isotope is now on Github
        $query = $db->getQuery(true)
            ->delete('#__update_sites')
            ->where($db->quoteName('location') . ' like "%conseilgouz.com/updates/plg_task_majpostit%"');
        $db->setQuery($query);
        $db->execute();

    }

    // Check if Joomla version passes minimum requirement
    private function passMinimumJoomlaVersion()
    {
        $j = new Version();
        $version = $j->getShortVersion();
        if (version_compare($version, $this->min_joomla_version, '<')) {
            Factory::getApplication()->enqueueMessage(
                'Incompatible Joomla version : found <strong>' . $version . '</strong>, Minimum : <strong>' . $this->min_joomla_version . '</strong>',
                'error'
            );

            return false;
        }

        return true;
    }

    // Check if PHP version passes minimum requirement
    private function passMinimumPHPVersion()
    {

        if (version_compare(PHP_VERSION, $this->min_php_version, '<')) {
            Factory::getApplication()->enqueueMessage(
                'Incompatible PHP version : found  <strong>' . PHP_VERSION . '</strong>, Minimum <strong>' . $this->min_php_version . '</strong>',
                'error'
            );
            return false;
        }

        return true;
    }
    private function uninstallInstaller()
    {
        if (! is_dir(JPATH_PLUGINS . '/system/' . $this->installerName)) {
            return;
        }
        $this->delete([
            JPATH_PLUGINS . '/system/' . $this->installerName . '/language',
            JPATH_PLUGINS . '/system/' . $this->installerName,
        ]);
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->delete('#__extensions')
            ->where($db->quoteName('element') . ' = ' . $db->quote($this->installerName))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'));
        $db->setQuery($query);
        $db->execute();
        Factory::getApplication()->getCache()->clean('_system');
    }
    public function delete($files = [])
    {
        foreach ($files as $file) {
            if (is_dir($file)) {
                Folder::delete($file);
            }

            if (is_file($file)) {
                File::delete($file);
            }
        }
    }
}
