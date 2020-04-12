<?php

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Plugin\CMSPlugin;

class plgSystemDfm extends CMSPlugin
{
    /**
     * @var \JDatabaseDriver
     */
    public $db;

    /**
     * @var CMSApplication
     */
    public $app;

    /**
     * Constructor.
     *
     * @param \JEventDispatcher $subject
     * @param array             $config
     */
    public function __construct(&$subject, $config = array())
    {
        parent::__construct($subject, $config);

    }

    public function onUserAfterSave (array $user_data, bool $isNew, bool $success)
    {
        $user = JFactory::getUser($user_data['id']);
        $fields = FieldsHelper::getFields('com_users.user', $user);

        return true;
    }
}
