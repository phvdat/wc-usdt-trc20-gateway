<?php
/**
 * Trait: Admin email notification.
 *
 * Sends a one-time admin notification when a USDT TRC20 order transitions
 * from on-hold → processing (covers both auto-match and manual TXID paths).
 *
 * No existing payment, verification, or matching logic is touched here.
 */
trait WC_USDT_TRC20_Admin_Notification {

    /**
     * Register the status-change hook.
     * Called once from the plugin bootstrap (not the gateway constructor)
     * so it fires even when the gateway object is not instantiated.
     */
    public static function register_admin_notification_hook() {
        add_action(
            'woocommerce_order_status_on-hold_to_processing',
            [ __CLASS__, 'maybe_send_admin_notification' ],
            20,
            1
        );
    }

    /**
     * Send the admin notification for a USDT TRC20 order, once per order.
     *
     * @param int $order_id
     */
    public static function maybe_send_admin_notification( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Only for this gateway.
        if ( $order->get_payment_method() !== 'usdt_trc20' ) {
            return;
        }

        // Duplicate-send guard — meta flag set before sending.
        $meta_key = '_usdt_trc20_admin_notified';
        if ( $order->get_meta( $meta_key ) ) {
            return;
        }
        $order->update_meta_data( $meta_key, current_time( 'mysql' ) );
        $order->save();

        // Gather order data.
        $order_id     = $order->get_id();
        $order_total  = $order->get_formatted_order_total();
        $order_number = $order->get_order_number();
        $pay_method   = $order->get_payment_method_title();
        $txid         = (string) $order->get_meta( '_usdt_trc20_txid' );
        $admin_url    = admin_url( 'post.php?post=' . $order_id . '&action=edit' );

        $customer_name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $customer_email = $order->get_billing_email();

        $site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $to        = get_option( 'admin_email' );

        // Subject.
        $subject = sprintf(
            '[%s] Order #%s is now processing',
            $site_name,
            $order_number
        );

        // Plain-text body — WooCommerce transactional emails are plain text
        // by default; we keep it simple so it renders in any mail client.
        $body  = sprintf( "Order #%s has been paid and is now processing.\n\n", $order_number );
        $body .= sprintf( "Order total  : %s\n", wp_strip_all_tags( $order_total ) );
        $body .= sprintf( "Payment method: %s\n", $pay_method );
        $body .= sprintf( "Customer name : %s\n", $customer_name ?: '—' );
        $body .= sprintf( "Customer email: %s\n", $customer_email ?: '—' );
        $body .= sprintf( "Transaction ID: %s\n", $txid ?: '(not yet recorded)' );
        $body .= sprintf( "Admin order   : %s\n", $admin_url );

        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
        ];

        wp_mail( $to, $subject, $body, $headers );
    }
}
