<?php

namespace FluentFormPro\Integrations\Telegram;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use FluentForm\App\Services\Integrations\IntegrationManager;
use FluentForm\Framework\Foundation\Application;
use FluentForm\Framework\Helpers\ArrayHelper;

class Bootstrap extends IntegrationManager
{
    public function __construct(Application $app)
    {
        parent::__construct(
            $app,
            'Telegram Messenger',
            'telegram',
            '_fluentform_telegram_settings',
            'telegram_feed',
            99
        );

        $this->logo = fluentFormMix('img/integrations/telegram.png');

        $this->description = 'Send notification to Telegram channel or group when a form is submitted.';

        $this->registerAdminHooks();
    }

    public function getGlobalFields($fields)
    {
        return [
            'logo'             => $this->logo,
            'menu_title'       => __($this->title . ' Settings', 'fluentformpro'),
            'menu_description' => __('Create a Bot by sending /newbot command to @BotFather in telegram. After completing the steps @BotFather will provide you the Bot Token. <br>Create a Channel/group/supergroup. Add the Bot as Administrator to your Channel/Group.<br /><a href="https://wpmanageninja.com/docs/fluent-form/integrations-available-in-wp-fluent-form/telegram-messenger-integration-with-wp-fluent-forms/" target="_blank">Please read the documentation for step by step tutorial</a>', 'fluentformpro'),
            'valid_message'    => __($this->title . ' integration is complete', 'fluentformpro'),
            'invalid_message'  => __($this->title . ' integration is not complete', 'fluentformpro'),
            'save_button_text' => __('Save Settings', 'fluentformpro'),
            'fields'           => [
                'bot_token' => [
                    'type'        => 'password',
                    'placeholder' => __('Bot Token', 'fluentformpro'),
                    'label_tips'  => __("Enter your Telegram Bot Token", 'fluentformpro'),
                    'label'       => __('Bot Token', 'fluentformpro'),
                ],
                'chat_id'   => [
                    'type'        => 'text',
                    'placeholder' => __('Channel Username/ID', 'fluentformpro'),
                    'label_tips'  => __("Enter your Telegram API channel user ID or message ID. Please check documentation for more details.", 'fluentformpro'),
                    'label'       => __('Default Channel/Group Chat ID', 'fluentformpro'),
                ],
                'message'   => [
                    'type'        => 'textarea',
                    'placeholder' => __('Test Message', 'fluentformpro'),
                    'label_tips'  => __("Enter your Test Message", 'fluentformpro'),
                    'label'       => __('Test Message (optional)', 'fluentformpro'),
                    'tips'        => __('Provide a message if you want to send a test message now', 'fluentformpro')
                ],
                'message_thread_id' => [
                    'type'        => 'text',
                    'placeholder' => __('Topic MessageID', 'fluentformpro'),
                    'label_tips'  => __("Enter your Telegram API topic message ID. Please check documentation for more details.", 'fluentformpro'),
                    'label'       => __('Default Topic ID', 'fluentformpro'),
                ],
            ],
            'hide_on_valid'    => true,
            'discard_settings' => [
                'section_description' => __('Your ' . $this->title . ' API integration is up and running', 'fluentformpro'),
                'button_text'         => __('Disconnect ' . $this->title, 'fluentformpro'),
                'data'                => [
                    'chat_id'   => '',
                    'bot_token' => '',
                    'message'   => '',
                    'message_thread_id' => ''
                ],
                'show_verify'         => true
            ]
        ];
    }

    public function getGlobalSettings($settings)
    {
        $globalSettings = get_option($this->optionKey);
        if (!$globalSettings) {
            $globalSettings = [
                'status' => ''
            ];
        }
        $defaults = [
            'status'            => '',
            'chat_id'           => '',
            'bot_token'         => '',
            'message_thread_id' => ''
        ];

        return wp_parse_args($globalSettings, $defaults);
    }

