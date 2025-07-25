<?php

class FluentFormAddOnChecker
{
    private $vars;

    private static $_instance = null;

    function __construct($vars)
    {
        $this->vars = $vars;
        add_action('admin_init', array($this, 'init'));
        add_action('admin_init', array($this, 'activate_license'));
        add_action('admin_init', array($this, 'deactivate_license'));
        add_action('admin_init', array($this, 'check_license'));

        add_filter('fluentform/global_settings_components', array($this, 'pushLicenseMenuToGlobalSettings'));
        add_action('fluentform/global_settings_component_license_page', array($this, 'license_page'));

        $this->sl_updater();

        self::$_instance = $this;
    }

    public static function getInstance()
    {
        return self::$_instance;
    }

    public function isLocal()
    {
        $ip_address = '';
        if (array_key_exists('SERVER_ADDR', $_SERVER)) {
            $ip_address = $_SERVER['SERVER_ADDR'];
        } else if (array_key_exists('LOCAL_ADDR', $_SERVER)) {
            $ip_address = $_SERVER['LOCAL_ADDR'];
        }
        return in_array($ip_address, array("127.0.0.1", "::1"));
    }

    function get_var($var)
    {
        if (isset($this->vars[$var])) {
            return $this->vars[$var];
        }
        return false;
    }

    public function registerLicenseMenu($menus)
    {
        $menus[$this->get_var('menu_slug')] = $this->get_var('menu_title');
        return $menus;
    }

    public function register_option()
    {
        // creates our settings in the options table
        register_setting($this->get_var('option_group'), $this->get_var('license_key'));
    }

    /**
     * Show an error message that license needs to be activated
     */
    function init()
    {
        $this->register_option();
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        if (!defined('FLUENTFORM_VERSION')) {
            return;
        }

        $licenseStatus = $this->getSavedLicenseStatus();

        if (!$licenseStatus) {
            add_action('fluentform/global_menu', array($this, 'errorNotice'));
            add_action('fluentform/after_form_menu', array($this, 'errorNotice'));
            return;
        }

        $licenseData = get_option($this->get_var('license_status') . '_checking');

        if (!$licenseData) {
            return;
        }

        if ($licenseStatus == 'expired') {
            $expireMessage = $this->getExpireMessage($licenseData);
            add_filter('fluentform/dashboard_notices', function ($notices) use ($expireMessage) {
                $notices['license_expire'] = array(
                    'type'     => 'error',
                    'message'  => $expireMessage,
                    'closable' => false
                );
                return $notices;
            });
            if ($this->willShowExpirationNotice()) {
                add_action('fluentform/global_menu', array($this, 'expireNotice'));
                add_action('fluentform/after_form_menu', array($this, 'expireNotice'));
            }
            return;
        }

        if ('valid' != $licenseStatus) {
            add_action('fluentform/global_menu', array($this, 'errorNotice'));
            add_action('fluentform/after_form_menu', array($this, 'errorNotice'));
        }
    }

    public function expireNotice()
    {
        $licenseData = get_option($this->get_var('license_status') . '_checking');
        $expireMessage = $this->getExpireMessage($licenseData);
        echo '<div class="error">' . $expireMessage . '</div>';
    }

    public function errorNotice()
    {
        echo '<div class="error fluentform-admin-notice error_notice' . $this->get_var('option_group') . '"><p>' .
            sprintf(__('The %s license needs to be activated. %sActivate Now%s', 'fluentformpro'),
                $this->get_var('plugin_title'), '<a href="' . $this->get_var('activate_url') . '">',
                '</a>') .
            '</p></div>';
    }

    function sl_updater()
    {
        // retrieve our license key from the DB
        $license_key = trim(get_option($this->get_var('license_key')));
        $license_status = get_option($this->get_var('license_status'));

        // setup the updater
        new FluentFormAddOnUpdater($this->get_var('store_url'), $this->get_var('plugin_file'), array(
            'version'   => $this->get_var('version'),
            'license'   => $license_key,
            'item_name' => $this->get_var('item_name'),
            'item_id'   => $this->get_var('item_id'),
            'author'    => $this->get_var('author')
        ),
            array(
                'license_status' => $license_status,
                'admin_page_url' => $this->get_var('activate_url'),
                'purchase_url'   => $this->get_var('purchase_url'),
                'plugin_title'   => $this->get_var('plugin_title')
            )
        );
    }

