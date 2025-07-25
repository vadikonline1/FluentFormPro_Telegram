<?php

namespace FluentFormPro\Integrations\Telegram;

if (!defined('ABSPATH')) {
    exit;
}

use FluentForm\App\Http\Controllers\IntegrationManagerController;
use FluentForm\Framework\Foundation\Application;
use FluentForm\Framework\Helpers\ArrayHelper;

class Bootstrap extends IntegrationManagerController
{
    public function __construct(Application $app)
    {
        parent::__construct(
            $app,
            'Telegram ',
            'telegram',
            '_fluentform_telegram_settings',
            'telegram_feed',
            99
        );

        $this->logo = fluentFormMix('img/integrations/telegram.png');
        $this->description = 'Send notification to Telegram channel, group, or topic when a form is submitted.';
        $this->registerAdminHooks();
    }

    public function getGlobalFields($fields)
    {
        return [
            'logo'             => $this->logo,
            'menu_title'       => __($this->title . ' Settings', 'fluentformpro'),
            'menu_description' => __('Create a Bot by sending /newbot command to @BotFather in Telegram. After completing the steps, @BotFather will provide the Bot Token. <br>Create a Channel/Group/Forum. Add the Bot as Administrator.<br /><a href="https://wpmanageninja.com/docs/fluent-form/integrations-available-in-wp-fluent-form/telegram-messenger-integration-with-wp-fluent-forms/" target="_blank">Read documentation</a>', 'fluentformpro'),
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
                    'label_tips'  => __("Enter your Telegram channel/group ID.", 'fluentformpro'),
                    'label'       => __('Default Channel/Group Chat ID', 'fluentformpro'),
                ],
                'message_thread_id' => [
                    'type'        => 'text',
                    'placeholder' => __('Thread ID (for topics)', 'fluentformpro'),
                    'label_tips'  => __("Optional: Use this if you're posting to a forum/topic-based chat.", 'fluentformpro'),
                    'label'       => __('Default Message Thread ID', 'fluentformpro'),
                ],
                'message'   => [
                    'type'        => 'textarea',
                    'placeholder' => __('Test Message', 'fluentformpro'),
                    'label_tips'  => __("Enter your Test Message", 'fluentformpro'),
                    'label'       => __('Test Message (optional)', 'fluentformpro'),
                    'tips'        => __('Provide a message if you want to send a test message now', 'fluentformpro')
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
            $globalSettings = ['status' => ''];
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
                'message_thread_id' => sanitize_text_field(ArrayHelper::get($settings, 'message_thread_id', ''))
            ];

            $message = ArrayHelper::get($settings, 'message', '');
            if ($message) {
                $api->setChatId($apiSettings['chat_id']);
                if (!empty($apiSettings['message_thread_id'])) {
                    $api->setMessageThreadId($apiSettings['message_thread_id']);
                }
                $result = $api->sendMessage($message);
                if (is_wp_error($result)) {
                    $apiSettings['status'] = false;
                    $responseMessage = 'Your API key is valid, but the message could not be sent. Error: ' . $result->get_error_message();
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

        wp_send_json_success([
            'message' => __($responseMessage, 'fluentformpro'),
            'status'  => $status
        ], $status ? 200 : 423);
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
            'name'               => '',
            'send_message'       => '',
            'custom_chat_id'     => '',
            'message_thread_id'  => '',
            'conditionals'       => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all'
            ],
            'enabled'            => true
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
                    'inline_tip'  => __('Override global chat ID. Leave blank to use global one.', 'fluentformpro')
                ],
                [
                    'key'         => 'message_thread_id',
                    'label'       => __('Message Thread ID (optional)', 'fluentformpro'),
                    'required'    => false,
                    'placeholder' => __('Thread ID for topics', 'fluentformpro'),
                    'component'   => 'text',
                    'inline_tip'  => __('Post inside a topic of a forum group chat.', 'fluentformpro')
                ],
                [
                    'key'       => 'conditionals',
                    'label'     => __('Conditional Logics', 'fluentformpro'),
                    'tips'      => __('Run this feed only when these conditions are met', 'fluentformpro'),
                    'component' => 'conditional_block'
                ],
                [
                    'key'            => 'enabled',
                    'label'          => __('Status', 'fluentformpro'),
                    'component'      => 'checkbox-single',
                    'checkbox_label' => __('Enable this feed', 'fluentformpro'),
                ]
            ],
            'integration_title'   => $this->title
        ];
    }

    public function getMergeFields($list = false, $listId = false, $formId = false)
    {
        return [];
    }

    public function notify($feed, $formData, $entry, $form)
    {
        $feedData = $feed['processedValues'];

        if (empty($feedData['send_message'])) {
            return;
        }

        $settings = $this->getGlobalSettings([]);

        if (!$settings['status']) {
            return;
        }

        $chatId = ArrayHelper::get($feedData, 'custom_chat_id', $settings['chat_id']);
        $threadId = ArrayHelper::get($feedData, 'message_thread_id', $settings['message_thread_id']);

        $api = $this->getApiClient($settings['bot_token'], $chatId);

        if (!empty($threadId)) {
            $api->setMessageThreadId($threadId);
        }

        $response = $api->sendMessage($feedData['send_message']);

        if (is_wp_error($response)) {
            do_action('fluentform/integration_action_result', $feed, 'failed', $response->get_error_message());
            return;
        }

        $messageId = ArrayHelper::get($response, 'result.message_id');
        do_action(
            'fluentform/integration_action_result',
            $feed,
            'success',
            __('Telegram feed sent successfully. Message ID: ', 'fluentformpro') . $messageId
        );
    }

    protected function getApiClient($token, $chatId = '')
    {
        return new TelegramApi($token, $chatId);
    }
}
