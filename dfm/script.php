<?php

defined('_JEXEC') or die;

class plgSystemDfmInstallerScript
{
    public function install($parent) {}

    public function uninstall($parent) {}

    public function update($parent) {}

    public function preflight($type, $parent) {}

    public function postflight($type, $parent)
    {
        if (!in_array($type, ['install', 'update'])) {
            return;
        }

        JFactory::getDBO()->setQuery(
            "UPDATE #__extensions SET "
            .($type == 'install' ? 'enabled = 1, ' : '')
            ."ordering = 999 WHERE type = 'plugin' AND folder = 'system' AND element = 'dfm'"
        )->execute();
    }
}