    public function pushLicenseMenuToGlobalSettings($components)
    {
        $components['license_page'] = [
            'path'  => '/license_page',
            'title' => 'License',
            'query' => [
                'component' => 'license_page'
            ],

        ];

        return $components;
    }

    function license_page()
    {
        $license = $this->getSavedLicenseKey();
        $status = $this->getSavedLicenseStatus();

        if ($status == 'expired' && $license) {
            $activation = $this->tryActivateLicense($license);
            $status = $this->getSavedLicenseStatus();
        }

        $licenseData = false;
        if ($status) {
            $licenseData = get_option($this->get_var('license_status') . '_checking');
            if (!$licenseData) {
                $remoteData = $this->getRemoteLicense();
                if ($remoteData && !is_wp_error($remoteData)) {
                    $licenseData = $remoteData;
                }
            }
        }

        $renewHtml = $this->getRenewHtml($licenseData);
        settings_errors();
        ?>
        <?php
        echo "
                <script>
                    jQuery('.ff_settings_list li a').on('click', function (e) {

                        if(jQuery(this).attr('href') == '#'){
                            e.preventDefault();
                        }
            
                        let subMenu = jQuery(this).parent().find('.ff_list_submenu');

                        jQuery(this).parent().addClass('active').siblings().removeClass('active');
            
                        subMenu.parent().toggleClass('is-submenu').siblings().removeClass('is-submenu');
                        subMenu.slideToggle().parent().siblings().find('.ff_list_submenu').slideUp();
                    });
                </script>
            ";
        ?>
        <div class="ff_card fluent_activation_wrapper">
            <div class="ff_card_head">
                <h5 class="title"> <?php echo __('License', 'fluentformpro') ?></h5>
                <p class="text"> <?php echo __('Access new features and updates by activating your license key.', 'fluentformpro') ?></p>
            </div>

            <?php if ($renewHtml): ?>
                <div style="padding: 20px; margin-bottom: 20px; background: white;" class="ff_renew_html">
                    <?php echo $renewHtml; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields($this->get_var('option_group')); ?>
                <?php if ('valid' != $status): ?>
                    <h5 class="mb-2"><?php echo __('License Key', 'fluentformpro') ?></h5>
                    <p class="mb-3"><?php echo esc_html(sprintf(__('Thank you for purchasing %s!  Please enter your license key below.', 'fluentformpro'), $this->get_var('plugin_title'))); ?></p>
                <?php endif; ?>

                <?php
                if ($status != false && $status == 'valid') {
                    $extraClass = 'fluent_plugin_activated_hide';
                } else {
                    $extraClass = '';
                }
                if (isset($_GET['debug'])) {
                    $extraClass = '';
                }
                ?>
                <label class="fluentform_label <?php echo $extraClass; ?>">
                    <div class="el-input--large">
                        <input id="<?php echo esc_attr($license) ?>"
                               name="<?php echo $this->get_var('license_key') ?>"
                               type="text" class="regular-text el-input__inner" value="<?php esc_attr_e($license); ?>"
                               placeholder="Enter your license key"/>
                    </div>
                </label>

                <?php if ($status !== false && $status == 'valid') { ?>
                    <div class="license_activated_sucess">
                        <h5> <?php echo __('Fluent Forms Pro Add On', 'fluentformpro'); ?> </h5>
                        <p><?php echo __('Your license is activated! Enjoy ', 'fluentformpro'); ?><?php echo esc_html($this->get_var('plugin_title')) ?>
                            .</p>
                    </div>
                    <?php wp_nonce_field($this->get_var('option_group') . '_nonce',
                        $this->get_var('option_group') . '_nonce'); ?>
                    <input type="hidden" name="<?php echo $this->get_var('option_group') ?>_do_deactivate_license"
                           value="1"/>
                    <input type="submit" class="el-button el-button--primary is-plain el-button--large"
                           name="<?= $this->get_var('option_group') ?>_deactivate"
                           value="<?php echo __('Deactivate License', 'fluentformpro'); ?>"/>
                <?php } else {
                    wp_nonce_field($this->get_var('option_group') . '_nonce',
                        $this->get_var('option_group') . '_nonce'); ?>
                    <input type="hidden" name="<?php echo $this->get_var('option_group') ?>_do_activate_license"
                           value="1"/>
                    <input type="submit" class="el-button el-button--primary button_activate el-button--large"
                           name="<?php echo $this->get_var('option_group') ?>_activate"
                           value="<?php echo __('Activate License', 'fluentformpro'); ?>"/>
                <?php } ?>
            </form>
            <p class="contact_us_line">
                <?php echo sprintf(esc_html(__('Any queries regarding your license key? %sContact us!%s', 'fluentformpro')), '<a href="' . $this->get_var('contact_url') . '" target="_blank">', '</a>'); ?>
            </p>
        </div>
        <?php
    }

