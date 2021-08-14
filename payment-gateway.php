<?php
/*
 * Plugin Name: WooCommerce EMIS Iframe Payment Gateway
 * Plugin URI: https://pininformatica.com
 * Text Domain: emis_iframe
 * Domain Path: /languages
 * Description: EMIS Iframe Payment Method for WooCommerce
 * Author: André Amorim
 * Author URI: https://www.linkedin.com/in/andrefcamorim/
 * Version: 1.0.0
 */

session_start();

add_action( 'init', 'emis_iframe_load_textdomain' );
function emis_iframe_load_textdomain() {
	load_plugin_textdomain( 'emis_iframe', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

add_filter( 'woocommerce_payment_gateways', 'emis_add_gateway_class' );
function emis_add_gateway_class( $gateways ) {
	$gateways[] = 'EMIS_Iframe_Gateway'; // your class name is here
	return $gateways;
}

add_action( 'plugins_loaded', 'emis_init_gateway_class' );
function emis_init_gateway_class() {

	class EMIS_Iframe_Gateway extends WC_Payment_Gateway {

		public function __construct() {
			add_filter( 'load_textdomain_mofile', 'my_plugin_load_my_own_textdomain', 10, 2 );
			$this->id                 = 'emis_iframe';
			$this->icon               = '';
			$this->has_fields         = true;
			$this->method_title       = 'EMIS Iframe Payment Gateway';
			$this->method_description = __( 'Adds the payment option for EMIS using Iframe', 'emis_iframe' );

			$this->supports = [
				'products',
			];

			$this->init_form_fields();

			$this->init_settings();
			$this->title              = $this->get_option( 'title' );
			$this->description        = $this->get_option( 'description' );
			$this->api_url            = $this->get_option( 'api_url' );
			$this->iframe_page_url    = $this->get_option( 'iframe_page_url' );
			$this->frame_token        = $this->get_option( 'frame_token' );
			$this->callback_remote_ip = $this->get_option( 'callback_remote_ip' );

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
			add_action( 'woocommerce_api_' . $this->id, [ $this, 'webhook' ] );
			add_action( 'woocommerce_thankyou_emis_iframe', [ $this, 'add_link_to_thankyou_page' ], 10, 1 );
		}

		public function add_link_to_thankyou_page(){
			if ( isset( $_SESSION['emis_link_order'] ) ) {
				printf(
					'<div class="emis_link_order">
						<p><b>%1$s</b><br>
						%2$s</p>
					</div><br>',
					__( 'Here is the link for the payment:', 'emis_iframe' ),
					wp_kses_post( $_SESSION['emis_link_order'] ),
				);
			}
		}

		public function init_form_fields() {
			$this->form_fields = [
				'title'              => [
					'title'       => __( 'Title', 'emis_iframe' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'emis_iframe' ),
					'default'     => '',
					'desc_tip'    => true,
				],
				'description'        => [
					'title'       => __( 'Description', 'emis_iframe' ),
					'type'        => 'textarea',
					'description' => __( 'This controls the description which the user sees during checkout.', 'emis_iframe' ),
					'default'     => '',
					'desc_tip'    => true,
				],
				'api_url'            => [
					'title'       => __( 'API URL', 'emis_iframe' ),
					'type'        => 'text',
					'description' => __( 'Insert here the URL provided by EMIS.', 'emis_iframe' ),
					'default'     => '',
					'desc_tip'    => true,
				],
				'iframe_page_url'    => [
					'title'       => __( 'Iframe Page URL', 'emis_iframe' ),
					'type'        => 'text',
					'description' => __( 'Insert here the page for your website where it will be displayed the iframe.', 'emis_iframe' ),
					'default'     => '',
					'desc_tip'    => true,					
				],
				'frame_token'        => [
					'title'       => __( 'Frame Token', 'emis_iframe' ),
					'type'        => 'text',
					'description' => __( 'Insert here the frametoken provided by EMIS.', 'emis_iframe' ),
					'default'     => '',
					'desc_tip'    => true,
				],
				'callback_remote_ip' => [
					'title'       => __( 'Callback Remote IP', 'emis_iframe' ),
					'type'        => 'text',
					'description' => __( 'Insert here the callback remote ip for validation.', 'emis_iframe' ),
					'default'     => '',
					'desc_tip'    => true,
				],
			];
		}

		private function get_cart_total() {
			$total = 0;

			if ( ! WC()->cart->prices_include_tax ) {
				$total = WC()->cart->cart_contents_total;
			} else {
				$total = WC()->cart->cart_contents_total + WC()->cart->tax_total;
			}
			
			//Sum shipping
			$total += ( WC()->cart->get_shipping_total() * 0.14 ) + WC()->cart->get_shipping_total();
			
			return round($total);
		}

		private function get_emis_token( $url, $order_id ) {
			if ( substr( $url, -1 ) === '/' ) {
				$url .= 'frameToken';
			} else {
				$url .= '/frameToken';
			}

			$data = [
				'reference'   => $order_id,
				'amount'      => $this->get_cart_total(),
				'token'       => $this->frame_token,
				'mobile'      => 'PAYMENT',
				'card'        => 'DISABLED',
				'callbackUrl' => get_site_url() . '/wc-api/emis_iframe',
			];

			$options = [
				'body'        => wp_json_encode( $data ),
				'headers'     => [
					'Content-Type' => 'application/json',
				],
				'timeout'     => 60,
				'redirection' => 5,
				'blocking'    => true,
				'httpversion' => '1.0',
				'sslverify'   => false,
				'data_format' => 'body',
			];

			$response = wp_remote_post( $url, $options );

			$result = json_decode( $response['body'], true );

			return $result['id'];
		}

		private function render_payment( $order_id, $token ) {
			if ( empty( $token ) ) {
				return '';
			}

			$url = $this->iframe_page_url;

			if ( substr( $url, -1 ) === '/' ) {
				$url .= '?order-nr=' . $order_id . '&payment-key=' . $token;
			} else {
				$url .= '/?order-nr=' . $order_id . '&payment-key=' . $token;
			}

			return '<a target="_blank" href="' . $url . '">' . $url . '</a>';
		}

		public function payment_fields() {			
			if ( $this->frame_token && $this->api_url ) {
				echo esc_html( $this->description );
			} else {
				esc_html_e( 'This plugin is not yet configurated!', 'emis_iframe' );
			}
		}

		public function process_payment( $order_id ) {
			if ( ! is_checkout() ) {
				return;
			}

			if ( empty( $this->api_url ) || empty( $this->frame_token ) ) {
				return;
			}

			if ( $this->frame_token && $this->api_url ) {
				$order    = wc_get_order( $order_id );
				$token_id = $this->get_emis_token( $this->api_url, $order_id );

				$order->update_status( 'on-hold' );
				
				$emis_link_order = $this->render_payment( $order_id, $token_id );
				$order->add_order_note( esc_html__( 'Payment Address: ', 'emis_iframe' ) . $emis_link_order, true );

				$_SESSION['emis_link_order'] = $emis_link_order;

				WC()->cart->empty_cart();

				return [
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				];
			}
		}

		public function webhook() {
			$json     = file_get_contents( 'php://input' );
			$data     = json_decode( $json );
			$order_id = $data->merchantReferenceNumber;
			$status   = $data->status;
			$logger = wc_get_logger();
			$logger->info( 'Callback: Order_id: ' . $order_id . ' Status: ' . $status . ' ip: ' . $_SERVER['REMOTE_ADDR'] );
			
			$emis_ip   = $this->callback_remote_ip;
			$remote_ip = $_SERVER['REMOTE_ADDR'];
			
			if ( empty( $emis_ip ) ) {
				$emis_ip = 0;
				$remote_ip = 0;
			}
			
			if ( $emis_ip === $remote_ip ) {
				$json     = file_get_contents( 'php://input' );
				$data     = json_decode( $json );
				$order_id = $data->merchantReferenceNumber;
				$status   = $data->status;
				if ( $status === 'ACCEPTED' ) {
					$order = wc_get_order( $order_id );
					$order->update_status( 'processing' );
					$logger = wc_get_logger();
					$logger->info( 'Callback called from the following remote IP: ' . $remote_ip . '.' );
				} else {
					$order = wc_get_order( $order_id );
					$order->update_status( 'failed' );
					$logger = wc_get_logger();
					$logger->info( 'Callback called from the following remote IP: ' . $remote_ip . '.' );
				}
			} else {
				$logger = wc_get_logger();
				$logger->info( 'Callback from different IP. The IP that is registred in the configurations is ' . $emis_ip . '. The remote IP is ' . $_SERVER['REMOTE_ADDR'] . '.' );
				wp_mail( get_option( 'admin_email' ), 'Callback com IP diferente', 'O callback do plugin de pagamentos da EMIS foi chamado através de uma ip diferente: ' . $remote_ip );
			}
		}

	}
}

