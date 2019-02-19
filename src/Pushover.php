<?php

namespace WHMCS\Module\Notification\Pushover;

use WHMCS\Module\Contracts\NotificationModuleInterface;
use WHMCS\Module\Notification\DescriptionTrait;
use WHMCS\Notification\Contracts\NotificationInterface;

class Pushover implements NotificationModuleInterface
{
    use DescriptionTrait;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->setDisplayName('Pushover');
        $this->setLogoFileName('logo.png');
    }

    /**
     * Provider settings.
     *
     * @return array
     */
    public function settings()
    {
        return [
            'api_token' => [
                'FriendlyName' => 'API Token',
                'Type' => 'password',
                'Description' => 'Your Pushover application token.',
            ],
        ];
    }

    /**
     * Test connection.
     *
     * @param array $settings
     * @return array
     */
    public function testConnection($settings)
    {
        // Do nothing.
        // Pusher doesn't provide a way to validate the API token alone.
    }


    /**
     * Individual notification settings.
     *
     * @return array
     */
    public function notificationSettings()
    {
        return [
            'user' => [
                'FriendlyName' => 'User/Group Key',
                'Type' => 'text',
                'Description' => 'The recipient key, obtained from the Pushover dashboard.',
            ],
            'device' => [
                'FriendlyName' => 'Device Name (Optional)',
                'Type' => 'text',
                'Description' => 'The recipient\'s device name. Multiple devices can be separated by a comma or leave blank for all devices.',
            ],
            'sound' => [
                'FriendlyName' => 'Sound',
                'Type' => 'dynamic',
                'Description' => 'Choose the sound for this notification.',
            ],
            'priority' => [
                'FriendlyName' => 'Priority',
                // TODO: Replace with the 'dropdown' type once WHMCS issue #MODULE-6698 is resolved.
                'Type' => 'dynamic',
                'Description' => 'Choose the priority for this notification. Emergency priority notifications will require acknowledgement.',
            ],
            'retry' => [
                'FriendlyName' => 'Retry Period',
                'Type' => 'text',
                'Description' => 'Emergency priority only. Defines the number of seconds Pushover will wait between re-delivery attempts of the same notification until it\'s acknowledged or expires (see Expires setting). The value must be a minimum of 30.',
            ],
            'expires' => [
                'FriendlyName' => 'Expires',
                'Type' => 'text',
                'Description' => 'Emergency priority only. Defines the number of seconds Pushover will continue to attempt re-delivery of the same notification before giving up. The value can be a maximum of 10800 (3 hours).',
            ],
        ];
    }

    /**
     * Returns an array containing values for the dynamic field "sound".
     * 
     * @param array $settings
     * @return array
     */
    private function getSoundFieldValues(array $settings)
    {
        $response = json_decode(file_get_contents('https://api.pushover.net/1/sounds.json?token=' . $settings['api_token']), true);
                
        return array_map(function ($name, $id) {
            return compact('name', 'id');
        }, $response['sounds'], array_keys($response['sounds']));
    }

    /**
     * Returns an array containing values for the dynamic field "priorities".
     * 
     * @return array
     */
    private function getPriorityFieldValues()
    {
        return [
            ['id' => '-2', 'name' => 'Lowest'],
            ['id' => '-1', 'name' => 'Low'],
            ['id' => '0',  'name' => 'Normal'],
            ['id' => '1',  'name' => 'High'],
            ['id' => '2',  'name' => 'Emergency'],
        ];
    }

    /**
     * Get dynamic field configuration.
     *
     * @param string $fieldName
     * @param array $settings
     * @return array
     */
    public function getDynamicField($fieldName, $settings)
    {
        switch ($fieldName) {
            case 'sound':
                $values = $this->getSoundFieldValues($settings);
                break;
            case 'priority':
                $values = $this->getPriorityFieldValues();
                break;
            default:
                throw new Exception("The field name '{$fieldName}' is not recognised.");
        }

        return compact('values');
    }

    /**
     * Build the Pushover message including the original message and attributes.
     *
     * @param NotificationInterface $notification
     * @return string
     */
    private function buildMessage(NotificationInterface $notification)
    {
        $message = $notification->getMessage() . PHP_EOL . PHP_EOL;

        foreach ($notification->getAttributes() as $attribute) {
            $message .= $attribute->getLabel() . ': ' . $attribute->getValue() . PHP_EOL;
        }

        return $message;
    }

    /**
     * Get dynamic field configuration.
     *
     * @param NotificationInterface $notification
     * @param array $moduleSettings
     * @param array $notificationSettings
     * @return array
     */
    public function sendNotification(NotificationInterface $notification, $moduleSettings, $notificationSettings)
    {
        $data = [];
        $data['token'] = $moduleSettings['api_token'];
        $data['user'] = $notificationSettings['user'];
        $data['title'] = $notification->getTitle();
        $data['message'] = $this->buildMessage($notification);
        $data['priority'] = array_shift(explode('|', $notificationSettings['priority']));
        $data['sound'] = array_shift(explode('|', $notificationSettings['sound']));

        if (!empty($notificationSettings['device'])) {
            $data['device'] = $notificationSettings['device'];
        }

        if ($data['priority'] == 2) {
            $data['retry'] = empty($notificationSettings['retry']) ? $notificationSettings['retry'] : 300;
            $data['expire'] = empty($notificationSettings['expires']) ? $notificationSettings['expires'] : (3600 * 10);
        }

        $ch = curl_init('https://api.pushover.net/1/messages.json');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
    }
}