    function activate_license()
    {
        // listen for our activate button to be clicked
        if (!isset($_POST[$this->get_var('option_group') . '_do_activate_license'])) {
            return;
        }

        if (!\FluentForm\App\Modules\Acl\Acl::hasPermission('fluentform_full_access')) {
            add_settings_error(
                $this->get_var('option_group'),
                'deactivate',
                __('Sorry! You do not have permission to activate this license.', 'fluentformpro')
            );
            return;
        }

        // run a quick security check
        if (!check_admin_referer($this->get_var('option_group') . '_nonce',
            $this->get_var('option_group') . '_nonce')
        ) {
            return;
        } // get out if we didn't click the Activate button

        // retrieve the license from the database
        $license = trim($_REQUEST[$this->get_var('option_group') . '_key']);

        $result = $this->tryActivateLicense($license);
        if (is_wp_error($result)) {
            $message = $result->get_error_message();
            add_settings_error(
                $this->get_var('option_group'),
                'activate',
                $message
            );
            return;
        }

        return;
    }

    public function tryActivateLicense($license)
    {
        $isNetworkMainSite = is_multisite();

        if ($isNetworkMainSite) {
            // data to send in our API request
            $api_params = array(
                'edd_action' => 'activate_license',
                'license'    => $license,
                'item_name'  => urlencode($this->get_var('item_name')), // the name of our product in EDD
                'item_id'    => $this->get_var('item_id'),
                'url'        => network_site_url()
            );
        } else {
            // data to send in our API request
            $api_params = array(
                'edd_action' => 'activate_license',
                'license'    => $license,
                'item_name'  => urlencode($this->get_var('item_name')), // the name of our product in EDD
                'item_id'    => $this->get_var('item_id'),
                'url'        => home_url()
            );
        }

        $payloadParams = $api_params;
        if ($otherData = $this->getOtherInfo()) {
            $payloadParams['other_data'] = $otherData;
        }

        // Call the custom API.
        $response = wp_remote_get(
            $this->get_var('store_url'),
            array('timeout' => 15, 'sslverify' => false, 'body' => $payloadParams)
        );

        // make sure the response came back okay
        if (is_wp_error($response)) {
            $license_data = file_get_contents($this->get_var('store_url') . '?' . http_build_query($api_params));
            if (!$license_data) {
                $license_data = $this->urlGetContentFallBack($this->get_var('store_url') . '?' . http_build_query($api_params));
            }
            if (!$license_data) {
                return new WP_Error(
                    423,
                    __('Error when contacting with license server. Please check that your server have curl installed', 'fluentformpro'),
                    [
                        'response' => $response,
                        'is_error' => true
                    ]
                );
            }
            $license_data = json_decode($license_data);
        } else {
            $license_data = json_decode(wp_remote_retrieve_body($response));
        }


        // $license_data->license will be either "valid" or "invalid"
        if ($license_data->license) {
            if ($license_data->license == 'invalid' && $license_data->error == 'expired') {
                $this->setLicenseStatus('expired');
            } else {
                $this->setLicenseStatus($license_data->license);
            }
        }

        if (!isset($license_data->next_timestamp)) {
            $license_data->next_timestamp = time() + $this->get_var('cache_time');
        }

        update_option(
            $this->get_var('license_status') . '_checking',
            $license_data
        );

        if ('valid' == $license_data->license) {
            $this->setLicenseKey($license);
            // save the license key to the database
            return array(
                'message'  => sprintf(__('Congratulation! %s is successfully activated', 'fluentformpro'), $this->get_var('plugin_title')),
                'response' => $license_data,
                'status'   => 'valid'
            );
        }

        $errorMessage = $this->getErrorMessage($license_data, $license);

        return new WP_Error(
            423,
            $errorMessage,
            [
                'license_data' => $license_data,
                'is_error'     => true
            ]
        );
    }

