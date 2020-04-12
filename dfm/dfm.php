<?php

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\User\User;

class plgSystemDfm extends CMSPlugin
{
    /**
     * @var \JDatabaseDriver
     */
    public $db;

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

    public function onUserAfterSave (array $old_data, bool $isNew, bool $success)
    {
        //set current date for trial start
        $user = JFactory::getUser($old_data['id']);
        if ($success && $isNew) {
            $this->setUserField($user, $this->params['trial_date_field'], (new DateTime())->format('Y-m-d 00:00:00'));
        }
    }

    public function onNewLicenseKey (string $key, array $dr_data): array
    {
        $email = $dr_data['EMAIL'];
        if (!$user = $this->getUserByEmail($email)) {
            return [0, sprintf('User with email %s not found', $email),];
        }
        if (!$this->setUserField($user, $this->params['license_key_field'], $key)) {
            return [0, sprintf('License key field (%s) could not be set', $this->params['license_key_field']),];
        }
        //all good, return id
        return [(int)$user->id, '',];
    }

    public function getActiveLicenseKey (User $user): ?string
    {
        if ($user->id) {
            //get key from field
            if ($field = $this->getUserField($user, $this->params['license_key_field']) and $key = $field->rawvalue) {
                return $key;
            }
            //valid trial?
            if ($field = $this->getUserField($user, $this->params['trial_date_field'])
                and $this->trialDateValid($field->rawvalue)) {
                return $this->params['trial_license_key'];
            }
        }
        return null;
    }

    protected function getUserByEmail (string $email): ?User
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__users'))
            ->where($this->db->quoteName('email') . ' = ' . $this->db->quote($email));
        $this->db->setQuery($query);

        if ($id = $this->db->loadResult()) {
            $user = JFactory::getUser($id);;
            return $user;
        } else {
            return null;
        }
    }

    protected function setUserField (User $user, string $name, string $value): bool
    {
        // Loading the model
        BaseDatabaseModel::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_fields/models', 'FieldsModel');
        /** @var FieldsModelField $model */
        $model = BaseDatabaseModel::getInstance('Field', 'FieldsModel', array('ignore_request' => true));
        if ($field = $this->getUserField($user, $name)) {
            return $model->setFieldValue($field->id, $user->id, $value);
        }
        return false;
    }

    protected function getUserField (User $user, string $name): ?object
    {
        foreach (FieldsHelper::getFields('com_users.user', $user) as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }
        return null;
    }

    protected function trialDateValid (string $trialDate): bool
    {
        if (!$trialDate) {
            return false;
        }
        try {
            $date = new \DateTime($trialDate);
            $validTill = $date->add(new \DateInterval($this->params['trial_license_duration']));
            return new DateTime() < $validTill;
        } catch (Exception $e) {
            return false;
        }
    }
}
