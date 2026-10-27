<?php
/* FakeOrder за capi-test.php (identity-test.php си има своя) */
$GLOBALS['order_ts'] = time() - 3600;
class FakeDate { function getTimestamp() { return $GLOBALS['order_ts']; } }
class FakeOrder {
	public $meta = array(); public $saved = 0; public $status = 'processing'; public $customer_id = 0; public $pm = 'cod'; public $email = 'Maria@Example.com';
	function get_meta( $k ) { return $this->meta[ $k ] ?? ''; } function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; } function save() { $this->saved++; }
	function get_billing_email() { return $this->email; } function get_billing_phone() { return '0888 123 456'; } function get_billing_first_name() { return 'Мария'; } function get_billing_last_name() { return 'Иванова'; }
	function get_billing_city() { return 'София'; } function get_billing_state() { return ''; } function get_billing_postcode() { return '1000'; } function get_billing_country() { return 'BG'; }
	function get_shipping_phone() { return ''; } function get_shipping_first_name() { return ''; } function get_shipping_last_name() { return ''; } function get_shipping_city() { return ''; } function get_shipping_postcode() { return ''; } function get_shipping_country() { return ''; }
	function get_customer_id() { return $this->customer_id; } function get_customer_ip_address() { return '8.8.8.8'; } function get_customer_user_agent() { return 'UA-from-order'; }
	function get_status() { return $this->status; } function get_id() { return 1001; } function get_order_number() { return '1001'; } function get_date_created() { return new FakeDate(); }
	function get_items( $t = '' ) { return array(); } function get_total() { return 61.5; } function get_currency() { return 'EUR'; } function get_coupon_codes() { return array( 'PROMO10' ); }
	function get_payment_method() { return $this->pm; } function get_shipping_total() { return 2.55; } function get_shipping_tax() { return 0; } function get_shipping_method() { return 'Speedy'; }
	function needs_payment() { return 'pending' === $this->status; } function get_order_key() { return 'wc_order_abc'; }
}
function wc_get_order( $id ) { return $GLOBALS['orders'][ $id ] ?? null; }