    function deactivate_license()
    {
        // listen for our activate button to be clicked
        if (isset($_POST[$this->get_var('option_group') . '_do_deactivate_license'])) {
            if (!\FluentForm\App\Modules\Acl\Acl::hasPermission('fluentform_full_access')) {
                add_settings_error(
                    $this->get_var('option_group'),
                    'deactivate',
                    __('Sorry! You do not have permission to deactivate this license.', 'fluentformpro')
                );
                return;
            }

            // run a quick security check
            if (!check_admin_referer($this->get_var('option_group') . '_nonce',
                $this->get_var('option_group') . '_nonce')
            ) {
                return;
            } // get out if we didn't click the Activate button

            // retrieve the license from the database

            $license = $this->getSavedLicenseKey();

            // data to send in our API request
            $api_params = array(
                'edd_action' => 'deactivate_license',
                'license'    => $license,
                'item_name'  => urlencode($this->get_var('item_name')), // the name of our product in EDD
                'item_id'    => $this->get_var('item_id'),
                'url'        => home_url()
            );

            // Call the custom API.
            $response = wp_remote_post($this->get_var('store_url'),
                array('timeout' => 15, 'sslverify' => false, 'body' => $api_params));

            // make sure the response came back okay
            if (is_wp_error($response)) {
                add_settings_error(
                    $this->get_var('option_group'),
                    'deactivate',
                    __('There was an error deactivating the license, please try again or contact support.',
                        'fluentformpro')
                );
                return false;
            }


            // decode the license data
            $license_data = json_decode(wp_remote_retrieve_body($response));

            // $license_data->license will be either "deactivated" or "failed"
            if ($license_data && ('deactivated' == $license_data->license || $license_data->license == 'failed')) {
                $this->setLicenseStatus(false);
                $this->setLicenseKey(false);
                delete_option($this->get_var('license_status') . '_checking');
                add_settings_error(
                    $this->get_var('option_group'),
                    'deactivate',
                    __('License deactivated', 'fluentformpro')
                );
                wp_safe_redirect($this->get_var('activate_url'));
                exit();

            } else {
                add_settings_error(
                    $this->get_var('option_group'),
                    'deactivate',
                    __('Unable to deactivate license, please try again or contact support.', 'fluentformpro')
                );
            }
        }
    }

    public function check_license()
    {
        $cachedData = get_option($this->get_var('license_status') . '_checking');

        $nextTimestamp = (!empty($cachedData->next_timestamp)) ? $cachedData->next_timestamp : 0;

        if ($nextTimestamp > time()) {
            return;
        }

        $license_data = $this->getRemoteLicense();

        if (is_wp_error($license_data) || !$license_data) {
            return false;
        }

        if ($license_data && $license_data->license) {
            $this->setLicenseStatus($license_data->license);
        }

        $license_data->next_timestamp = time() + $this->get_var('cache_time');

        // Set to check again in sometime later.
        update_option(
            $this->get_var('license_status') . '_checking',
            $license_data
        );
    }

