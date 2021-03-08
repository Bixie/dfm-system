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
            $this->setTrialStartDate($user, new DateTime());
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
        return [(int)$user->id, sprintf('License key added to user %s', $user->name),];
    }

    public function onCheckCsiSubscription (User $user): bool
    {
        try {
            if ($field = $this->getUserField($user, $this->params['csi_email_field']) and $email = $field->rawvalue) {
                $response = file_get_contents(str_replace('{email}', $email, $this->params['csi_check_url']));
                return (bool) $response;
            }
        } catch (Exception $e) {
            //ignore
        }
        return false;
    }

    public function getActiveLicenseKey (User $user): array
    {
        if ($user->id) {
            ['key' => $key, 'isTrial' => $isTrial,] = $this->getLicenseInfo($user);
            return [($isTrial ? $this->params['trial_license_key'] : $key), $isTrial,];
        }
        return [null, false,];
    }

    public function getUserDfmAppData (User $user): array
    {
        ['key' => $key, 'isTrial' => $isTrial, 'trialEnd' => $trialEnd,] = $this->getLicenseInfo($user);
        $userData = [
            'noLicense' => !$key,
            'isTrial' => $isTrial,
            'trialEnd' => $trialEnd,
            'csiActive' => false,
            'fields' => [
                'license_key' => $key,
                'csi_email' => '',
                'gameplans' => [],
            ],
        ];
        if ($field = $this->getUserField($user, $this->params['csi_email_field']) and $email = $field->rawvalue) {
            $userData['fields']['csi_email'] = $email;
            $userData['csiActive'] = !empty($email);
        }
        if ($field = $this->getUserField($user, $this->params['gameplans_field']) and $json = $field->rawvalue) {
            $userData['fields']['gameplans'] = json_decode($json, true);
        }
        return $userData;
    }

    public function updateUserField (User $user, string $field_name, string $value): bool
    {
        $field_names = [
            'license_key' => $this->params['license_key_field'],
            'gameplans' => $this->params['gameplans_field'],
            'watchlists' => $this->params['watchlists_field'],
            'csi_email' => $this->params['csi_email_field'],
        ];
        if (!isset($field_names[$field_name])) {
            return false;
        }
        return $this->setUserField($user, $field_names[$field_name], $value);
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

    protected function getLicenseInfo (User $user): array
    {
        $license = ['key' => null, 'isTrial' => false, 'trialEnd' => null,];
        //get key from field
        if ($field = $this->getUserField($user, $this->params['license_key_field']) and $key = $field->rawvalue) {
            $license['key'] = $key;
        }
        //valid trial?
        if (empty($license['key']) && $field = $this->getUserField($user, $this->params['trial_date_field'])
            and $trial_end = $this->validTrialEndDate($field->rawvalue)) {
            $license['isTrial'] =  true;
            $license['trialEnd'] =  $trial_end;
        }
        return $license;
    }

    protected function validTrialEndDate (string $trialDate): ?string
    {
        if (!$trialDate) {
            return null;
        }
        try {
            $date = new \DateTime($trialDate);
            $validTill = $date->add(new \DateInterval($this->params['trial_license_duration']));
            return new DateTime() < $validTill ? $validTill->format(DATE_ATOM) : null;
        } catch (Exception $e) {
            return null;
        }
    }

    protected function setTrialStartDate (User $user, DateTime $date)
    {
        //we have to do this with manual queries, since the user is not logged in yet, and has no rights to edit the
        // trial date field
        $query = $this->db->getQuery(true)->select('*')
            ->from($this->db->quoteName('#__fields'))
            ->where($this->db->quoteName('name') . ' = ' . $this->db->quote($this->params['trial_date_field']));
        $this->db->setQuery($query);

        if ($field = $this->db->loadObject()) {
            $query = $this->db->getQuery(true)
                ->insert($this->db->quoteName('#__fields_values'))
                ->columns($this->db->quoteName(['field_id', 'item_id', 'value',]))
                ->values(implode(',', [$field->id, $user->id, $this->db->quote($date->format('Y-m-d 00:00:00')),]));
            $this->db->setQuery($query);
            $this->db->execute();
        }
    }
}
