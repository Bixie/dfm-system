<?php

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\User\User;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;

class PlgSystemDfm extends CMSPlugin implements SubscriberInterface
{
    /**
     * @var \JDatabaseDriver
     */
    public ?JDatabaseDriver $db = null;

    public static function getSubscribedEvents (): array
    {
        $methods = [
            'getActiveLicenseKey',
            'licenseKeyAlreadyExists',
            'setNewLicenseKey',
            'getUserDfmAppData',
            'checkCsiSubscription',
            'updateUserField',
            'onUserAfterSave',
        ];
        return array_combine($methods, $methods);
    }

     public function onUserAfterSave (Event $event): void
    {
        $data = $event[0];
        $isNew = $event[1];
        $success = $event[2];
        //set current date for trial start
        $user = JFactory::getUser($data['id']);
        if ($success && $isNew) {
            $this->setTrialStartDate($user, new DateTime());
        }
    }

    public function setNewLicenseKey (Event $event): void
    {
        /** @var User $user */
        $user = $event['user'];
        $license_key = $event['license_key'];
        if ($field = $this->getUserField($user, $this->params['license_key_field']) and !$field->rawvalue) {
            $this->setUserField($user, $this->params['license_key_field'], $license_key);
        }
    }

    public function checkCsiSubscription (Event $event): void
    {
        /** @var User $user */
        $user = $event['user'];
        try {
            if (!isset($event['email'])) {
                $field = $this->getUserField($user, $this->params['csi_email_field']);
                $email = $field?->rawvalue;
            } else {
                $email = $event['email'];
            }
            if ($email) {
                $response = file_get_contents(str_replace('{email}', $email, $this->params['csi_check_url']));
                $event->setArgument('result', (bool) $response);
                return;
            }
        } catch (Exception $e) {
            //ignore
        }
        $event->setArgument('result', false);
    }

    public function getActiveLicenseKey (Event $event): void
    {
        /** @var User $user */
        $user = $event['user'];
        if ($user->id) {
            ['key' => $key, 'isTrial' => $isTrial,] = $this->getLicenseInfo($user);
            $event->setArgument('result', [($isTrial ? $this->params['trial_license_key'] : $key), $isTrial,]);
        } else {
            $event->setArgument('result', [null, false,]);
        }
    }

    public function getUserDfmAppData (Event $event): void
    {
        /** @var User $user */
        $user = $event['user'];
        ['key' => $key, 'isTrial' => $isTrial, 'trialEnd' => $trialEnd,] = $this->getLicenseInfo($user);
        $userData = [
            'noLicense' => !$key,
            'isTrial' => $isTrial,
            'trialEnd' => $trialEnd,
            'csiActive' => false,
            'name' => $user->name,
            'email' => $user->email,
            'fields' => [
                'license_key' => $key,
                'csi_email' => '',
                'purpose' => '',
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
        if ($fieldKey = $this->params['purpose_field'] and
            $field = $this->getUserField($user, $fieldKey) and
            $purpose = $field->rawvalue) {
            $userData['fields']['purpose'] = $purpose;
        }
        if ($fieldKey = $this->params['vat_code_field'] and
            $field = $this->getUserField($user, $fieldKey) and
            $vat_code = $field->rawvalue) {
            $userData['fields']['vat_code'] = $vat_code;
        }
        $event->setArgument('result', $userData);
    }

    public function updateUserField (Event $event): void
    {
        /** @var User $user */
        $user = $event['user'];
        $field_name = $event['field_name'];
        $value = $event['value'];
        $field_names = [
            'license_key' => $this->params['license_key_field'],
            'gameplans' => $this->params['gameplans_field'],
            'csi_email' => $this->params['csi_email_field'],
        ];
        if (!isset($field_names[$field_name])) {
            $event->setArgument('result', false);
            return;
        }
        $event->setArgument(
            'result',
            $this->setUserField($user, $field_names[$field_name], $value)
        );
    }

    public function licenseKeyAlreadyExists (Event $event): void
    {
        /** @var User $user */
        $user = $event['user'];
        $license_key = $event['license_key'];
        $query = $this->db->getQuery(true)->select('count(*)')
            ->from('#__fields_values AS v')
            ->innerJoin('#__fields AS f ON f.id = v.field_id')
            ->where('f.name = ' . $this->db->quote($this->params['license_key_field']))
            ->where('v.value = ' . $this->db->quote($license_key))
            ->where('v.item_id <> ' . $user->id);
        $this->db->setQuery($query);
        $event->setArgument('result', (int)$this->db->loadResult() > 0);
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
