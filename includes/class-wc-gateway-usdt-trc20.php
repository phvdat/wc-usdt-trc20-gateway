<?php
if (!class_exists('WC_Gateway_USDT_TRC20')) {
class WC_Gateway_USDT_TRC20 extends WC_Payment_Gateway {

    const META_AMOUNT = '_usdt_trc20_amount';
    const META_ADDRESS = '_usdt_trc20_address';
    const META_TXID = '_usdt_trc20_txid';
    const META_CREATED = '_usdt_trc20_created';
    const META_MATCHED = '_usdt_trc20_matched';
    const TOKEN_CONTRACT_MAINNET = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
    const TOKEN_CONTRACT_NILE = 'TXYZopYRdj2D9XRtbG411XZZ3kM5VkAeBf';
    const TOKEN_CONTRACT_SHASTA = 'TXLAQ63Xg1NAzckPwKHvzw7CSEmLMEqcdj';


    public $id = 'usdt_trc20';
    public $has_fields = false;
    public $method_title = 'USDT TRC20';
    public $method_description = 'Direct USDT TRC20 payment to your configured TRON wallet.';
    private $wallet_address = '';
    private $trongrid_api_key = '';
    private $network = 'mainnet';
    private $confirmations = 19;
    private $timeout_minutes = 60;
    private $amount_suffix = true;
    private $debug = false;

    public function __construct() {
        $this->id = 'usdt_trc20';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = 'USDT TRC20';
        $this->method_description = 'Accept USDT on TRON (TRC20) directly to your own wallet and automatically verify payments through TronGrid.';

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title', 'Pay with USDT (TRC20)');
        $this->description = $this->get_option('description', 'Send USDT on the TRON network (TRC20). Your order will be processed after the blockchain payment is confirmed.');

        $this->wallet_address = trim($this->get_option('wallet_address', ''));
        $this->trongrid_api_key = trim($this->get_option('trongrid_api_key', ''));
        $this->network = $this->get_option('network', 'mainnet');
        $this->confirmations = max(0, absint($this->get_option('confirmations', 19)));
        $this->timeout_minutes = max(5, absint($this->get_option('timeout_minutes', 60)));
        // Unique payment amounts are intentionally disabled. Matching uses transaction ID / timestamp.
        $this->amount_suffix = false;
        $this->debug = $this->get_option('debug', 'no') === 'yes';

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
        add_action('woocommerce_email_after_order_table', [$this, 'email_instructions'], 10, 4);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
    }

    public function enqueue_frontend_assets() {
        if (!is_order_received_page()) return;
        $order_id = absint(get_query_var('order-received'));
        if (!$order_id) return;
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== $this->id) return;
        wp_enqueue_style('wc-usdt-trc20', plugins_url('../assets/usdt-trc20.css', __FILE__), [], '0.2.11');
        wp_enqueue_script('wc-usdt-trc20', plugins_url('../assets/usdt-trc20.js', __FILE__), [], '0.2.11', true);
        wp_localize_script('wc-usdt-trc20', 'WCUSDTTRC20', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_usdt_trc20_status'),
            'orderId' => $order_id,
            'orderKey' => $order->get_order_key(),
            'interval' => 8000,
            'verifyNonce' => wp_create_nonce('wc_usdt_trc20_verify_txid'),
        ]);
    }

    public static function ajax_payment_status() {
        $gateway = new self();
        $gateway->handle_ajax_payment_status();
    }

    public static function ajax_verify_txid() {
        $gateway = new self();
        $gateway->handle_ajax_verify_txid();
    }

    private function handle_ajax_payment_status() {
        check_ajax_referer('wc_usdt_trc20_status', 'nonce');
        $order_id = absint($_POST['order_id'] ?? 0);
        $order_key = sanitize_text_field(wp_unslash($_POST['order_key'] ?? ''));
        $order = wc_get_order($order_id);
        if (!$order || !hash_equals((string)$order->get_order_key(), $order_key)) {
            wp_send_json_error(['message' => 'Invalid order.'], 403);
        }
        if ($order->get_payment_method() !== $this->id) {
            wp_send_json_error(['message' => 'Invalid payment method.'], 400);
        }
        $status = $order->get_status();
        wp_send_json_success([
            'status' => $status,
            'paid' => $order->is_paid() || in_array($status, ['processing', 'completed'], true),
            'txid' => (string) $order->get_meta(self::META_TXID),
        ]);
    }

    private function format_display_amount($amount) {
        return rtrim(rtrim(number_format((float) $amount, 6, '.', ''), '0'), '.');
    }

    private function to_units($amount) {
        $value = trim((string) $amount);
        if ($value === '') return 0;
        $parts = explode('.', $value, 2);
        $whole = preg_replace('/\D/', '', $parts[0] ?? '0');
        $frac = preg_replace('/\D/', '', $parts[1] ?? '');
        $frac = str_pad(substr($frac, 0, 6), 6, '0');
        return (int) $whole * 1000000 + (int) $frac;
    }

    private function usdt_contract_address() {
        if ($this->network === 'nile') return self::TOKEN_CONTRACT_NILE;
        if ($this->network === 'shasta') return self::TOKEN_CONTRACT_SHASTA;
        return self::TOKEN_CONTRACT_MAINNET;
    }


    private function find_order_by_txid($txid) {
        $orders = wc_get_orders([
            'limit' => 10,
            'status' => array_keys(wc_get_order_statuses()),
            'meta_key' => self::META_TXID,
            'meta_value' => $txid,
            'return' => 'objects',
        ]);
        return !empty($orders) ? $orders[0] : false;
    }


    private function tron_base58_to_hex($address) {
        if (!$address) return '';
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $digits = [0];
        for ($i = 0; $i < strlen($address); $i++) {
            $pos = strpos($alphabet, $address[$i]);
            if ($pos === false) return '';
            $carry = $pos;
            for ($j = 0; $j < count($digits); $j++) {
                $value = $digits[$j] * 58 + $carry;
                $digits[$j] = $value & 0xff;
                $carry = intdiv($value, 256);
            }
            while ($carry > 0) {
                $digits[] = $carry & 0xff;
                $carry = intdiv($carry, 256);
            }
        }
        $hex = '';
        for ($i = count($digits)-1; $i >= 0; $i--) $hex .= str_pad(dechex($digits[$i]), 2, '0', STR_PAD_LEFT);
        $leading = 0;
        while ($leading < strlen($address) && $address[$leading] === '1') $leading++;
        return str_repeat('00', $leading) . $hex;
    }


    private function tron_hex_to_base58($hex) {
        $hex = preg_replace('/^0x/i', '', trim($hex));
        if ($hex === '') return '';
        if (strlen($hex) % 2) $hex = '0' . $hex;
        $bytes = [];
        for ($i = 0; $i < strlen($hex); $i += 2) $bytes[] = hexdec(substr($hex, $i, 2));
        $zeros = 0;
        while ($zeros < count($bytes) && $bytes[$zeros] === 0) $zeros++;
        $digits = [0];
        foreach ($bytes as $byte) {
            $carry = $byte;
            for ($j = 0; $j < count($digits); $j++) {
                $value = $digits[$j] * 256 + $carry;
                $digits[$j] = $value % 58;
                $carry = intdiv($value, 58);
            }
            while ($carry > 0) {
                $digits[] = $carry % 58;
                $carry = intdiv($carry, 58);
            }
        }
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $out = '';
        for ($i = count($digits)-1; $i >= 0; $i--) $out .= $alphabet[$digits[$i]];
        return str_repeat('1', $zeros) . ltrim($out, '1');
    }


    private function verify_transaction_by_id($txid, $address, $expected_amount, $created, $deadline) {
        $base = $this->network === 'mainnet'
            ? 'https://api.trongrid.io'
            : ($this->network === 'shasta' ? 'https://api.shasta.trongrid.io' : 'https://nile.trongrid.io');

        $this->log('[USDT][TXID] Direct API base=' . $base);

        $headers = [];
        $api_key = trim((string) $this->get_option('trongrid_api_key', ''));
        if ($api_key) {
            $headers['TRON-PRO-API-KEY'] = $api_key;
        }

        $tx_url = $base . '/wallet/gettransactionbyid';
        $this->log('[USDT][TXID] GET ' . $tx_url);

        $response = wp_remote_post($tx_url, [
            'timeout' => 20,
            'headers' => array_merge($headers, ['Content-Type' => 'application/json']),
            'body' => wp_json_encode(['value' => $txid]),
        ]);

        if (is_wp_error($response)) {
            $this->log('[USDT][TXID] gettransactionbyid WP_Error=' . $response->get_error_message());
            return new WP_Error('api_error', 'Unable to reach TRON API: ' . $response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $this->log(sprintf('[USDT][TXID] gettransactionbyid HTTP=%d body=%s', $status, substr($body, 0, 1500)));
        $tx = json_decode($body, true);

        if (!is_array($tx) || empty($tx['txID'])) {
            return new WP_Error('tx_not_found', 'Transaction not found on the selected TRON network.');
        }

        $info_url = $base . '/wallet/gettransactioninfobyid';
        $this->log('[USDT][TXID] GET ' . $info_url);

        $info_response = wp_remote_post($info_url, [
            'timeout' => 20,
            'headers' => array_merge($headers, ['Content-Type' => 'application/json']),
            'body' => wp_json_encode(['value' => $txid]),
        ]);

        if (is_wp_error($info_response)) {
            $this->log('[USDT][TXID] gettransactioninfobyid WP_Error=' . $info_response->get_error_message());
            return new WP_Error('api_error', 'Unable to reach TRON transaction info API.');
        }

        $info_status = wp_remote_retrieve_response_code($info_response);
        $info_body = wp_remote_retrieve_body($info_response);
        $this->log(sprintf('[USDT][TXID] gettransactioninfobyid HTTP=%d body=%s', $info_status, substr($info_body, 0, 1500)));
        $info = json_decode($info_body, true);

        if (!is_array($info) || empty($info['id'])) {
            return new WP_Error('tx_info_not_found', 'Transaction information is not available yet.');
        }

        if (!empty($info['result']) && strtoupper((string) $info['result']) !== 'SUCCESS') {
            return new WP_Error('tx_failed', 'This transaction was not successful on the TRON network.');
        }

        $contract = $this->usdt_contract_address();
        $this->log('[USDT][TXID] Expected USDT contract=' . $contract);

        $contracts = isset($tx['raw_data']['contract']) && is_array($tx['raw_data']['contract'])
            ? $tx['raw_data']['contract'] : [];

        $found = false;
        foreach ($contracts as $c) {
            $type = $c['type'] ?? '';
            $param = $c['parameter']['value'] ?? [];
            $to = '';
            $amount_raw = null;
            $contract_address = $param['contract_address'] ?? '';

            if ($type === 'TriggerSmartContract') {
                $data = strtolower((string) ($param['data'] ?? ''));
                if (strpos($data, 'a9059cbb') !== 0) continue;
                $to_hex = substr($data, 8, 64);
                $amount_hex = substr($data, 72, 64);
                if (strlen($to_hex) !== 64 || strlen($amount_hex) !== 64) continue;

                $to = $this->tron_hex_to_base58('41' . substr($to_hex, 24));
                $amount_raw = hexdec(substr($amount_hex, 48));
            }

            if (!$to || $to !== $address) continue;
            if ($contract && strtolower($contract_address) !== strtolower($this->tron_base58_to_hex($contract))) continue;

            $actual = ((float) $amount_raw) / 1000000;
            $this->log(sprintf('[USDT][TXID] Transfer candidate to=%s amount=%s contract=%s', $to, $actual, $contract_address));

            if (abs($actual - $expected_amount) > 0.000001) {
                return new WP_Error('amount_mismatch', sprintf('Amount mismatch. This order expects %s USDT.', wc_format_decimal($expected_amount, 6)));
            }

            $timestamp_ms = isset($tx['raw_data']['timestamp']) ? (int) $tx['raw_data']['timestamp'] : 0;
            $timestamp = (int) floor($timestamp_ms / 1000);
            $this->log(sprintf('[USDT][TXID] Timestamp=%s order_created=%s deadline=%s', gmdate('c', $timestamp), gmdate('c', $created), gmdate('c', $deadline)));

            if ($timestamp < $created) return new WP_Error('too_early', 'This transaction was sent before the order was created.');
            if ($timestamp > $deadline) return new WP_Error('expired', 'This transaction was sent after the payment timeout.');

            $found = true;
            break;
        }

        if (!$found) {
            return new WP_Error('transfer_not_found', 'No matching USDT TRC20 transfer to the store wallet was found in this transaction.');
        }

        return true;
    }


    private function handle_ajax_verify_txid() {
        $this->log('[USDT][TXID] Verify request started');

        try {
            $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
            $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
            $order_key = isset($_POST['order_key']) ? sanitize_text_field(wp_unslash($_POST['order_key'])) : '';
            $txid = isset($_POST['txid']) ? strtolower(trim(sanitize_text_field(wp_unslash($_POST['txid'])))) : '';

            $this->log(sprintf(
                '[USDT][TXID] Input order=%d txid=%s nonce_present=%s order_key_present=%s',
                $order_id,
                $txid ? substr($txid, 0, 12) . '...' : '(empty)',
                $nonce ? 'YES' : 'NO',
                $order_key ? 'YES' : 'NO'
            ));

            if (!$nonce || !wp_verify_nonce($nonce, 'wc_usdt_trc20_verify_txid')) {
                $this->log('[USDT][TXID] FAIL: invalid nonce');
                wp_send_json_error(['message' => __('Security check failed. Please refresh the page and try again.', 'wc-usdt-trc20')], 400);
            }

            if (!$order_id || !$txid) {
                $this->log('[USDT][TXID] FAIL: missing order_id or txid');
                wp_send_json_error(['message' => __('Order ID or transaction ID is missing.', 'wc-usdt-trc20')], 400);
            }

            $order = wc_get_order($order_id);
            if (!$order) {
                $this->log('[USDT][TXID] FAIL: order not found');
                wp_send_json_error(['message' => __('Order not found.', 'wc-usdt-trc20')], 404);
            }

            $this->log(sprintf(
                '[USDT][TXID] Order loaded: #%d status=%s payment_method=%s total=%s',
                $order_id,
                $order->get_status(),
                $order->get_payment_method(),
                $order->get_total()
            ));

            if ($order_key && !hash_equals($order->get_order_key(), $order_key)) {
                $this->log('[USDT][TXID] FAIL: order key mismatch');
                wp_send_json_error(['message' => __('Invalid order key.', 'wc-usdt-trc20')], 403);
            }

            if ($order->get_payment_method() !== $this->id) {
                $this->log(sprintf(
                    '[USDT][TXID] FAIL: payment method mismatch expected=%s actual=%s',
                    $this->id,
                    $order->get_payment_method()
                ));
                wp_send_json_error(['message' => __('Invalid payment method for this order.', 'wc-usdt-trc20')], 400);
            }

            if ($order->is_paid()) {
                $saved_tx = $order->get_meta(self::META_TXID);
                $this->log(sprintf(
                    '[USDT][TXID] Order already paid. saved_tx=%s',
                    $saved_tx ? substr((string) $saved_tx, 0, 12) . '...' : '(none)'
                ));
                if ($saved_tx && hash_equals(strtolower((string) $saved_tx), $txid)) {
                    wp_send_json_success([
                        'message' => __('Payment successful! Your payment has already been verified.', 'wc-usdt-trc20'),
                        'status' => $order->get_status(),
                    ]);
                }
                wp_send_json_error(['message' => __('This order has already been paid.', 'wc-usdt-trc20')], 400);
            }

            $expected_amount = (float) $order->get_meta(self::META_AMOUNT);
            if ($expected_amount <= 0) {
                $expected_amount = (float) $order->get_total();
            }
            $address = trim((string) $order->get_meta(self::META_ADDRESS));
            $created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
            $timeout = max(1, (int) $this->get_option('timeout_minutes', $this->timeout_minutes));
            $deadline = $created + ($timeout * 60);

            $this->log(sprintf(
                '[USDT][TXID] Expected amount=%s address=%s created=%s deadline=%s network=%s',
                wc_format_decimal($expected_amount, 6),
                $address,
                gmdate('c', $created),
                gmdate('c', $deadline),
                $this->network
            ));

            // If the transaction has already been recorded by cron for this exact order,
            // accept it without re-querying the history list.
            $saved_tx = strtolower(trim((string) $order->get_meta(self::META_TXID)));
            if ($saved_tx && hash_equals($saved_tx, $txid)) {
                $this->log('[USDT][TXID] TXID matches transaction already saved by auto-check');
                wp_send_json_success([
                    'message' => __('Payment successful! Your payment has been detected and verified.', 'wc-usdt-trc20'),
                    'status' => $order->get_status(),
                ]);
            }

            $this->log('[USDT][TXID] Calling direct transaction verification...');
            $result = $this->verify_transaction_by_id($txid, $address, $expected_amount, $created, $deadline);

            if (is_wp_error($result)) {
                $this->log(sprintf(
                    '[USDT][TXID] VERIFY FAIL code=%s message=%s',
                    $result->get_error_code(),
                    $result->get_error_message()
                ));
                $data = $result->get_error_data();
                if ($data !== null) {
                    $this->log('[USDT][TXID] VERIFY FAIL data=' . wp_json_encode($data));
                }
                wp_send_json_error(['message' => $result->get_error_message()], 400);
            }

            $this->log('[USDT][TXID] Blockchain verification passed');

            // Prevent the same TX from being assigned to another order.
            $used = $this->find_order_by_txid($txid);
            if ($used && (int) $used->get_id() !== $order_id) {
                $this->log(sprintf('[USDT][TXID] FAIL: TX already used by order #%d', $used->get_id()));
                wp_send_json_error(['message' => __('This transaction has already been used for another order.', 'wc-usdt-trc20')], 400);
            }

            $order->update_meta_data(self::META_TXID, $txid);
            $order->payment_complete($txid);
            $order->add_order_note(sprintf('USDT payment verified by customer with TX %s', $txid));
            $order->save();

            $this->log(sprintf('[USDT][TXID] SUCCESS: Order #%d paid with TX %s', $order_id, $txid));

            wp_send_json_success([
                'message' => __('Payment successful! Your payment has been detected and verified.', 'wc-usdt-trc20'),
                'status' => $order->get_status(),
            ]);
        } catch (\Throwable $e) {
            $this->log(sprintf(
                '[USDT][TXID] EXCEPTION %s: %s in %s:%d',
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            $this->log('[USDT][TXID] TRACE ' . wp_json_encode(array_slice($e->getTrace(), 0, 5)));
            wp_send_json_error(['message' => __('Unable to verify this transaction. Check the WooCommerce log for details.', 'wc-usdt-trc20')], 500);
        }
    }


    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title'   => 'Enable/Disable',
                'type'    => 'checkbox',
                'label'   => 'Enable USDT TRC20',
                'default' => 'no',
            ],
            'title' => [
                'title'       => 'Title',
                'type'        => 'text',
                'default'     => 'Pay with USDT (TRC20)',
                'description' => 'Shown to customers at checkout.',
            ],
            'description' => [
                'title'       => 'Description',
                'type'        => 'textarea',
                'default'     => 'Pay with USDT on the TRON network (TRC20).',
            ],
            'binance_qr_url' => [
                'title'       => 'Binance QR image URL',
                'type'        => 'text',
                'default'     => '',
                'description' => 'Optional. Upload the Binance QR image to Media Library, copy its File URL, and paste it here. Leave empty to hide the Binance section. This QR is informational only; payment is verified against the configured receiving wallet.',
                'desc_tip'    => true,
            ],
            'network' => [
                'title'   => 'Network',
                'type'    => 'select',
                'default' => 'mainnet',
                'options' => [
                    'mainnet' => 'TRON Mainnet',
                    'nile'    => 'Nile Testnet',
                    'shasta'  => 'Shasta Testnet',
                ],
                'description' => 'Use Nile or Shasta for development/testing. Testnet USDT has no real value.',
            ],
            'wallet_address' => [
                'title'       => 'Receiving wallet address',
                'type'        => 'text',
                'description' => 'TRON receiving address for the selected network. Do NOT enter a private key.',
                'desc_tip'    => true,
            ],
            'trongrid_api_key' => [
                'title'       => 'TronGrid API key',
                'type'        => 'password',
                'description' => 'Required on Mainnet. Testnet TronGrid endpoints currently do not require an API key.',
                'desc_tip'    => true,
            ],
            'confirmations' => [
                'title'       => 'Required confirmations',
                'type'        => 'number',
                'default'     => 19,
                'custom_attributes' => ['min' => 0, 'step' => 1],
                'description' => 'Only confirmed transfers are considered. This MVP uses TronGrid confirmed history.',
            ],
            'timeout_minutes' => [
                'title'       => 'Payment timeout (minutes)',
                'type'        => 'number',
                'default'     => 60,
                'custom_attributes' => ['min' => 5, 'step' => 5],
            ],
            'debug' => [
                'title'   => 'Debug logging',
                'type'    => 'checkbox',
                'label'   => 'Enable WooCommerce logs',
                'default' => 'no',
            ],
            'cron_instructions' => [
                'title'       => 'Automatic payment checker',
                'type'        => 'title',
                'description' => $this->cron_instructions_html(),
            ],
        ];
    }

    public function is_configured() {
        return $this->wallet_address !== '' && ($this->network !== 'mainnet' || $this->trongrid_api_key !== '');
    }

    private function cron_instructions_html() {
        $home = wp_parse_url(home_url(), PHP_URL_HOST);
        $site_path = defined('ABSPATH') ? ABSPATH : '/var/www/html/';
        $site_path = rtrim($site_path, '/');
        $wp_path = '/usr/local/bin/wp';

        $command = 'cd ' . $site_path . ' && ' . $wp_path . ' cron event run --due-now';
        $specific = 'cd ' . $site_path . ' && ' . $wp_path . ' cron event run wc_usdt_trc20_check_payments';

        ob_start();
        ?>
        <div style="max-width:900px;line-height:1.6;">
            <p><strong>Recommended for production:</strong> run WP-CLI cron from the server every minute. This prevents payment detection from depending on site traffic.</p>
            <p><strong>Current site:</strong> <?php echo esc_html($home ?: 'your-site'); ?><br>
            <strong>Detected WordPress path:</strong> <code><?php echo esc_html($site_path); ?></code></p>

            <p><strong>Recommended crontab command:</strong></p>
            <pre style="background:#f6f7f7;padding:12px;overflow:auto;"><code>* * * * * cd <?php echo esc_html($site_path); ?> &amp;&amp; <?php echo esc_html($wp_path); ?> cron event run --due-now &gt;/dev/null 2&gt;&amp;1</code></pre>

            <p>If your <code>wp</code> executable is somewhere else, find it with <code>which wp</code> and replace <code><?php echo esc_html($wp_path); ?></code>.</p>

            <p><strong>Payment checker only:</strong></p>
            <pre style="background:#f6f7f7;padding:12px;overflow:auto;"><code><?php echo esc_html($specific); ?></code></pre>

            <p><strong>Important:</strong> Do not disable WordPress cron unless you also configure a real server cron. If you set <code>DISABLE_WP_CRON</code> to <code>true</code>, use the <code>--due-now</code> command above so other WordPress scheduled tasks also continue to run.</p>

            <p><strong>Check the scheduled event:</strong></p>
            <pre style="background:#f6f7f7;padding:12px;overflow:auto;"><code><?php echo esc_html($specific); ?>

<?php echo esc_html($wp_path); ?> cron event list | grep wc_usdt</code></pre>

            <p><strong>Debug:</strong> when Debug logging is enabled, check <em>WooCommerce &gt; Status &gt; Logs</em> for source <code>usdt-trc20</code>.</p>
        </div>
        <?php
        return ob_get_clean();
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Unable to create the crypto payment.', 'wc-usdt-trc20'), 'error');
            return ['result' => 'failure'];
        }

        $currency = strtoupper($order->get_currency());
        if ($currency !== 'USD') {
            wc_add_notice(__('USDT TRC20 checkout currently requires the store currency to be USD.', 'wc-usdt-trc20'), 'error');
            return ['result' => 'failure'];
        }

        if (!$this->is_configured()) {
            wc_add_notice(__('Crypto payment is temporarily unavailable.', 'wc-usdt-trc20'), 'error');
            return ['result' => 'failure'];
        }

        $amount = $this->make_unique_amount($order);

        $order->update_meta_data(self::META_AMOUNT, $amount);
        $order->update_meta_data(self::META_ADDRESS, $this->wallet_address);
        $order->update_meta_data(self::META_CREATED, time());
        $order->save();

        $order->update_status('on-hold', __('Awaiting USDT TRC20 payment.', 'wc-usdt-trc20'));
        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $this->get_return_url($order),
        ];
    }

    private function make_unique_amount($order) {
        // Do not modify the customer's amount. Payments are matched by exact
        // USDT amount + receiving address + transaction time.
        return rtrim(rtrim(number_format((float) $order->get_total(), 6, '.', ''), '0'), '.');
    }

    public function thankyou_page($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== $this->id) return;

        $amount  = $order->get_meta(self::META_AMOUNT);
        $display_amount = $this->format_display_amount($amount);
        $address = $order->get_meta(self::META_ADDRESS);
        if (!$amount || !$address) return;

        $txid = $order->get_meta(self::META_TXID);
        $network_label = $this->network === 'mainnet'
            ? 'TRON Mainnet'
            : ($this->network === 'nile' ? 'TRON (Nile Testnet)' : 'TRON (Shasta Testnet)');
        $binance_qr_url = trim((string) $this->get_option('binance_qr_url', ''));

        ?>
        <section id="wc-usdt-trc20-payment" class="wc-usdt-trc20-payment">
          <div class="wc-usdt-trc20-card">
            <h2><?php echo esc_html__('USDT Payment', 'wc-usdt-trc20'); ?></h2>

            <div class="wc-usdt-trc20-status" data-state="<?php echo $txid ? 'paid' : 'waiting'; ?>">
              <div class="wc-usdt-trc20-waiting-indicator" aria-hidden="true"><i></i><i></i><i></i></div>
              <strong class="wc-usdt-trc20-status-title"><?php echo $txid ? esc_html__('Payment successful!', 'wc-usdt-trc20') : esc_html__('Waiting for payment', 'wc-usdt-trc20'); ?></strong>
              <span class="wc-usdt-trc20-status-message"><?php echo $txid ? esc_html__('Your payment has been detected and verified. Your order is being processed.', 'wc-usdt-trc20') : esc_html__('We will automatically detect your payment.', 'wc-usdt-trc20'); ?></span>
              <?php if ($txid): ?><code class="wc-usdt-trc20-txid"><?php echo esc_html($txid); ?></code><?php endif; ?>
            </div>

            <div class="wc-usdt-trc20-amount-box">
              <span><?php echo esc_html__('Send exactly', 'wc-usdt-trc20'); ?></span>
              <strong><?php echo esc_html($display_amount); ?> USDT</strong>
              <button type="button" class="button wc-usdt-trc20-copy-amount" data-copy="<?php echo esc_attr($amount); ?>">
                <?php echo esc_html__('Copy amount', 'wc-usdt-trc20'); ?>
              </button>
              <small><?php echo esc_html__('Please send the exact amount shown above.', 'wc-usdt-trc20'); ?></small>
            </div>

            <div class="wc-usdt-trc20-tabs" role="tablist" aria-label="<?php echo esc_attr__('Payment method', 'wc-usdt-trc20'); ?>">
              <?php if ($binance_qr_url): ?>
                <button type="button" class="wc-usdt-trc20-tab is-active" role="tab" aria-selected="true" aria-controls="wc-usdt-tab-binance" data-tab="binance">
                  <?php echo esc_html__('Binance', 'wc-usdt-trc20'); ?>
                </button>
              <?php endif; ?>
              <button type="button" class="wc-usdt-trc20-tab<?php echo $binance_qr_url ? '' : ' is-active'; ?>" role="tab" aria-selected="<?php echo $binance_qr_url ? 'false' : 'true'; ?>" aria-controls="wc-usdt-tab-wallet" data-tab="wallet">
                <?php echo esc_html__('Other wallets', 'wc-usdt-trc20'); ?>
              </button>
            </div>

            <?php if ($binance_qr_url): ?>
              <div id="wc-usdt-tab-binance" class="wc-usdt-trc20-tab-panel is-active" data-panel="binance" role="tabpanel">
                <h3><?php echo esc_html__('Pay with Binance', 'wc-usdt-trc20'); ?></h3>
                <p class="wc-usdt-trc20-instruction">
                  <?php echo esc_html__('Scan the QR with the Binance app. Make sure the payment is sent as USDT on TRON (TRC20) for the exact amount shown above.', 'wc-usdt-trc20'); ?>
                </p>
                <img class="wc-usdt-trc20-binance-qr"
                     src="<?php echo esc_url($binance_qr_url); ?>"
                     alt="<?php echo esc_attr__('Binance payment QR', 'wc-usdt-trc20'); ?>">
              </div>
            <?php endif; ?>

            <div id="wc-usdt-tab-wallet" class="wc-usdt-trc20-tab-panel<?php echo $binance_qr_url ? '' : ' is-active'; ?>" data-panel="wallet" role="tabpanel">
              <h3><?php echo esc_html__('Pay with another wallet', 'wc-usdt-trc20'); ?></h3>
              <p class="wc-usdt-trc20-instruction">
                <?php echo esc_html__('Send USDT to the receiving address below using the TRON (TRC20) network.', 'wc-usdt-trc20'); ?>
              </p>

              <div class="wc-usdt-trc20-address-box">
                <label><?php echo esc_html__('Receiving address', 'wc-usdt-trc20'); ?></label>
                <code><?php echo esc_html($address); ?></code>
                <button type="button" class="button" data-copy="<?php echo esc_attr($address); ?>">
                  <?php echo esc_html__('Copy address', 'wc-usdt-trc20'); ?>
                </button>
              </div>

              <div class="wc-usdt-trc20-payment-details">
                <div class="wc-usdt-trc20-detail-row">
                  <span><?php echo esc_html__('Network', 'wc-usdt-trc20'); ?></span>
                  <strong><?php echo esc_html($network_label); ?> — USDT (TRC20)</strong>
                </div>
                <div class="wc-usdt-trc20-detail-row">
                  <span><?php echo esc_html__('Amount', 'wc-usdt-trc20'); ?></span>
                  <strong><?php echo esc_html($display_amount); ?> USDT</strong>
                </div>
              </div>
            </div>

            <p class="wc-usdt-trc20-warning">
              <strong><?php echo esc_html__('Important:', 'wc-usdt-trc20'); ?></strong>
              <?php echo esc_html__('Send USDT on TRON (TRC20) only. Sending another token or using another network will not be recognized automatically.', 'wc-usdt-trc20'); ?>
            </p>

            <?php if (!$txid): ?>
              <div class="wc-usdt-trc20-verify">
                <h3><?php echo esc_html__('Already paid?', 'wc-usdt-trc20'); ?></h3>
                <p><?php echo esc_html__('If you have completed the transfer, paste your Transaction ID (TXID) below. We will verify it directly on the TRON blockchain.', 'wc-usdt-trc20'); ?></p>
                <label for="wc-usdt-trc20-txid-input"><?php echo esc_html__('Transaction ID (TXID)', 'wc-usdt-trc20'); ?></label>
                <input id="wc-usdt-trc20-txid-input" type="text" autocomplete="off" spellcheck="false" placeholder="Paste your TRON transaction ID">
                <button type="button" class="button wc-usdt-trc20-verify-button"><?php echo esc_html__('Verify payment', 'wc-usdt-trc20'); ?></button>
                <div class="wc-usdt-trc20-verify-result" aria-live="polite"></div>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <script id="wc-usdt-trc20-inline-tabs">
        document.addEventListener('DOMContentLoaded', function () {
          document.querySelectorAll('.wc-usdt-trc20-payment .wc-usdt-trc20-tabs').forEach(function (tabs) {
            var root = tabs.closest('.wc-usdt-trc20-card');
            if (!root) return;
            tabs.querySelectorAll('.wc-usdt-trc20-tab').forEach(function (tab) {
              tab.addEventListener('click', function (event) {
                event.preventDefault();
                var name = tab.getAttribute('data-tab');
                tabs.querySelectorAll('.wc-usdt-trc20-tab').forEach(function (t) {
                  var active = t === tab;
                  t.classList.toggle('is-active', active);
                  t.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                root.querySelectorAll('.wc-usdt-trc20-tab-panel').forEach(function (panel) {
                  panel.classList.toggle('is-active', panel.getAttribute('data-panel') === name);
                });
              });
            });
          });
        });
        </script>
        <?php
    }

    public function email_instructions($order, $sent_to_admin, $plain_text, $email) {
        if ($sent_to_admin || !$order instanceof WC_Order || $order->get_payment_method() !== $this->id) {
            return;
        }

        $amount = $order->get_meta(self::META_AMOUNT);
        $display_amount = $this->format_display_amount($amount);
        $address = $order->get_meta(self::META_ADDRESS);
        if (!$amount || !$address) return;

        $network_label = $this->network === 'mainnet'
            ? 'TRON Mainnet'
            : ($this->network === 'nile' ? 'TRON (Nile Testnet)' : 'TRON (Shasta Testnet)');

        if ($plain_text) {
            echo "\n" . __('USDT TRC20 Payment', 'wc-usdt-trc20') . "\n";
            echo sprintf(__('Send exactly %s USDT on %s to:', 'wc-usdt-trc20'), $display_amount, $network_label) . "\n";
            echo $address . "\n\n";
            echo __('Receiving address: copy this address exactly. Network: USDT (TRC20).', 'wc-usdt-trc20') . "\n\n";
            return;
        }

        echo '<div style="margin:0 0 22px;padding:18px 20px;border:1px solid #e5e7eb;border-radius:10px;background:#fafafa;">';
        echo '<h2 style="margin:0 0 10px;font-size:20px;">' . esc_html__('USDT TRC20 Payment', 'wc-usdt-trc20') . '</h2>';
        echo '<p style="margin:0 0 12px;line-height:1.6;">' . esc_html(sprintf('Send exactly %s USDT on %s to:', $display_amount, $network_label)) . '</p>';
        echo '<p style="margin:0 0 10px;"><strong>' . esc_html__('Receiving address', 'wc-usdt-trc20') . '</strong></p>';
        echo '<p style="margin:0 0 10px;"><code style="display:block;padding:12px;background:#fff;border:1px solid #ddd;border-radius:6px;word-break:break-all;font-size:13px;">' . esc_html($address) . '</code></p>';
        echo '<p style="margin:0;font-size:12px;color:#666;">' . esc_html__('Network: USDT (TRC20). Please copy the address exactly and send the exact amount shown above.', 'wc-usdt-trc20') . '</p>';
        echo '</div>';
    }

    public function scan_and_match() {
        $pending_orders = wc_get_orders([
            'limit'          => 100,
            'status'         => ['pending', 'on-hold'],
            'payment_method' => $this->id,
            'return'         => 'objects',
            'orderby'        => 'date',
            'order'          => 'ASC',
        ]);

        if (!$pending_orders) {
            return;
        }

        $now = time();
        $oldest_allowed = $now - ($this->timeout_minutes * 60);
        $orders_by_amount = [];  // amount_units => [ [order, created], ... ] sorted by created ASC
        $order_created = [];

        foreach ($pending_orders as $order) {
            if ($order->get_meta(self::META_TXID)) {
                continue;
            }

            $created = (int) $order->get_meta(self::META_CREATED);
            if (!$created) {
                $date = $order->get_date_created();
                $created = $date ? $date->getTimestamp() : $now;
            }
            $order_created[$order->get_id()] = $created;

            if ($created < $oldest_allowed) {
                $order->update_status('failed', __('USDT payment timed out.', 'wc-usdt-trc20'));
                continue;
            }

            $amount = $order->get_meta(self::META_AMOUNT);
            if (!$amount) {
                continue;
            }

            $key = $this->to_units($amount);
            $orders_by_amount[$key][] = $order;
        }

        if (!$orders_by_amount) {
            return;
        }

        // Fetch from the oldest pending order creation time so no payment
        // is missed when the cron runs later than the order was placed.
        $scan_from = min($order_created ?: [$now]);
        $transactions = $this->fetch_transactions($scan_from);

        // ---------------------------------------------------------------
        // Build a map: amount_units => [ valid TX entries ] (deduplicated,
        // sorted by TX timestamp ASC so we can deterministically pair them
        // with orders sorted by creation time ASC).
        // ---------------------------------------------------------------
        $txs_by_amount = [];  // amount_units => [ tx, ... ]

        foreach ($transactions as $tx) {
            if (!$this->is_valid_inbound_usdt($tx)) {
                continue;
            }

            $txid = isset($tx['transaction_id']) ? sanitize_text_field($tx['transaction_id']) : '';
            if (!$txid) {
                continue;
            }

            // Skip TXs already recorded on any order.
            if ($this->txid_already_used($txid)) {
                continue;
            }

            $units = $this->to_units($this->transaction_amount($tx));
            if (!isset($orders_by_amount[$units])) {
                // No pending order wants this amount — ignore.
                continue;
            }

            $timestamp = $this->transaction_timestamp($tx);
            if (!$timestamp) {
                continue;
            }

            $txs_by_amount[$units][] = $tx;
        }

        // ---------------------------------------------------------------
        // Match per amount group.
        // ---------------------------------------------------------------
        foreach ($txs_by_amount as $units => $txs) {
            $group_orders = $orders_by_amount[$units];  // already ASC by WC query

            // --- Filter each TX: it must fall within at least one order's window
            //     AND its timestamp must be >= the earliest order creation time in
            //     the group (requirement: timestamp >= order creation timestamp).
            // We keep only TXs that arrive after the oldest order in this group.
            $earliest_order_created = PHP_INT_MAX;
            foreach ($group_orders as $o) {
                $c = $order_created[$o->get_id()] ?? 0;
                if ($c < $earliest_order_created) {
                    $earliest_order_created = $c;
                }
            }

            $valid_txs = [];
            foreach ($txs as $tx) {
                $ts = $this->transaction_timestamp($tx);
                if ($ts >= $earliest_order_created) {
                    $valid_txs[] = $tx;
                }
            }

            if (!$valid_txs) {
                continue;
            }

            // Sort valid TXs by timestamp ASC (oldest TX → oldest order).
            usort($valid_txs, function ($a, $b) {
                return $this->transaction_timestamp($a) - $this->transaction_timestamp($b);
            });

            $order_count = count($group_orders);
            $tx_count    = count($valid_txs);

            if ($tx_count < $order_count) {
                // Not enough TXs to cover all orders in this amount group.
                // Do nothing — never process an ambiguous group partially.
                $this->log(sprintf(
                    '[USDT][AUTO] Ambiguous amount group %s USDT: %d order(s) but only %d valid TX(s). Waiting for more TXs or manual TXID submission.',
                    $this->transaction_amount($valid_txs[0]),
                    $order_count,
                    $tx_count
                ));
                continue;
            }

            // tx_count >= order_count: pair the first N TXs with the N orders
            // in creation-time order (both lists are sorted ASC).
            // Sort orders by creation time ASC for deterministic pairing.
            $sorted_orders = $group_orders;
            usort($sorted_orders, function ($a, $b) use ($order_created) {
                return ($order_created[$a->get_id()] ?? 0) - ($order_created[$b->get_id()] ?? 0);
            });

            for ($i = 0; $i < $order_count; $i++) {
                $candidate = $sorted_orders[$i];
                $tx        = $valid_txs[$i];
                $created   = $order_created[$candidate->get_id()] ?? 0;
                $ts        = $this->transaction_timestamp($tx);
                $deadline  = $created + ($this->timeout_minutes * 60);

                // TX must be within this specific order's window.
                if ($ts < $created || $ts > $deadline) {
                    $this->log(sprintf(
                        '[USDT][AUTO] TX %s timestamp %s outside window for order #%d [%s – %s]. Skipping pair.',
                        sanitize_text_field($tx['transaction_id']),
                        gmdate('c', $ts),
                        $candidate->get_id(),
                        gmdate('c', $created),
                        gmdate('c', $deadline)
                    ));
                    continue;
                }

                // Double-check the TX hasn't been claimed by the time we process it.
                $txid = sanitize_text_field($tx['transaction_id']);
                if ($this->txid_already_used($txid)) {
                    $this->log('[USDT][AUTO] TX ' . $txid . ' was used by another order before pairing could complete. Skipping.');
                    continue;
                }

                $this->mark_paid($candidate, $tx);
            }
        }
    }

    private function network_config() {
        switch ($this->network) {
            case 'nile':
                return [
                    'api' => 'https://nile.trongrid.io',
                    'usdt_contract' => 'TXYZopYRdj2D9XRtbG411XZZ3kM5VkAeBf',
                    'explorer' => 'https://nile.tronscan.org',
                ];
            case 'shasta':
                return [
                    'api' => 'https://api.shasta.trongrid.io',
                    'usdt_contract' => 'TDZDd58a44n5Bvg7pfpcdWhZpv7XSt9PsU',
                    'explorer' => 'https://shasta.tronscan.org',
                ];
            case 'mainnet':
            default:
                return [
                    'api' => 'https://api.trongrid.io',
                    'usdt_contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
                    'explorer' => 'https://tronscan.org',
                ];
        }
    }

    private function fetch_transaction_by_txid($txid) {
        $config = $this->network_config();

        // First try TronGrid's transaction event endpoint.
        $url = trailingslashit($config['api']) . 'v1/transactions/' . rawurlencode($txid) . '/events';
        $url = add_query_arg(['only_confirmed' => 'true', 'limit' => 200], $url);
        $response = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => array_filter([
                'TRON-PRO-API-KEY' => $this->trongrid_api_key ?: null,
                'Accept' => 'application/json',
            ]),
        ]);

        if (!is_wp_error($response)) {
            $code = wp_remote_retrieve_response_code($response);
            $body_raw = wp_remote_retrieve_body($response);
            $body = json_decode($body_raw, true);
            if ($code >= 200 && $code < 300 && is_array($body)) {
                $events = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];
                $contract = $config['usdt_contract'];
                foreach ($events as $event) {
                    $event_contract = isset($event['contract_address']) ? trim((string) $event['contract_address']) : '';
                    $event_name = isset($event['event_name']) ? trim((string) $event['event_name']) : '';
                    if ($event_name !== 'Transfer' || strcasecmp($event_contract, $contract) !== 0) continue;

                    $result = isset($event['result']) && is_array($event['result']) ? $event['result'] : [];
                    $to = isset($result['to']) ? $this->normalize_tron_address((string) $result['to']) : '';
                    $from = isset($result['from']) ? $this->normalize_tron_address((string) $result['from']) : '';
                    $value = isset($result['value']) ? (string) $result['value'] : '';
                    if ($to !== '' && $value !== '') {
                        $timestamp = isset($event['block_timestamp']) ? (int) $event['block_timestamp'] : 0;
                        return [
                            'transaction_id' => $txid,
                            'to' => $to,
                            'from' => $from,
                            'value' => $value,
                            'timestamp' => $timestamp > 0 ? (int) floor($timestamp / 1000) : 0,
                            'type' => 'Transfer',
                            'token_info' => ['address' => $contract, 'symbol' => 'USDT', 'decimals' => 6],
                        ];
                    }
                }
            } else {
                $this->log('TXID verify event endpoint HTTP ' . $code . ': ' . $body_raw);
            }
        } else {
            $this->log('TXID verify event endpoint error: ' . $response->get_error_message());
        }

        // Fallback: read the raw TRON transaction + transaction info directly.
        // This avoids relying on the paginated/events API and works when the
        // event endpoint does not expose the submitted TXID on testnet.
        $tx_url = trailingslashit($config['api']) . 'wallet/gettransactionbyid';
        $tx_response = wp_remote_post($tx_url, [
            'timeout' => 20,
            'headers' => array_filter([
                'TRON-PRO-API-KEY' => $this->trongrid_api_key ?: null,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
            'body' => wp_json_encode(['value' => $txid]),
        ]);
        $tx_body = !is_wp_error($tx_response) ? json_decode(wp_remote_retrieve_body($tx_response), true) : null;

        $info_url = trailingslashit($config['api']) . 'wallet/gettransactioninfobyid';
        $info_response = wp_remote_post($info_url, [
            'timeout' => 20,
            'headers' => array_filter([
                'TRON-PRO-API-KEY' => $this->trongrid_api_key ?: null,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
            'body' => wp_json_encode(['value' => $txid]),
        ]);
        $info_body = !is_wp_error($info_response) ? json_decode(wp_remote_retrieve_body($info_response), true) : null;

        if (!is_array($tx_body) || empty($tx_body['txID']) || !is_array($tx_body['raw_data']['contract'][0]['parameter']['value'] ?? null)) {
            $this->log('TXID verify raw transaction not found: ' . $txid);
            return null;
        }

        $info_ok = is_array($info_body) && !empty($info_body['blockNumber']);
        $receipt_result = $info_body['receipt']['result'] ?? '';
        if (!$info_ok || ($receipt_result !== '' && strtoupper((string) $receipt_result) !== 'SUCCESS')) {
            $this->log('TXID verify transaction not confirmed/successful: ' . $txid);
            return null;
        }

        $contract_item = $tx_body['raw_data']['contract'][0];
        $type = $contract_item['type'] ?? '';
        if ($type !== 'TriggerSmartContract') {
            return null;
        }

        $value = $contract_item['parameter']['value'];
        $contract_hex = isset($value['contract_address']) ? strtolower((string) $value['contract_address']) : '';
        $contract_base58 = $this->normalize_tron_address($contract_hex);
        if (strcasecmp($contract_base58, $config['usdt_contract']) !== 0) {
            $this->log('TXID verify contract mismatch: ' . $contract_base58);
            return null;
        }

        $data = strtolower((string) ($value['data'] ?? ''));
        if (strpos($data, 'a9059cbb') !== 0 || strlen($data) < 136) {
            return null;
        }

        // ABI: transfer(address,uint256)
        $to_hex = '41' . substr($data, 32, 40);
        $amount_hex = substr($data, 72, 64);
        $to = $this->normalize_tron_address($to_hex);
        $from = $this->normalize_tron_address((string) ($value['owner_address'] ?? ''));

        if ($to === '' || $amount_hex === '' || !ctype_xdigit($amount_hex)) {
            return null;
        }

        $decimal = $this->hex_to_decimal_string($amount_hex);
        $timestamp_ms = isset($tx_body['raw_data']['timestamp']) ? (int) $tx_body['raw_data']['timestamp'] : 0;

        return [
            'transaction_id' => $txid,
            'to' => $to,
            'from' => $from,
            'value' => $decimal,
            'timestamp' => $timestamp_ms > 0 ? (int) floor($timestamp_ms / 1000) : 0,
            'type' => 'Transfer',
            'token_info' => ['address' => $config['usdt_contract'], 'symbol' => 'USDT', 'decimals' => 6],
        ];
    }

    private function hex_to_decimal_string($hex) {
        $hex = ltrim(strtolower(preg_replace('/[^0-9a-f]/', '', (string) $hex)), '0');
        if ($hex === '') return '0';
        $decimal = '0';
        foreach (str_split($hex) as $digit) {
            $decimal = $this->decimal_mul_small($decimal, 16);
            $decimal = $this->decimal_add_small($decimal, hexdec($digit));
        }
        return $decimal;
    }

    private function decimal_mul_small($number, $multiplier) {
        $carry = 0;
        $out = '';
        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $n = ((int) $number[$i] * $multiplier) + $carry;
            $out = (string) ($n % 10) . $out;
            $carry = (int) floor($n / 10);
        }
        while ($carry > 0) {
            $out = (string) ($carry % 10) . $out;
            $carry = (int) floor($carry / 10);
        }
        return ltrim($out, '0') ?: '0';
    }

    private function decimal_add_small($number, $add) {
        $carry = (int) $add;
        $out = '';
        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $n = (int) $number[$i] + $carry;
            $out = (string) ($n % 10) . $out;
            $carry = (int) floor($n / 10);
        }
        while ($carry > 0) {
            $out = (string) ($carry % 10) . $out;
            $carry = (int) floor($carry / 10);
        }
        return ltrim($out, '0') ?: '0';
    }

    private function validate_transaction_for_order($order, $tx, $created) {
        if (!$this->is_valid_inbound_usdt($tx)) {
            return ['valid' => false, 'message' => __('This TXID is not a confirmed USDT TRC20 transfer to the store wallet.', 'wc-usdt-trc20')];
        }

        $timestamp = $this->transaction_timestamp($tx);
        if (!$timestamp || $timestamp < $created || $timestamp > ($created + ($this->timeout_minutes * 60))) {
            return ['valid' => false, 'message' => __('This transaction was not made within this order\'s payment window.', 'wc-usdt-trc20')];
        }

        $expected = $this->to_units($order->get_meta(self::META_AMOUNT));
        $actual = $this->to_units($this->transaction_amount($tx));
        if ($expected !== $actual) {
            return ['valid' => false, 'message' => sprintf(__('Amount mismatch. This order expects %s USDT.', 'wc-usdt-trc20'), $order->get_meta(self::META_AMOUNT))];
        }

        return ['valid' => true, 'message' => ''];
    }

    private function fetch_transactions($from_timestamp) {
        $config = $this->network_config();
        $address = trim($this->wallet_address);
        if (!$address) {
            $this->log('[USDT][AUTO] Missing receiving wallet');
            return [];
        }

        $url = trailingslashit($config['api']) . 'v1/accounts/' . rawurlencode($address) . '/transactions/trc20';
        $url = add_query_arg([
            'limit' => 200,
            'only_confirmed' => 'true',
            'min_timestamp' => max(0, (int)$from_timestamp * 1000),
            'order_by' => 'block_timestamp,asc',
            'contract_address' => $config['usdt_contract'],
        ], $url);

        $headers = ['Accept' => 'application/json'];
        if ($this->trongrid_api_key) $headers['TRON-PRO-API-KEY'] = $this->trongrid_api_key;

        $this->log('[USDT][AUTO] GET ' . $url);
        $response = wp_remote_get($url, ['timeout' => 20, 'headers' => $headers]);
        if (is_wp_error($response)) {
            $this->log('[USDT][AUTO] API error: ' . $response->get_error_message());
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $this->log('[USDT][AUTO] HTTP ' . $code . ' response=' . substr($body, 0, 2000));
        if ($code < 200 || $code >= 300) return [];

        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['data']) || !is_array($json['data'])) return [];

        $result = [];
        foreach ($json['data'] as $tx) {
            $token = isset($tx['token_info']['address']) ? (string)$tx['token_info']['address'] : '';
            $symbol = isset($tx['token_info']['symbol']) ? strtoupper((string)$tx['token_info']['symbol']) : '';
            $to = isset($tx['to']) ? $this->normalize_tron_address((string)$tx['to']) : '';
            $txid = isset($tx['transaction_id']) ? strtolower((string)$tx['transaction_id']) : '';

            if (!$txid || $to !== $address) continue;
            if ($token && strcasecmp($token, $config['usdt_contract']) !== 0) continue;
            if ($symbol && $symbol !== 'USDT') continue;

            $result[] = $tx;
        }

        $this->log('[USDT][AUTO] Parsed ' . count($result) . ' matching USDT transfers');
        return $result;
    }

    private function is_valid_inbound_usdt($tx) {
        if (!is_array($tx)) return false;
        $config = $this->network_config();
        $to = isset($tx['to']) ? $this->normalize_tron_address((string)$tx['to']) : '';
        $token = isset($tx['token_info']['address']) ? (string)$tx['token_info']['address'] : '';
        $symbol = isset($tx['token_info']['symbol']) ? strtoupper((string)$tx['token_info']['symbol']) : '';
        return $to === $this->wallet_address
            && (!$token || strcasecmp($token, $config['usdt_contract']) === 0)
            && (!$symbol || $symbol === 'USDT')
            && !empty($tx['transaction_id']);
    }

    private function transaction_amount($tx) {
        $value = isset($tx['value']) ? (string)$tx['value'] : '0';
        $decimals = isset($tx['token_info']['decimals']) ? (int)$tx['token_info']['decimals'] : 6;
        if ($decimals === 6) {
            $units = ltrim($value, '0');
            if ($units === '') return '0';
            if (strlen($units) <= 6) return '0.' . str_pad($units, 6, '0', STR_PAD_LEFT);
            return substr($units, 0, -6) . '.' . substr($units, -6);
        }
        return rtrim(rtrim(number_format(((float)$value) / pow(10, $decimals), 8, '.', ''), '0'), '.');
    }

    private function transaction_timestamp($tx) {
        if (isset($tx['timestamp'])) return (int)$tx['timestamp'];
        if (isset($tx['block_timestamp'])) return (int)floor(((int)$tx['block_timestamp']) / 1000);
        return 0;
    }

    private function normalize_tron_address($address) {
        $address = trim((string)$address);
        if ($address === '') return '';
        if ($address[0] === 'T' && strlen($address) === 34) return $address;
        $hex = strtolower(preg_replace('/^0x/i', '', $address));
        if (strlen($hex) === 42 && str_starts_with($hex, '41')) {
            return $this->tron_hex_to_base58($hex);
        }
        return '';
    }


    private function txid_already_used($txid) {
        $orders = wc_get_orders([
            'limit' => 1,
            'meta_key' => WC_Gateway_USDT_TRC20::META_TXID,
            'meta_value' => $txid,
            'return' => 'ids',
        ]);

        return !empty($orders);
    }

    private function mark_paid($order, $tx) {
        $txid = sanitize_text_field($tx['transaction_id']);
        $amount = $this->transaction_amount($tx);
        $from = isset($tx['from']) ? sanitize_text_field($tx['from']) : '';

        $order->update_meta_data(WC_Gateway_USDT_TRC20::META_TXID, $txid);
        $order->update_meta_data(WC_Gateway_USDT_TRC20::META_MATCHED, current_time('mysql'));
        $order->save();

        $note = sprintf(
            'USDT TRC20 payment verified. Amount: %s USDT. TXID: %s. From: %s',
            $amount,
            $txid,
            $from
        );

        $order->payment_complete($txid);
        $order->add_order_note($note);

        $this->log('Order #' . $order->get_id() . ' paid with TX ' . $txid);
    }

    private function log($message) {
        if (!$this->debug) {
            return;
        }

        if (function_exists('wc_get_logger')) {
            wc_get_logger()->info($message, ['source' => 'usdt-trc20']);
        }
    }
}
}