    public function getRemoteLicense()
    {
        $license = $this->getSavedLicenseKey();

        if (!$license) {
            return false;
        }

        if (is_multisite()) {
            $api_params = array(
                'edd_action' => 'check_license',
                'license'    => $license,
                'item_name'  => urlencode($this->get_var('item_name')),
                'url'        => network_site_url()
            );
        } else {
            $api_params = array(
                'edd_action' => 'check_license',
                'license'    => $license,
                'item_name'  => urlencode($this->get_var('item_name')),
                'url'        => home_url()
            );
        }


        $payloadParams = $api_params;
        if ($otherData = $this->getOtherInfo()) {
            $payloadParams['other_data'] = $otherData;
        }

        // Call the custom API.
        $response = wp_remote_get(
            $this->get_var('store_url'),
            array(
                'timeout'   => 15,
                'sslverify' => false,
                'body'      => $payloadParams
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $license_data = json_decode(
            wp_remote_retrieve_body($response)
        );

        return $license_data;
    }

    private function getErrorMessage($licenseData, $licenseKey = false)
    {
        $errorMessage = __('There was an error activating the license, please verify your license is correct and try again or contact support.', 'fluentformpro');


        if (empty($licenseData)) {
            return $errorMessage;
        }

        if ($licenseData->error == 'expired') {
            $renewUrl = $this->getRenewUrl($licenseKey);
            $errorMessage = sprintf(__('Your license has been expired at %s. Please %sclick here%s to renew your license', 'fluentformpro'), $licenseData->expires, '<a target="_blank" href="' . $renewUrl . '">', '</a>');
        } else if ($licenseData->error == 'no_activations_left') {
            $errorMessage = sprintf(__('No Activation Site left: You have activated all the sites that your license offer. Please go to wpmanageninja.com account and review your sites. You may deactivate your unused sites from wpmanageninja account or you can purchase another license. %sClick Here to purchase another license%s', 'fluentformpro'), '<a target="_blank" href="' . $this->get_var('purchase_url') . '">', '</a>');
        } else if ($licenseData->error == 'missing') {
            $errorMessage = __('The given license key is not valid. Please verify that your license is correct. You may login to wpmanageninja.com account and get your valid license key for your purchase.', 'fluentformpro');
        }

        return $errorMessage;
    }

    private function urlGetContentFallBack($url)
    {
        $parts = parse_url($url);
        $host = $parts['host'];
        $result = false;
        if (!function_exists('curl_init')) {
            $ch = curl_init();
            $header = array('GET /1575051 HTTP/1.1',
                "Host: {$host}",
                'Accept:text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language:en-US,en;q=0.8',
                'Cache-Control:max-age=0',
                'Connection:keep-alive',
                'Host:adfoc.us',
                'User-Agent:Mozilla/5.0 (Macintosh; Intel Mac OS X 10_8_4) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/27.0.1453.116 Safari/537.36',
            );
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
            curl_setopt($ch, CURLOPT_COOKIESESSION, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
            $result = curl_exec($ch);
            curl_close($ch);
        }
        if (!$result && function_exists('fopen') && function_exists('stream_get_contents')) {
            $handle = fopen($url, "r");
            $result = stream_get_contents($handle);
        }
        return $result;
    }

    private function getSavedLicenseKey()
    {
        if (is_multisite()) {
            $license = trim(get_network_option(get_main_network_id(), $this->get_var('license_key')));
        } else {
            $license = trim(get_option($this->get_var('license_key')));
        }
        return $license;
    }

    private function setLicenseKey($key)
    {
        if (is_multisite()) {
            $status = update_network_option(get_main_network_id(), $this->get_var('license_key'), $key);
        } else {
            $status = update_option($this->get_var('license_key'), $key);
        }
        return $status;
    }

    private function getSavedLicenseStatus()
    {
        if (is_multisite()) {
            $status = trim(get_network_option(get_main_network_id(), $this->get_var('license_status')));
        } else {
            $status = trim(get_option($this->get_var('license_status')));
        }
        return $status;
    }

    private function setLicenseStatus($status)
    {
        if (is_multisite()) {
            $status = update_network_option(get_main_network_id(), $this->get_var('license_status'), $status);
        } else {
            $status = update_option($this->get_var('license_status'), $status);
        }
        return $status;
    }

    private function getExpireMessage($licenseData)
    {
        $renewUrl = $this->get_var('activate_url');

        return sprintf(__('%sYour %s license has been %sexpired at %s, Please %sClick Here to Renew Your License%s', 'fluentformpro'), '<p>', $this->get_var('plugin_title'), '<b>', date('d M Y', strtotime($licenseData->expires)) . '</b>', '<a href="' . $renewUrl . '"><b>', '</b></a>' . '</p>');
    }

    private function willShowExpirationNotice()
    {
        if (!defined('FLUENTFORM_VERSION') || !\FluentForm\App\Modules\Acl\Acl::hasAnyFormPermission()) {
            return false;
        }
        global $pagenow;
        $showablePages = ['index.php', 'plugins.php'];
        if (in_array($pagenow, $showablePages)) {
            return true;
        }
        return false;
    }

    private function getRenewHtml($license_data)
    {
        if (!$license_data) {
            return;
        }
        $status = $this->getSavedLicenseStatus();
        if (!$status) {
            return;
        }
        $renewUrl = $this->getRenewUrl();
        $renewHTML = '';
        if ($status == 'expired') {
            $expiredDate = date('d M Y', strtotime($license_data->expires));
            $renewHTML = sprintf(__('%sYour license was expired at %s', 'fluentformpro'), '<p>', '<b>' . $expiredDate . '</b></p>');
            $renewHTML .= sprintf(__('%sClick Here to renew your license%s', 'fluentformpro'), '<p><a class="button-secondary button_activate" target="_blank" href="' . $renewUrl . '">', '</a></p>');
        } else if ($status == 'valid') {
            if ($license_data->expires != 'lifetime') {
                $expireDate = date('d M Y', strtotime($license_data->expires));
                $interval = strtotime($license_data->expires) - time();
                $intervalDays = intval($interval / (60 * 60 * 24));
                if ($intervalDays < 30) {
                    $renewHTML = sprintf(__('%sYour license will be expired in %s days%s', 'fluentformpro'), '<p>', $intervalDays, '</p>');
                    $renewHTML .= sprintf(__('%sPlease %sClick Here to renew your license%s', 'fluentformpro'), '<p>', '<a class="button-secondary button_activate" target="_blank" href="' . $renewUrl . '">', '</a></p>');
                }
            }
        }

        return $renewHTML;
    }

    private function getRenewUrl($licenseKey = false)
    {
        if (!$licenseKey) {
            $licenseKey = $this->getSavedLicenseKey();
        }
        if ($licenseKey) {
            $renewUrl = $this->get_var('store_site') . '/checkout/?edd_license_key=' . $licenseKey . '&download_id=' . $this->get_var('item_id');
        } else {
            $renewUrl = $this->get_var('purchase_url');
        }
        return $renewUrl;
    }

    private function getOtherInfo()
    {
        if (!$this->timeMatched()) {
            return false;
        }

        $php_version = (defined('PHP_VERSION')) ? PHP_VERSION : phpversion();
        $php_version = preg_replace('/^(\d+\.\d+\.\d+).*/', '$1', $php_version);

        global $wp_version;
        return [
            'plugin_version' => FLUENTFORMPRO_VERSION,
            'php_version'    => $php_version,
            'wp_version'     => $wp_version,
            'plugins'        => (array)get_option('active_plugins'),
            'site_lang'      => get_bloginfo('language'),
            'site_title'     => get_bloginfo('name'),
            'theme'          => wp_get_theme()->get('Name')
        ];
    }

    private function timeMatched()
    {
        $prevValue = get_option('_fluent_last_m_run');
        if (!$prevValue) {
            return true;
        }
        return (time() - $prevValue) > 518400; // 6 days match
    }

}