    public function saveGlobalSettings($settings)
    {
        if (empty($settings['chat_id']) || empty($settings['bot_token'])) {
            $settings['status'] = false;
            update_option($this->optionKey, $settings, 'no');
            wp_send_json_success([
                'message' => __('Your settings have been updated', 'fluentformpro'),
                'status'  => false
            ], 200);
        }

        $responseMessage = __('Your ' . $this->integrationKey . ' API key has been verified and successfully set', 'fluentformpro');
        $status = false;

        try {
            $api = $this->getApiClient($settings['bot_token']);

            $apiStatus = $api->getMe();

            if (is_wp_error($apiStatus)) {
                throw new \Exception($apiStatus->get_error_message());
            }

            $apiSettings = [
                'bot_token'         => sanitize_textarea_field($settings['bot_token']),
                'status'            => true,
                'chat_id'           => sanitize_textarea_field($settings['chat_id']),
                'message_thread_id' => sanitize_textarea_field($settings['message_thread_id'])
            ];

            $message = ArrayHelper::get($settings, 'message', '');
            if ($message) {
                $api->setChatId($apiSettings['chat_id']);
                $result = $api->sendMessage($message);
                if (is_wp_error($result)) {
                    $apiSettings['status'] = false;
                    $responseMessage = 'Your API key is correct, but the message could not be sent to the provided chat ID. Error: ' . $result->get_error_message();
                }
            }

            $status = $apiSettings['status'];

            update_option($this->optionKey, $apiSettings, 'no');

        } catch (\Exception $exception) {
            $settings['status'] = false;
            update_option($this->optionKey, $settings, 'no');
            wp_send_json_error([
                'message' => $exception->getMessage()
            ], 400);
        }

        $responseCode = 200;
        if (!$status) {
            $responseCode = 423;
        }

        wp_send_json_success([
            'message' => __($responseMessage, 'fluentformpro'),
            'status'  => $status
        ], $responseCode);

    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'title'                 => $this->title . ' Integration',
            'logo'                  => $this->logo,
            'is_active'             => $this->isConfigured(),
            'configure_title'       => __('Configuration required!', 'fluentformpro'),
            'global_configure_url'  => admin_url('admin.php?page=fluent_forms_settings#general-' . $this->integrationKey . '-settings'),
            'configure_message'     => __($this->title . ' is not configured yet! Please configure your API first', 'fluentformpro'),
            'configure_button_text' => __('Set ' . $this->title . ' API', 'fluentformpro')
        ];
        return $integrations;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        return [
            'name'             => '',
            'send_message'     => '',
            'custom_chat_id'   => '',
            'message_thread_id' => '',
            'conditionals'     => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all'
            ],
            'enabled'          => true
        ];
    }

    public function getSettingsFields($settings, $formId)
    {
        return [
            'fields'              => [
                [
                    'key'         => 'name',
                    'label'       => __('Name', 'fluentformpro'),
                    'required'    => true,
                    'placeholder' => __('Your Feed Name', 'fluentformpro'),
                    'component'   => 'text'
                ],
                [
                    'key'         => 'send_message',
                    'label'       => __('Message to Send', 'fluentformpro'),
                    'required'    => true,
                    'placeholder' => __('Telegram Message', 'fluentformpro'),
                    'component'   => 'value_textarea'
                ],
                [
                    'key'         => 'custom_chat_id',
                    'label'       => __('Custom Chat/Channel ID', 'fluentformpro'),
                    'required'    => false,
                    'placeholder' => __('Custom Chat ID', 'fluentformpro'),
                    'component'   => 'text',
                    'inline_tip'  => __('Provide custom chat ID if you want to send to a different channel or chat. Leave blank for default.', 'fluentformpro')
                ],
                [
                    'key'         => 'message_thread_id',
                    'label'       => __('Custom Topic ID', 'fluentformpro'),
                    'required'    => false,
                    'placeholder' => __('Custom Topic ID', 'fluentformpro'),
                    'component'   => 'text',
                    'inline_tip'  => __('Provide custom topic ID if you want to specify a topic within the channel. Leave blank for default.', 'fluentformpro')
                ],
                [
                    'key'       => 'conditionals',
                    'label'     => __('Conditional Logic', 'fluentformpro'),
                    'tips'      => __('Enable ' . $this->title . ' integration conditionally based on form submission values', 'fluentformpro'),
                    'component' => 'conditional_block'
                ],
                [
                    'key'            => 'enabled',
                    'label'          => __('Status', 'fluentformpro'),
                    'component'      => 'checkbox-single',
                    'checkbox_label' => __('Enable This Feed', 'fluentformpro'),
                ],
            ],
            'button_require_list' => false,
            'integration_title'   => $this->title
        ];
    }

    public function getMergeFields($list = false, $listId = false, $formId = false)
    {
        return [];
    }

    /*
     * Form Submission Hooks Here
     */
    public function notify($feed, $formData, $entry, $form)
    {
        $feedData = $feed['processedValues'];

        if (empty($feedData['send_message'])) {
            return; // Exit if no message to send
        }

        $settings = $this->getGlobalSettings([]);

        // Exit if integration is not configured
        if (!$settings['status']) {
            return;
        }

        // Check for custom chat ID and topic ID
        if ($chatId = ArrayHelper::get($feedData, 'custom_chat_id')) {
            if (trim($chatId)) {
                $settings['chat_id'] = $chatId;
            }
        }
        if ($messageThreadId = ArrayHelper::get($feedData, 'message_thread_id')) {
            if (trim($messageThreadId)) {
                $settings['message_thread_id'] = $messageThreadId;
            }
        }

        // Send message using Telegram API
        $api = $this->getApiClient($settings['bot_token'], $settings['chat_id'], $settings['message_thread_id']);
        $response = $api->sendMessage($feedData['send_message']);

        if (is_wp_error($response)) {
            // Handle error if message failed to send
            do_action('fluentform/integration_action_result', $feed, 'failed', $response->get_error_message());
            return;
        }

        // Success message if message sent successfully
        $messageId = ArrayHelper::get($response, 'result.message_id');
        do_action(
            'fluentform/integration_action_result',
            $feed,
            'success',
            __('Telegram feed has been successfully initiated and data pushed. Message ID: ', 'fluentformpro') . $messageId
        );
    }

    protected function getApiClient($token, $chatId = '', $messageThreadId = '')
    {
        return new TelegramApi(
            $token,
            $chatId,
            $messageThreadId
        );
    }
}
