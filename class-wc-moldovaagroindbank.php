<?php
/**
 * Plugin Name: WooCommerce Moldova Agroindbank Payment Gateway
 * Description: Accept Visa and Mastercard directly on your store with the Moldova Agroindbank payment gateway for WooCommerce.
 * Plugin URI: https://github.com/alexminza/wc-moldovaagroindbank
 * Version: 1.2.4
 * Author: Alexander Minza
 * Author URI: https://profiles.wordpress.org/alexminza
 * Developer: Alexander Minza
 * Developer URI: https://profiles.wordpress.org/alexminza
 * Text Domain: wc-moldovaagroindbank
 * Domain Path: /languages
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires at least: 4.8
 * Tested up to: 6.0
 * WC requires at least: 3.3
 * WC tested up to: 6.6.1
 *
 * @package WooCommerce
 */

// Looking to contribute code to this plugin? Go ahead and fork the repository over at GitHub https://github.com/alexminza/wc-moldovaagroindbank
// This plugin is based on MaibApi by Maib/maibapi https://github.com/maibank/MaibApi ( https://packagist.org/packages/maib/maibapi ).

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

require_once __DIR__ . '/vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\MessageFormatter;
use Maib\MaibApi\MaibClient;
use Maib\MaibApi\MaibDescription;


add_action( 'plugins_loaded', 'woocommerce_moldovaagroindbank_init', 0 );

/**
 * Initialize the Moldovaagroindbank payment gateway.
 *
 * @return void
 */
function woocommerce_moldovaagroindbank_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	load_plugin_textdomain( 'wc-moldovaagroindbank', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	/**
	 * The class of the Moldovaagroindbank payment gateway.
	 */
	class WC_MoldovaAgroindbank extends WC_Payment_Gateway {

		/**
		 * The Monolog Logger class.
		 *
		 * @var \Monolog\Logger
		 */
		protected $logger;

		// region Constants.
		const MOD_ID          = 'moldovaagroindbank';
		const MOD_TITLE       = 'Moldova Agroindbank';
		const MOD_PREFIX      = 'maib_';
		const MOD_TEXT_DOMAIN = 'wc-moldovaagroindbank';

		const TRANSACTION_TYPE_CHARGE        = 'charge';
		const TRANSACTION_TYPE_AUTHORIZATION = 'authorization';

		const LOGO_TYPE_BANK    = 'bank';
		const LOGO_TYPE_SYSTEMS = 'systems';

		const MOD_TRANSACTION_TYPE = self::MOD_PREFIX . 'transaction_type';
		const MOD_TRANSACTION_ID   = self::MOD_PREFIX . 'transaction_id';
		const MOD_CLOSEDAY_ACTION  = self::MOD_PREFIX . 'close_day';

		const SUPPORTED_CURRENCIES = array( 'MDL', 'EUR', 'USD' );
		const ORDER_TEMPLATE       = 'Order #%1$s';

		const MAIB_TRANS_ID       = 'trans_id';
		const MAIB_TRANSACTION_ID = 'TRANSACTION_ID';

		const MAIB_RESULT              = 'RESULT';
		const MAIB_RESULT_OK           = 'OK'; // successfully completed transaction.
		const MAIB_RESULT_FAILED       = 'FAILED'; // transaction has failed.
		const MAIB_RESULT_CREATED      = 'CREATED'; // transaction just registered in the system.
		const MAIB_RESULT_PENDING      = 'PENDING'; // transaction is not accomplished yet.
		const MAIB_RESULT_DECLINED     = 'DECLINED'; // transaction declined by ECOMM, because ECI is in blocked ECI list ( ECOMM server side configuration ).
		const MAIB_RESULT_REVERSED     = 'REVERSED'; // transaction is reversed.
		const MAIB_RESULT_AUTOREVERSED = 'AUTOREVERSED'; // transaction is reversed by autoreversal.
		const MAIB_RESULT_TIMEOUT      = 'TIMEOUT'; // transaction was timed out.

		const MAIB_RESULT_CODE          = 'RESULT_CODE';
		const MAIB_RESULT_3DSECURE      = '3DSECURE';
		const MAIB_RESULT_RRN           = 'RRN';
		const MAIB_RESULT_APPROVAL_CODE = 'APPROVAL_CODE';
		const MAIB_RESULT_CARD_NUMBER   = 'CARD_NUMBER';
		// endregion.

		/**
		 * {@inheritDoc}
		 */
		public function __construct() {
			$plugin_dir = plugin_dir_url( __FILE__ );

			$this->logger = wc_get_logger();

			$this->id                 = self::MOD_ID;
			$this->method_title       = self::MOD_TITLE;
			$this->method_description = 'WooCommerce Payment Gateway for Moldova Agroindbank';
			$this->icon               = apply_filters( 'woocommerce_moldovaagroindbank_icon', $plugin_dir . 'assets/img/maib.png' );
			$this->has_fields         = false;
			$this->supports           = array( 'products', 'refunds' );

			// region Initialize user set variables.
			$this->enabled     = $this->get_option( 'enabled', 'yes' );
			$this->title       = $this->get_option( 'title', $this->method_title );
			$this->description = $this->get_option( 'description' );

			$this->logo_type    = $this->get_option( 'logo_type', self::LOGO_TYPE_BANK );
			$this->bank_logo    = $plugin_dir . 'assets/img/maib.png';
			$this->systems_logo = $plugin_dir . 'assets/img/paymentsystems.png';
			$plugin_icon        = ( self::LOGO_TYPE_BANK === $this->logo_type ? $this->bank_logo : $this->systems_logo );
			$this->icon         = apply_filters( 'woocommerce_moldovaagroindbank_icon', $plugin_icon );

			$this->testmode = 'yes' === $this->get_option( 'testmode', 'no' );
			$this->debug    = 'yes' === $this->get_option( 'debug', 'no' );

			$this->log_context   = array( 'source' => $this->id );
			$this->log_threshold = $this->debug ? WC_Log_Levels::DEBUG : WC_Log_Levels::NOTICE;
			$this->logger        = new WC_Logger( null, $this->log_threshold );

			$this->transaction_type = $this->get_option( 'transaction_type', self::TRANSACTION_TYPE_CHARGE );
			$this->transaction_auto = false;

			$this->order_template = $this->get_option( 'order_template', self::ORDER_TEMPLATE );

			$this->base_url             = ( $this->testmode ? MaibClient::MAIB_TEST_BASE_URI : MaibClient::MAIB_LIVE_BASE_URI );
			$this->client_handler_url   = ( $this->testmode ? MaibClient::MAIB_TEST_REDIRECT_URL : MaibClient::MAIB_LIVE_REDIRECT_URL );
			$this->merchant_handler_url = ( $this->testmode ? '/ecomm/MerchantHandler' : '/ecomm01/MerchantHandler' );
			$this->skip_receipt_page    = true;

			$this->maib_pfxcert      = $this->get_option( 'maib_pfxcert' );
			$this->maib_pcert        = $this->get_option( 'maib_pcert' );
			$this->maib_key          = $this->get_option( 'maib_key' );
			$this->maib_key_password = $this->get_option( 'maib_key_password' );

			$this->file_system = new WP_Filesystem_Direct( null );

			$this->init_form_fields();
			$this->init_settings();

			$this->initialize_certificates();

			$this->update_option( 'maib_callback_url', $this->get_callback_url() );
			// endregion.

			if ( is_admin() ) {
				// Save options.
				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			}

			add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );

			if ( $this->transaction_auto ) {
				add_filter( 'woocommerce_order_status_completed', array( $this, 'order_status_completed' ) );
				add_filter( 'woocommerce_order_status_cancelled', array( $this, 'order_status_cancelled' ) );
				add_filter( 'woocommerce_order_status_refunded', array( $this, 'order_status_refunded' ) );
			}

			// Payment listener/API hook.
			add_action( 'woocommerce_api_wc_' . $this->id, array( $this, 'check_response' ) );
		}

		/**
		 * Initialize Gateway Settings Form Fields
		 */
		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'              => array(
					'title'   => sprintf( __( 'Enable/Disable', 'wc-moldovaagroindbank' ) ),
					'type'    => 'checkbox',
					'label'   => sprintf( __( 'Enable this gateway', 'wc-moldovaagroindbank' ) ),
					'default' => 'yes',
				),
				'title'                => array(
					'title'    => sprintf( __( 'Title', 'wc-moldovaagroindbank' ) ),
					'type'     => 'text',
					'desc_tip' => sprintf( __( 'Payment method title that the customer will see during checkout.', 'wc-moldovaagroindbank' ) ),
					'default'  => self::MOD_TITLE,
				),
				'description'          => array(
					'title'    => sprintf( __( 'Description', 'wc-moldovaagroindbank' ) ),
					'type'     => 'textarea',
					'desc_tip' => sprintf( __( 'Payment method description that the customer will see during checkout.', 'wc-moldovaagroindbank' ) ),
					'default'  => '',
				),
				'logo_type'            => array(
					'title'    => sprintf( __( 'Logo', 'wc-moldovaagroindbank' ) ),
					'type'     => 'select',
					'class'    => 'wc-enhanced-select',
					'desc_tip' => sprintf( __( 'Payment method logo image that the customer will see during checkout.', 'wc-moldovaagroindbank' ) ),
					'default'  => self::LOGO_TYPE_BANK,
					'options'  => array(
						self::LOGO_TYPE_BANK    => sprintf( __( 'Bank logo', 'wc-moldovaagroindbank' ) ),
						self::LOGO_TYPE_SYSTEMS => sprintf( __( 'Payment systems logos', 'wc-moldovaagroindbank' ) ),
					),
				),
				'testmode'             => array(
					'title'    => sprintf( __( 'Test mode', 'wc-moldovaagroindbank' ) ),
					'type'     => 'checkbox',
					'label'    => sprintf( __( 'Enabled', 'wc-moldovaagroindbank' ) ),
					'desc_tip' => sprintf( __( 'Use Test or Live bank gateway to process the payments. Disable when ready to accept live payments.', 'wc-moldovaagroindbank' ) ),
					'default'  => 'no',
				),
				'debug'                => array(
					'title'       => sprintf( __( 'Debug mode', 'wc-moldovaagroindbank' ) ),
					'type'        => 'checkbox',
					'label'       => sprintf( __( 'Enable logging', 'wc-moldovaagroindbank' ) ),
					'default'     => 'no',
					'description' => sprintf( '<a href="%2$s">%1$s</a>', __( 'View logs', 'wc-moldovaagroindbank' ), self::get_logs_url() ),
					'desc_tip'    => sprintf( __( 'Save debug messages to the WooCommerce System Status logs. Note: this may log personal information. Use this for debugging purposes only and delete the logs when finished.', 'wc-moldovaagroindbank' ) ),
				),
				'transaction_type'     => array(
					'title'    => sprintf( __( 'Transaction type', 'wc-moldovaagroindbank' ) ),
					'type'     => 'select',
					'class'    => 'wc-enhanced-select',
					'desc_tip' => sprintf( __( 'Select how transactions should be processed. Charge submits all transactions for settlement, Authorization simply authorizes the order total for capture later.', 'wc-moldovaagroindbank' ) ),
					'default'  => self::TRANSACTION_TYPE_CHARGE,
					'options'  => array(
						self::TRANSACTION_TYPE_CHARGE => sprintf( __( 'Charge', 'wc-moldovaagroindbank' ) ),
						self::TRANSACTION_TYPE_AUTHORIZATION => sprintf( __( 'Authorization', 'wc-moldovaagroindbank' ) ),
					),
				),
				'order_template'       => array(
					'title'       => sprintf( __( 'Order description', 'wc-moldovaagroindbank' ) ),
					'type'        => 'text',
					/* translators: %s: ?. */
					'description' => sprintf( __( 'Format: <code>%1$s</code> - Order ID, <code>%2$s</code> - Order items summary', 'wc-moldovaagroindbank' ), '', '' ),
					'desc_tip'    => sprintf( __( 'Order description that the customer will see on the bank payment page.', 'wc-moldovaagroindbank' ) ),
					'default'     => self::ORDER_TEMPLATE,
				),
				'connection_settings'  => array(
					'title'       => sprintf( __( 'Connection Settings', 'wc-moldovaagroindbank' ) ),
					'description' => sprintf(
						'%1$s<br /><br /><a href="#" id="woocommerce_moldovaagroindbank_basic_settings" class="button">%2$s</a>&nbsp;%3$s&nbsp;<a href="#" id="woocommerce_moldovaagroindbank_advanced_settings" class="button">%4$s</a>',
						sprintf( __( 'Use Basic settings to upload the certificate file received from the bank or configure manually using Advanced settings below.', 'wc-moldovaagroindbank' ) ),
						sprintf( __( 'Basic settings&raquo;', 'wc-moldovaagroindbank' ) ),
						sprintf( __( 'or', 'wc-moldovaagroindbank' ) ),
						sprintf( __( 'Advanced settings&raquo;', 'wc-moldovaagroindbank' ) ),
					),
					'type'        => 'title',
				),
				'maib_pfxcert'         => array(
					'title'             => sprintf( __( 'Client certificate ( PFX )', 'wc-moldovaagroindbank' ) ),
					'type'              => 'file',
					'desc_tip'          => sprintf( __( 'Uploaded PFX certificate will be processed and converted to PEM format. Advanced settings will be overwritten and configured automatically.', 'wc-moldovaagroindbank' ) ),
					'custom_attributes' => array(
						'accept' => '.pfx',
					),
				),

				'maib_pcert'           => array(
					'title'       => sprintf( __( 'Client certificate file', 'wc-moldovaagroindbank' ) ),
					'type'        => 'text',
					'description' => '<code>' . MaibClient::MAIB_TEST_CERT_URL . '</code>',
					'default'     => '',
				),
				'maib_key'             => array(
					'title'       => sprintf( __( 'Private key file', 'wc-moldovaagroindbank' ) ),
					'type'        => 'text',
					'description' => '<code>' . MaibClient::MAIB_TEST_CERT_KEY_URL . '</code>',
					'default'     => '',
				),
				'maib_key_password'    => array(
					'title'       => sprintf( __( 'Certificate / private key passphrase', 'wc-moldovaagroindbank' ) ),
					'type'        => 'password',
					'desc_tip'    => sprintf( __( 'Leave empty if certificate / private key is not encrypted.', 'wc-moldovaagroindbank' ) ),
					'placeholder' => sprintf( __( 'Optional', 'wc-moldovaagroindbank' ) ),
					'default'     => '',
					'description' => '<code>' . MaibClient::MAIB_TEST_CERT_PASS . '</code>',
				),
				'payment_notification' => array(
					'title'       => sprintf( __( 'Payment Notification', 'wc-moldovaagroindbank' ) ),
					'description' => sprintf( __( 'Provide this URL to the bank to enable online payment notifications.', 'wc-moldovaagroindbank' ) ),
					'type'        => 'title',
				),
				'maib_callback_url'    => array(
					'title'             => sprintf( __( 'Callback URL', 'wc-moldovaagroindbank' ) ),
					'type'              => 'text',
					/* translators: %s: maib gateway url. */
					'desc_tip'          => sprintf( __( 'Bank payment gateway URL: %1$s', 'wc-moldovaagroindbank' ), esc_url( $this->get_maib_gateway_url() ) ),
					'custom_attributes' => array(
						'readonly' => 'readonly',
					),
				),
			);
		}

		/**
		 * The function of the currency validation.
		 *
		 * @return bool
		 *   Returns TRUE if the currency is in the currency array.
		 */
		public function is_valid_for_use() {
			if ( ! in_array( get_option( 'woocommerce_currency' ), self::SUPPORTED_CURRENCIES, true ) ) {
				return false;
			}

			return true;
		}

		/**
		 * The method of the availables verification.
		 *
		 * @return bool
		 *   Returns TRUE if the currency is in the currency array and
		 *   if the certificate and his key have been set.
		 */
		public function is_available() {
			if ( ! $this->is_valid_for_use() ) {
				return false;
			}

			if ( ! $this->check_settings() ) {
				return false;
			}

			return parent::is_available();
		}

		/**
		 * Verifying if the certificate and his key have been set.
		 *
		 * @return bool
		 *   Returns the check_settings method result.
		 */
		public function needs_setup() {
			return $this->check_settings();
		}

		/**
		 * That method adds to the parent method,
		 * The validation of the settings,
		 * Displaying the error messages for Admin,
		 * Preparing a custom jQuery code to provide to the page.
		 */
		public function admin_options() {
			$this->validate_settings();
			$this->display_errors();

			wc_enqueue_js(
				'jQuery( function() {
					var basic_fields_ids    = "#woocommerce_moldovaagroindbank_maib_pfxcert, #woocommerce_moldovaagroindbank_maib_key_password";
					var advanced_fields_ids = "#woocommerce_moldovaagroindbank_maib_pcert, #woocommerce_moldovaagroindbank_maib_key, #woocommerce_moldovaagroindbank_maib_key_password";

					var basic_fields    = jQuery( basic_fields_ids ).closest( "tr" );
					var advanced_fields = jQuery( advanced_fields_ids ).closest( "tr" );

					jQuery( document ).ready( function() {
						basic_fields.hide();
						advanced_fields.hide();
					} );

					jQuery( "#woocommerce_moldovaagroindbank_basic_settings" ).on( "click", function() {
						advanced_fields.hide();
						basic_fields.show();
						return false;
					} );

					jQuery( "#woocommerce_moldovaagroindbank_advanced_settings" ).on( "click", function() {
						basic_fields.hide();
						advanced_fields.show();
						return false;
					} );
				} );'
			);

			parent::admin_options();
		}

		/**
		 * That method adds to the parent method,
		 * Additional process the pfx certificate.
		 */
		public function process_admin_options() {
			$this->process_pfx_setting( 'woocommerce_moldovaagroindbank_maib_pfxcert', $this->maib_pfxcert, 'woocommerce_moldovaagroindbank_maib_key_password' );

			return parent::process_admin_options();
		}

		/**
		 * Verifying if the certificate and his key have been set.
		 *
		 * @return bool
		 *   Returns TRUE if the certificate and his key have been set.
		 */
		protected function check_settings() {
			return ! self::string_empty( $this->maib_pcert )
				&& ! self::string_empty( $this->maib_key );
		}

		/**
		 * Verifying if the certificate and his key have been set and they are valid,
		 * Adds the errors if needed.
		 *
		 * @return bool
		 *   Returns TRUE if the certificate and his key have been set
		 *   and they are valid.
		 */
		protected function validate_settings() {
			$validate_result = true;

			if ( ! $this->is_valid_for_use() ) {
				$this->add_error(
					sprintf(
						'<strong>%1$s: %2$s</strong>. %3$s: %4$s',
						__( 'Unsupported store currency', 'wc-moldovaagroindbank' ),
						get_option( 'woocommerce_currency' ),
						__( 'Supported currencies', 'wc-moldovaagroindbank' ),
						join( ', ', self::SUPPORTED_CURRENCIES )
					)
				);

				$validate_result = false;
			}

			if ( ! $this->check_settings() ) {
				$this->add_error(
					sprintf(
						'<strong>%1$s</strong>: %2$s',
						__( 'Connection Settings', 'wc-moldovaagroindbank' ),
						__( 'Not configured', 'wc-moldovaagroindbank' )
					)
				);
				$validate_result = false;
			}

			$result = $this->validate_certificate( $this->maib_pcert );
			if ( ! self::string_empty( $result ) ) {
				$this->add_error(
					sprintf(
						'<strong>%1$s</strong>: %2$s',
						__( 'Client certificate file', 'wc-moldovaagroindbank' ),
						$result
					)
				);
				$validate_result = false;
			}

			$result = $this->validate_private_key( $this->maib_pcert, $this->maib_key, $this->maib_key_password );
			if ( ! self::string_empty( $result ) ) {
				$this->add_error(
					sprintf(
						'<strong>%1$s</strong>: %2$s',
						__( 'Private key file', 'wc-moldovaagroindbank' ),
						$result
					)
				);
				$validate_result = false;
			}

			return $validate_result;
		}

		/**
		 * Adds a notice if the user can manage this configuration.
		 *
		 * @return void
		 */
		protected function settings_admin_notice() {
			if ( current_user_can( 'manage_woocommerce' ) ) {
				$message = sprintf(
					/* translators: %s: maib settings url. */
					__( 'Please review the <a href="%1$s">payment method settings</a> page for log details and setup instructions.', 'wc-moldovaagroindbank' ),
					self::get_settings_url()
				);
				wc_add_notice( $message, 'error' );
			}
		}

		// region Certificates.

		/**
		 * Process the certificate settings irmconfiguration form.
		 *
		 * @param string $pfx_field_id the id of the uploaded certificate.
		 *
		 * @param string $pfx_option_value the path of the saved certificate.
		 *
		 * @param string $pass_field_id the password of the certificate.
		 *
		 * @return void
		 */
		protected function process_pfx_setting( $pfx_field_id, $pfx_option_value, $pass_field_id ) {
			try {
				if ( array_key_exists( $pfx_field_id, $_FILES ) && ! empty( $_FILES[ $pfx_field_id ] ) ) {
					$file_tmp_name = ! empty( $_FILES[ $pfx_field_id ]['tmp_name'] ) ? sanitize_file_name( wp_unslash( $_FILES[ $pfx_field_id ]['tmp_name'] ) ) : false;
					$file_error    = ! empty( $_FILES[ $pfx_field_id ]['error'] ) ? intval( wp_unslash( $_FILES[ $pfx_field_id ]['error'] ) ) : false;
					$tmp_name      = $file_tmp_name;

					if ( UPLOAD_ERR_OK === $file_error && is_uploaded_file( $tmp_name ) ) {
						$pfx_data = $this->file_system->get_contents( $tmp_name );

						if (
							false !== $pfx_data
							&& isset( $_POST[ $pass_field_id ] )
							&& ! empty( $_POST[ $pass_field_id ] )
							&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $pass_field_id ] ) ), $pass_field_id )
						) {
							$pfx_passphrase = wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $pass_field_id ] ) ), $pass_field_id );

							$result = $this->process_export_certificates( $pfx_data, $pfx_passphrase );

							$result_pcert = isset( $result['pcert'] ) ? $result['pcert'] : null;
							$result_key   = isset( $result['key'] ) ? $result['key'] : null;

							if ( ! self::string_empty( $result_pcert ) && ! self::string_empty( $result_key ) ) {
								// Overwrite advanced settings values.
								$_POST['woocommerce_moldovaagroindbank_maib_pcert'] = $result_pcert;
								$_POST['woocommerce_moldovaagroindbank_maib_key']   = $result_key;

								// Certificates export success - save PFX bundle to settings.
								// Warning: base64_encode() can be used to obfuscate code which is strongly discouraged. Please verify that the function is used for benign reasons.
								// (WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode).
								$_POST[ $pfx_field_id ] = base64_encode( $pfx_data ); // phpcs:ignore

								return;
							}
						}
					}
				}
			} catch ( Exception $ex ) {
				$this->log( $ex, WC_Log_Levels::ERROR );
			}

			// Preserve existing value.
			$_POST[ $pfx_field_id ] = $pfx_option_value;
		}

		/**
		 * Initialises the certificate if not Initialised.
		 *
		 * @return void
		 */
		protected function initialize_certificates() {
			try {
				if ( ! is_readable( $this->maib_pcert ) || ! is_readable( $this->maib_key ) ) {
					if ( self::is_overwritable( $this->maib_pcert ) && self::is_overwritable( $this->maib_key ) ) {
						if ( ! self::string_empty( $this->maib_pfxcert ) ) {
							// Warning: base64_decode() can be used to obfuscate code which is strongly discouraged. Please verify that the function is used for benign reasons.
							// (WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode).
							$pfx_cert_data = base64_decode( $this->maib_pfxcert ); // phpcs:ignore
							if ( false !== $pfx_cert_data ) {
								$result = $this->process_export_certificates( $pfx_cert_data, $this->maib_key_password );

								$result_pcert = isset( $result['pcert'] ) ? $result['pcert'] : '';
								$result_key   = isset( $result['key'] ) ? $result['key'] : '';

								if ( ! self::string_empty( $result_pcert ) && ! self::string_empty( $result_key ) ) {
									$this->update_option( 'maib_pcert', $result_pcert );
									$this->update_option( 'maib_key', $result_key );

									$this->maib_pcert = $result_pcert;
									$this->maib_key   = $result_key;
								}
							}
						}
					}
				}
			} catch ( Exception $ex ) {
				$this->log( $ex, WC_Log_Levels::ERROR );
			}
		}

		/**
		 *  Validates the certificate
		 *
		 * @param string $cert_file the certificate.
		 *
		 * @return mixed
		 */
		protected function validate_certificate( $cert_file ) {
			try {
				$validate_result = $this->validate_file( $cert_file );
				if ( ! self::string_empty( $validate_result ) ) {
					return $validate_result;
				}

				$cert_data = $this->file_system->get_contents( $cert_file );
				$cert      = openssl_x509_read( $cert_data );

				if ( false !== $cert ) {
					$cert_info = openssl_x509_parse( $cert );

					if ( false !== $cert_info ) {
						$valid_until = $cert_info['validTo_time_t'];

						// Certificate already expired or expires in the next 30 days.
						if ( $valid_until <= ( time() - 2592000 ) ) {
							return sprintf(
								/* translators: %s: cert valid date. */
								__( 'Certificate valid until %1$s', 'wc-moldovaagroindbank' ),
								date_i18n( get_option( 'date_format' ), $valid_until )
							);
						}

						return null;
					}
				}

				$this->log_openssl_errors();
				return sprintf( __( 'Invalid certificate', 'wc-moldovaagroindbank' ) );
			} catch ( Exception $ex ) {
				$this->log( $ex, WC_Log_Levels::ERROR );
				return sprintf( __( 'Could not validate certificate', 'wc-moldovaagroindbank' ) );
			}
		}

		/**
		 * Validates the certificate private key.
		 *
		 * @param string $cert_file the path to the certificate.
		 * @param string $key_file thepath to the the key of the certificate.
		 * @param string $key_passphrase thepassword to the certificate.
		 *
		 * @return string|void
		 */
		protected function validate_private_key( $cert_file, $key_file, $key_passphrase ) {
			try {
				$validate_result = $this->validate_file( $key_file );
				if ( ! self::string_empty( $validate_result ) ) {
					return $validate_result;
				}

				$key_data    = $this->file_system->get_contents( $key_file );
				$private_key = openssl_pkey_get_private( $key_data, $key_passphrase );

				if ( false === $private_key ) {
					$this->log_openssl_errors();
					return sprintf( __( 'Invalid private key or wrong private key passphrase', 'wc-moldovaagroindbank' ) );
				}

				$cert_data      = $this->file_system->get_contents( $cert_file );
				$key_check_data = array(
					0 => $key_data,
					1 => $key_passphrase,
				);

				$validate_result = openssl_x509_check_private_key( $cert_data, $key_check_data );
				if ( false === $validate_result ) {
					$this->log_openssl_errors();
					return sprintf( __( 'Private key does not correspond to client certificate', 'wc-moldovaagroindbank' ) );
				}
			} catch ( Exception $ex ) {
				$this->log( $ex, WC_Log_Levels::ERROR );
				return sprintf( __( 'Could not validate private key', 'wc-moldovaagroindbank' ) );
			}

		}

		/**
		 * Validates the file.
		 *
		 * @param string $file the pathto the File.
		 *
		 * @return string
		 */
		protected function validate_file( $file ) {
			try {
				if ( self::string_empty( $file ) ) {
					return sprintf( __( 'Invalid value', 'wc-moldovaagroindbank' ) );
				}

				if ( ! file_exists( $file ) ) {
					return sprintf( __( 'File not found', 'wc-moldovaagroindbank' ) );
				}

				if ( ! is_readable( $file ) ) {
					return sprintf( __( 'File not readable', 'wc-moldovaagroindbank' ) );
				}
			} catch ( Exception $ex ) {
				$this->log( $ex, WC_Log_Levels::ERROR );
				return sprintf( __( 'Could not validate file', 'wc-moldovaagroindbank' ) );
			}
		}

		/**
		 * Exports the certificate using the passphrase.
		 *
		 * @param string $pfx_cert_data the string data of the certificate.
		 * @param string $pfx_passphrase the password phrase of the certificate.
		 *
		 * @return array
		 */
		protected function process_export_certificates( $pfx_cert_data, $pfx_passphrase ) {
			$result    = array();
			$pfx_certs = array();
			$error     = null;

			if ( openssl_pkcs12_read( $pfx_cert_data, $pfx_certs, $pfx_passphrase ) ) {
				if ( isset( $pfx_certs['pkey'] ) ) {
					$pfx_pkey = null;
					if ( openssl_pkey_export( $pfx_certs['pkey'], $pfx_pkey, $pfx_passphrase ) ) {
						$result['key'] = self::save_temp_file( $pfx_pkey, 'key.pem' );

						if ( isset( $pfx_certs['cert'] ) ) {
							$result['pcert'] = self::save_temp_file( $pfx_certs['cert'], 'pcert.pem' );
						}
					}
				}
			} else {
				$error = sprintf( __( 'Invalid certificate or wrong passphrase', 'wc-moldovaagroindbank' ) );
			}

			if ( ! self::string_empty( $error ) ) {
				$this->log( $error, WC_Log_Levels::ERROR );
				$this->log_openssl_errors();
			}

			return $result;
		}

		/**
		 * Logs all OpenSSL errors.
		 *
		 * @return void
		 */
		protected function log_openssl_errors() {
			// Warning: Variable assignment found within a condition. Did you mean to do a comparison?
			// (WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition).
			while ( $openssl_error = openssl_error_string() ) { // phpcs:ignore
				$this->log( $openssl_error, WC_Log_Levels::ERROR );
			}
		}

		/**
		 * Saves the temporary file to the file system.
		 *
		 * @param string $file_data the file data.
		 * @param string $file_suffix the file suffix.
		 *
		 * @return mixed
		 */
		public static function save_temp_file( $file_data, $file_suffix = '' ) {
			// http://www.pathname.com/fhs/pub/fhs-2.3.html#TMPTEMPORARYFILES.
			$temp_file_name = sprintf( '%1$s%2$s_', self::MOD_PREFIX, $file_suffix );
			$temp_file      = tempnam( get_temp_dir(), $temp_file_name );

			if ( ! $temp_file ) {
				/* translators: %s: file name. */
				self::static_log( sprintf( __( 'Unable to create temporary file: %1$s', 'wc-moldovaagroindbank' ), $temp_file ), WC_Log_Levels::ERROR );
				return null;
			}

			$file_system = new WP_Filesystem_Direct( null );
			if ( false === $file_system->put_contents( $temp_file, $file_data ) ) {
				/* translators: %s: file name. */
				self::static_log( sprintf( __( 'Unable to save data to temporary file: %1$s', 'wc-moldovaagroindbank' ), $temp_file ), WC_Log_Levels::ERROR );
				return null;
			}

			return $temp_file;
		}

		/**
		 * Verifies if the temporary file exists.
		 *
		 * @param string $file_name thename of the file.
		 *
		 * @return boolean
		 */
		public static function is_temp_file( $file_name ) {
			$temp_dir = get_temp_dir();
			return strncmp( $file_name, $temp_dir, strlen( $temp_dir ) ) === 0;
		}

		/**
		 * Verifies if the temporary file is over writable
		 *
		 * @param string $file_name thename of the file.
		 *
		 * @return boolean
		 */
		public static function is_overwritable( $file_name ) {
			return self::string_empty( $file_name ) || self::is_temp_file( $file_name );
		}
		// endregion.

		/**
		 * Initialises the Moldovaagroindbank Gateway Client.
		 *
		 * @return \Maib\MaibApi\MaibClient
		 */
		protected function init_maib_client() {
			// http://docs.guzzlephp.org/en/stable/request-options.html.
			// https://www.php.net/manual/en/function.curl-setopt.php.
			$options = array(
				'base_uri' => $this->base_url,
				'debug'    => $this->debug,
				'verify'   => true,
				'cert'     => array( $this->maib_pcert, $this->maib_key_password ),
				'ssl_key'  => $this->maib_key,
				'config'   => array(
					'curl' => array(
						CURLOPT_SSL_VERIFYHOST => 2,
						CURLOPT_SSL_VERIFYPEER => true,
					),
				),
			);

			// region Init Client.

			if ( $this->debug ) {
				$log = new Logger( 'maib_guzzle_request' );
				$log->pushHandler( new StreamHandler( __DIR__ . '/logs/maib_guzzle_request.log', Logger::DEBUG ) );
				$stack = HandlerStack::create();
				$stack->push(
					Middleware::log( $log, new MessageFormatter( MessageFormatter::DEBUG ) )
				);
			}
			if ( isset( $stack ) ) {
				$options['handler'] = $stack;
			}
			$guzzle_client = new Client( $options );
			// https://github.com/alexminza/wc-moldovaagroindbank/issues/17#issuecomment-850337174.
			$maib_description = new MaibDescription( array(), $this->merchant_handler_url );
			$client           = new MaibClient( $guzzle_client, $maib_description );
			// endregion.

			return $client;
		}

		/**
		 * Returns the MAIB server ur.
		 *
		 * @return string
		 */
		protected function get_maib_gateway_url() {
			$gateway_url = $this->base_url . $this->merchant_handler_url;
			return $gateway_url;
		}

		/**
		 * Processes the payment.
		 *
		 * @param int $order_id theid of the order.
		 *
		 * @return array
		 */
		public function process_payment( $order_id ) {
			if ( ! $this->check_settings() ) {
				/* translators: %s: method title. */
				$message = sprintf( __( '%1$s is not properly configured.', 'wc-moldovaagroindbank' ), $this->method_title );

				wc_add_notice( $message, 'error' );
				$this->settings_admin_notice();

				return array(
					'result'   => 'failure',
					'messages' => $message,
				);
			}

			$order                  = wc_get_order( $order_id );
			$order_total            = $this->price_format( $order->get_total() );
			$order_currency_numcode = $this->get_currency_numcode( $order->get_currency() );
			$order_description      = $this->get_order_description( $order );
			$client_ip              = self::get_client_ip();
			$lang                   = $this->get_language();

			try {
				$client        = $this->init_maib_client();
				$client_result = null;

				switch ( $this->transaction_type ) {
					case self::TRANSACTION_TYPE_CHARGE:
						$client_result = $client->registerSmsTransaction(
							$order_total,
							$order_currency_numcode,
							$client_ip,
							$order_description,
							$lang
						);

						break;

					case self::TRANSACTION_TYPE_AUTHORIZATION:
						$client_result = $client->registerDmsAuthorization(
							$order_total,
							$order_currency_numcode,
							$client_ip,
							$order_description,
							$lang
						);

						break;

					default:
						/* translators: %s: Transaction type, %s: order id. */
						$this->log( sprintf( 'Unknown transaction type: %1$s Order ID: %2$s', $this->transaction_type, $order_id ), WC_Log_Levels::ERROR );
						break;
				}
			} catch ( Exception $ex ) {
				$this->log( $ex, WC_Log_Levels::ERROR );
			}

			// region Validate response.
			$trans_id = null;
			if ( ! empty( $client_result ) ) {
				$trans_id = isset( $client_result[ self::MAIB_TRANSACTION_ID ] )
					? $client_result[ self::MAIB_TRANSACTION_ID ]
					: null;
			}
			// endregion.

			if ( ! self::string_empty( $trans_id ) ) {
				// region Update order payment transaction metadata.
				self::set_post_meta( $order_id, self::MOD_TRANSACTION_TYPE, $this->transaction_type );
				self::set_post_meta( $order_id, self::MOD_TRANSACTION_ID, $trans_id );
				// endregion.

				// region Log transaction initiation.
				/* translators: %s: method title, %s: query. */
				$message = sprintf( __( 'Payment initiated via %1$s: %2$s', 'wc-moldovaagroindbank' ), $this->method_title, http_build_query( $client_result ) );
				$message = $this->get_order_message( $message );
				$this->log( $message, WC_Log_Levels::INFO );
				$order->add_order_note( $message );
				// endregion.

				$redirect = add_query_arg(
					self::MAIB_TRANS_ID,
					rawurlencode( $trans_id ),
					$this->skip_receipt_page
						? $this->client_handler_url
						: $order->get_checkout_payment_url( true )
				);

				return array(
					'result'   => 'success',
					'redirect' => $redirect,
				);
			}

			/* translators: %s: method title. */
			$message = sprintf( __( 'Payment initiation failed via %1$s.', 'wc-moldovaagroindbank' ), $this->method_title );
			$this->log( $message, WC_Log_Levels::ERROR );
			$this->log( self::print_var( $client_result ), WC_Log_Levels::ERROR );

			wc_add_notice( $message, 'error' );
			$this->settings_admin_notice();

			return array(
				'result'   => 'failure',
				'messages' => $message,
			);
		}

		// region Order status.

		/**
		 * Verifies the transaction if it can to be completed.
		 *
		 * @param int $order_id the id of the order.
		 *
		 * @return void
		 */
		public function order_status_completed( $order_id ) {
			$this->log( sprintf( '%1$s: OrderID=%2$s', __FUNCTION__, $order_id ) );

			if ( ! $this->transaction_auto ) {
				return;
			}

			$order = wc_get_order( $order_id );

			if ( $order && $order->get_payment_method() === $this->id ) {
				if ( $order->has_status( 'completed' ) && $order->is_paid() ) {
					$transaction_type = get_post_meta( $order_id, self::MOD_TRANSACTION_TYPE, true );

					if ( self::TRANSACTION_TYPE_AUTHORIZATION === $transaction_type ) {
						return $this->complete_transaction( $order_id, $order );
					}
				}
			}
		}

		/**
		 * Verifies the transaction if it can to be completed.
		 *
		 * @param int $order_id the id of the order.
		 *
		 * @return void
		 */
		public function order_status_cancelled( $order_id ) {
			/* translators: %s: Function, %s: order id. */
			$this->log( sprintf( '%1$s: OrderID=%2$s', __FUNCTION__, $order_id ) );

			if ( ! $this->transaction_auto ) {
				return;
			}

			$order = wc_get_order( $order_id );

			if ( $order && $order->get_payment_method() === $this->id ) {
				if ( $order->has_status( 'cancelled' ) && $order->is_paid() ) {
					$transaction_type = get_post_meta( $order_id, self::MOD_TRANSACTION_TYPE, true );

					if ( self::TRANSACTION_TYPE_AUTHORIZATION === $transaction_type ) {
						return $this->refund_transaction( $order_id, $order );
					}
				}
			}
		}

		/**
		 * Verifies the transaction if it can to be refunded.
		 *
		 * @param int $order_id the id of the order.
		 *
		 * @return mixed
		 */
		public function order_status_refunded( $order_id ) {
			/* translators: %s: Function, %s: order id. */
			$this->log( sprintf( '%1$s: OrderID=%2$s', __FUNCTION__, $order_id ) );

			$order = wc_get_order( $order_id );

			if ( $order && $order->get_payment_method() === $this->id ) {
				if ( $order->has_status( 'refunded' ) && $order->is_paid() ) {
					return $this->refund_transaction( $order_id, $order );
				}
			}
		}

		// endregion.

		/**
		 * Sets the transaction status to complete.
		 *
		 * @param int    $order_id the id of the order.
		 * @param object $order the order entity.
		 *
		 * @return bool
		 */
		public function complete_transaction( $order_id, $order ) {
			/* translators: %s: Function, %s: order id. */
			$this->log( sprintf( '%1$s: OrderID=%2$s', __FUNCTION__, $order_id ) );

			$trans_id               = $this->get_order_trans_id( $order_id );
			$order_total            = $this->price_format( $this->get_order_net_total( $order ) );
			$order_currency_numcode = $this->get_currency_numcode( $order->get_currency() );
			$order_description      = $this->get_order_description( $order );
			$client_ip              = self::get_client_ip();
			$lang                   = $this->get_language();

			try {
				// Execute DMS transaction.
				$client            = $this->init_maib_client();
				$completion_result = $client->makeDMSTrans(
					$trans_id,
					$order_total,
					$order_currency_numcode,
					$client_ip,
					$order_description,
					$lang
				);

				$this->log( self::print_var( $completion_result ) );
			} catch ( Exception $ex ) {
				$this->log( $ex, WC_Log_Levels::ERROR );

				/* translators: %s: method title, %s: message. */
				$message = sprintf( __( 'Payment completion failed via %1$s: %2$s', 'wc-moldovaagroindbank' ), $this->method_title, $ex->getMessage() );
				$message = $this->get_order_message( $message );
				$order->add_order_note( $message );

				return false;
			}

			if ( ! empty( $completion_result ) ) {
				$result = $completion_result[ self::MAIB_RESULT ];

				if ( self::MAIB_RESULT_OK === $result ) {
					/* translators: %s: method title, %s: query. */
					$message = sprintf( __( 'Payment completed via %1$s: %2$s', 'wc-moldovaagroindbank' ), $this->method_title, http_build_query( $completion_result ) );
					$message = $this->get_order_message( $message );
					$this->log( $message, WC_Log_Levels::INFO );

					$order->add_order_note( $message );
					$this->mark_order_paid( $order, $trans_id );

					return true;
				}
			}

			return false;
		}

		/**
		 * Refunds the money by transaction.
		 *
		 * @param int    $order_id the id of the order.
		 * @param object $order the order entity.
		 * @param float  $amount theamount of the order.
		 *
		 * @return mixed
		 */
		public function refund_transaction( $order_id, $order, $amount = null ) {
			$this->log( sprintf( '%1$s: OrderID=%2$s Amount=%3$s', __FUNCTION__, $order_id, $amount ) );

			$trans_id       = $this->get_order_trans_id( $order_id );
			$order_total    = $order->get_total();
			$order_currency = $order->get_currency();

			if ( ! isset( $amount ) ) {
				// Refund entirely if no amount is specified.
				$amount = $order_total;
			}

			if ( $amount <= 0 || $amount > $order_total ) {
				$message = sprintf( __( 'Invalid refund amount', 'wc-moldovaagroindbank' ) );
				$this->log( $message, WC_Log_Levels::ERROR );

				return new WP_Error( 'error', $message );
			}

			try {
				$client          = $this->init_maib_client();
				$reversal_result = $client->revertTransaction( $trans_id, $amount );

				$this->log( self::print_var( $reversal_result ) );
			} catch ( Exception $ex ) {
				$this->log( $ex, WC_Log_Levels::ERROR );

				$message = sprintf(
					/* translators: %s: amount, %s: currency, %s: method title, %s: message. */
					__( 'Refund of %1$s %2$s via %3$s failed: %4$s', 'wc-moldovaagroindbank' ),
					$amount,
					$order_currency,
					$this->method_title,
					$ex->getMessage()
				);
				$message = $this->get_order_message( $message );
				$order->add_order_note( $message );

				return new WP_Error( 'error', $message );
			}

			if ( ! empty( $reversal_result ) ) {
				$result = $reversal_result[ self::MAIB_RESULT ];

				if ( self::MAIB_RESULT_OK === $result ) {
					/* translators: %s: amount, %s: currency, %s: method title, %s: query. */
					$message = sprintf( __( 'Refund of %1$s %2$s via %3$s approved: %4$s', 'wc-moldovaagroindbank' ), $amount, $order_currency, $this->method_title, http_build_query( $reversal_result ) );
					$message = $this->get_order_message( $message );
					$this->log( $message, WC_Log_Levels::INFO );
					$order->add_order_note( $message );

					if ( $order->get_total() === $order->get_total_refunded() ) {
						$this->mark_order_refunded( $order );
					}

					return true;
				}
			}

			return false;
		}

		/**
		 * Checks the transactions result.
		 *
		 * @param string $trans_id the id of the transaction.
		 *
		 * @return mixed
		 */
		protected function check_transaction( $trans_id ) {
			$client_result = $this->get_transaction_result( $trans_id );

			if ( ! empty( $client_result ) ) {
				$result = $client_result[ self::MAIB_RESULT ];

				if ( self::MAIB_RESULT_OK === $result ) {
					$rrn           = $client_result[ self::MAIB_RESULT_RRN ];
					$approval_code = $client_result[ self::MAIB_RESULT_APPROVAL_CODE ];

					if ( ! self::string_empty( $rrn ) && ! self::string_empty( $approval_code ) ) {
						return $client_result;
					}
				}
			}

			return false;
		}

		/**
		 * Returns the transactions result.
		 *
		 * @param string $trans_id the id of the transaction.
		 *
		 * @return array
		 */
		protected function get_transaction_result( $trans_id ) {
			$client_ip = self::get_client_ip();

			$client_result = null;
			try {
				$client        = $this->init_maib_client();
				$client_result = $client->getTransactionResult( $trans_id, $client_ip );

				$this->log( self::print_var( $client_result ) );
			} catch ( Exception $ex ) {
				$this->log( $ex, WC_Log_Levels::ERROR );
			}

			return $client_result;
		}

		/**
		 * Checks the response from MAIB Server.
		 *
		 * @return bool|void
		 */
		public function check_response() {
			if ( ! empty( $_SERVER['REQUEST_METHOD'] ) && 'GET' === $_SERVER['REQUEST_METHOD'] ) {
				$message = __( 'This Callback URL works and should not be called directly.', 'wc-moldovaagroindbank' );

				wc_add_notice( $message, 'notice' );

				wp_safe_redirect( wc_get_cart_url() );
				return false;
			}

			$trans_id = ! empty( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::MAIB_TRANS_ID ] ) ) ) ) ?? '';
			$trans_id = wc_clean( $trans_id );

			if ( self::string_empty( $trans_id ) ) {
				/* translators: %s: method title. */
				$message = sprintf( __( 'Payment verification failed: Transaction ID not received from %1$s.', 'wc-moldovaagroindbank' ), $this->method_title );
				$this->log( $message, WC_Log_Levels::ERROR );

				wc_add_notice( $message, 'error' );
				$this->settings_admin_notice();

				wp_safe_redirect( wc_get_cart_url() );
				return false;
			}

			$order = $this->get_order_by_trans_id( $trans_id );
			if ( ! $order ) {
				/* translators: %s: transaction id. */
				$message = sprintf( __( 'Order not found by Transaction ID: %1$s received from %2$s.', 'wc-moldovaagroindbank' ), $trans_id, $this->method_title );
				$this->log( $message, WC_Log_Levels::ERROR );

				wc_add_notice( $message, 'error' );
				$this->settings_admin_notice();

				wp_safe_redirect( wc_get_cart_url() );
				return false;
			}

			$order_id = $order->get_id();

			try {
				$client_result = $this->check_transaction( $trans_id );
			} catch ( Exception $ex ) {
				$this->log( $ex, WC_Log_Levels::ERROR );
			}

			if ( ! empty( $client_result ) ) {
				// region Update order payment metadata.
				foreach ( $client_result as $key => $value ) {
					self::set_post_meta( $order_id, strtolower( self::MOD_PREFIX . $key ), $value );
				}
				// endregion.
				/* translators: %s: method title, %s: query. */
				$message = sprintf( __( 'Payment authorized via %1$s: %2$s', 'wc-moldovaagroindbank' ), $this->method_title, http_build_query( $client_result ) );
				$message = $this->get_order_message( $message );
				$this->log( $message, WC_Log_Levels::INFO );
				$order->add_order_note( $message );

				$this->mark_order_paid( $order, $trans_id );
				WC()->cart->empty_cart();
				/* translators: %s: order id, %s: method title. */
				$message = sprintf( __( 'Order #%1$s paid successfully via %2$s.', 'wc-moldovaagroindbank' ), $order_id, $this->method_title );
				$this->log( $message, WC_Log_Levels::INFO );

				wc_add_notice( $message, 'success' );

				wp_safe_redirect( $this->get_return_url( $order ) );
				return true;
			} else {
				/* translators: %s: order id, %s: method title. */
				$message = sprintf( __( 'Order #%1$s payment failed via %2$s.', 'wc-moldovaagroindbank' ), $order_id, $this->method_title );
				$message = $this->get_order_message( $message );
				$this->log( $message, WC_Log_Levels::ERROR );

				$order->add_order_note( $message );
				wc_add_notice( $message, 'error' );
				$this->settings_admin_notice();

				wp_safe_redirect( $order->get_checkout_payment_url() );
				return false;
			}
		}

		/**
		 * Sets the order payment to completed.
		 *
		 * @param mixed  $order the order.
		 * @param string $trans_id the id of the transaction.
		 *
		 * @return void
		 */
		protected function mark_order_paid( $order, $trans_id ) {
			if ( ! $order->is_paid() ) {
				$order->payment_complete( $trans_id );
			}
		}

		/**
		 * Sets the order status to refunded.
		 *
		 * @param object $order the order.
		 *
		 * @return void
		 */
		protected function mark_order_refunded( $order ) {
			/* translators: %s: method title. */
			$message = sprintf( __( 'Order fully refunded via %1$s.', 'wc-moldovaagroindbank' ), $this->method_title );
			$message = $this->get_order_message( $message );

			// Mark order as refunded if not already set.
			if ( ! $order->has_status( 'refunded' ) ) {
				$order->update_status( 'refunded', $message );
			} else {
				$order->add_order_note( $message );
			}
		}

		/**
		 * Generates the payment button form.
		 *
		 * @param string $trans_id the id of the transaction.
		 *
		 * @return string
		 */
		protected function generate_form( $trans_id ) {
			$form_html = '<form name="returnform" action="%1$s" method="POST">
				<input type="hidden" name="trans_id" value="%2$s">
				<input type="submit" name="submit" class="button alt" value="%3$s">
			</form>';

			return sprintf(
				$form_html,
				$this->client_handler_url,
				$trans_id,
				__( 'Pay', 'wc-moldovaagroindbank' )
			);
		}

		/**
		 * Returns the receipt page.
		 *
		 * @param int $order_id the id of the order.
		 *
		 * @return string
		 */
		public function receipt_page( $order_id ) {
			$trans_id = ! empty( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET[ self::MAIB_TRANS_ID ] ) ) ) ) ?? '';
			$trans_id = wc_clean( $trans_id );

			if ( self::string_empty( $trans_id ) ) {
				/* translators: %s: order id. */
				$message = sprintf( __( 'Transaction ID not found for order #%1$s', 'wc-moldovaagroindbank' ), $order_id );
				$this->log( $message, WC_Log_Levels::ERROR );

				wc_add_notice( $message, 'error' );

				return array(
					'result'   => 'failure',
					'messages' => $message,
				);
			}

			echo wp_kses( $this->generate_form( $trans_id ), $this->generate_form( $trans_id ) );
		}

		/**
		 * Processes the refund transaction.
		 *
		 * @param int    $order_id the id of the order.
		 * @param int    $amount the amount of the order.
		 * @param string $reason the reason of the refund.
		 *
		 * @return string
		 */
		public function process_refund( $order_id, $amount = null, $reason = '' ) {
			$order = wc_get_order( $order_id );
			return $this->refund_transaction( $order_id, $order, $amount );
		}

		/**
		 * Closes the work day on MAIB Server.
		 *
		 * @return string
		 */
		public function close_day() {
			if ( $this->check_settings() ) {
				try {
					$client          = $this->init_maib_client();
					$closeday_result = $client->closeDay();

					$this->log( self::print_var( $closeday_result ) );
				} catch ( Exception $ex ) {
					$this->log( $ex, WC_Log_Levels::ERROR );
				}

				$message_result = http_build_query( $closeday_result );

				if ( ! empty( $closeday_result ) ) {
					$result = $closeday_result[ self::MAIB_RESULT ];

					if ( self::MAIB_RESULT_OK === $result ) {
						/* translators: %s: method title, %s: message result. */
						$message = sprintf( __( 'Close business day via %1$s succeeded: %2$s', 'wc-moldovaagroindbank' ), $this->method_title, $message_result );
						$this->log( $message, WC_Log_Levels::NOTICE );

						return $message;
					}
				}
			} else {
				/* translators: %s: method title. */
				$message_result = sprintf( __( '%1$s is not properly configured.', 'wc-moldovaagroindbank' ), $this->method_title );
			}
			/* translators: %s: method title, %s: message result. */
			$message = sprintf( __( 'Close business day via %1$s failed: %2$s', 'wc-moldovaagroindbank' ), $this->method_title, $message_result );
			$this->log( $message, WC_Log_Levels::ERROR );
			return $message;
		}

		/**
		 * Returns the order message.
		 *
		 * @param string $message the message.
		 * @return string|void
		 */
		protected function get_order_message( $message ) {
			if ( $this->testmode ) {
				$message = 'TEST: ' . $message;
			}

			return $message;
		}

		/**
		 * Returns the NET order amount.
		 *
		 * @param object $order the order.
		 *
		 * @return int
		 */
		protected function get_order_net_total( $order ) {
			// https://github.com/woocommerce/woocommerce/issues/17795.
			// https://github.com/woocommerce/woocommerce/pull/18196.
			$total_refunded = 0;
			if ( method_exists( WC_Order_Refund::class, 'get_refunded_payment' ) ) {
				$order_refunds = $order->get_refunds();
				foreach ( $order_refunds as $refund ) {
					if ( $refund->get_refunded_payment() ) {
						$total_refunded += $refund->get_amount();
					}
				}
			} else {
				$total_refunded = $order->get_total_refunded();
			}

			$order_total = $order->get_total();
			return $order_total - $total_refunded;
		}

		// NOTE: MAIB Payment Gateway API does not currently support passing Order ID for transactions.

		/**
		 * Returns the order by transation id.
		 *
		 * @param string $trans_id the id of the transaction.
		 * @return mixed
		 */
		protected function get_order_by_trans_id( $trans_id ) {
			global $wpdb;
			$query = $wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta WHERE meta_key=%s AND meta_value=%s",
				self::MOD_TRANSACTION_ID,
				$trans_id
			);
			// https://github.com/WordPress/WordPress-Coding-Standards/issues/508.
			// Warning: Usage of a direct database call is discouraged.
			// (WordPress.DB.DirectDatabaseQuery.DirectQuery).
			// Warning: Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
			// (WordPress.DB.DirectDatabaseQuery.NoCaching).
			$order_id = $wpdb->get_var( // phpcs:ignore
				$wpdb->prepare(
					"SELECT post_id FROM $wpdb->postmeta WHERE meta_key=%s AND meta_value=%s",
					self::MOD_TRANSACTION_ID,
					$trans_id
				)
			);
			if ( ! $order_id ) {
				return false;
			}

			return wc_get_order( $order_id );
		}

		/**
		 * Returns the transaction id by order id.
		 *
		 * @param int $order_id the order id.
		 *
		 * @return string
		 */
		protected function get_order_trans_id( $order_id ) {
			$trans_id = get_post_meta( $order_id, self::MOD_TRANSACTION_ID, true );
			return $trans_id;
		}

		/**
		 * Formats the price.
		 *
		 * @param float|int $price the price.
		 *
		 * @return float|int
		 */
		protected function price_format( $price ) {
			$decimals = 2;

			return number_format( $price, $decimals, '.', '' );
		}

		// https://en.wikipedia.org/wiki/ISO_4217.
		/**
		 * The array of theallowed currencies.
		 *
		 * @var array
		 */
		private $currency_numcodes = array(
			'EUR' => 978,
			'USD' => 840,
			'MDL' => 498,
		);

		/**
		 * Returns the Numeric Code of the currency.
		 *
		 * @param string $currency the code.
		 * @return string
		 */
		protected function get_currency_numcode( $currency ) {
			return $this->currency_numcodes[ $currency ];
		}

		/**
		 * Returns the order description for the reciept.
		 *
		 * @param object $order the order entity.
		 * @return string
		 */
		protected function get_order_description( $order ) {
			return sprintf(
				$this->order_template,
				$order->get_id(),
				$this->get_order_items_summary( $order )
			);
		}

		/**
		 * Rerturns the summary of the order products.
		 *
		 * @param object $order the order entity.
		 * @return string
		 */
		protected function get_order_items_summary( $order ) {
			$items       = $order->get_items();
			$items_names = array_map(
				function( $item ) {
					return $item->get_name();
				},
				$items
			);

			return join( ', ', $items_names );
		}

		/**
		 * Returns the Language code.
		 *
		 * @return string
		 */
		protected function get_language() {
			$lang = get_locale();
			return substr( $lang, 0, 2 );
		}

		/**
		 * Returns the client IP
		 *
		 * @return string
		 */
		public static function get_client_ip() {
			return WC_Geolocation::get_ip_address();
		}

		/**
		 * Returns the call back URL.
		 *
		 * @return string
		 */
		protected function get_callback_url() {
			// https://docs.woocommerce.com/document/wc_api-the-woocommerce-api-callback/.

			// https://codex.wordpress.org/Function_Reference/home_url.

			return add_query_arg( 'wc-api', get_class( $this ), home_url( '/' ) );
		}

		/**
		 * Returns the URL of the LOGS.
		 *
		 * @return string
		 */
		public static function get_logs_url() {
			return add_query_arg(
				array(
					'page' => 'wc-status',
					'tab'  => 'logs',
				),
				admin_url( 'admin.php' )
			);
		}

		/**
		 * Returns the path to the LOGS file.
		 *
		 * @return string
		 */
		public static function get_logs_path() {
			return WC_Log_Handler_File::get_log_file_path( self::MOD_ID );
		}

		/**
		 * Returns the URL of the settings page.
		 *
		 * @return string
		 */
		public static function get_settings_url() {
			return add_query_arg(
				array(
					'page'    => 'wc-settings',
					'tab'     => 'checkout',
					'section' => self::MOD_ID,
				),
				admin_url( 'admin.php' )
			);
		}

		/**
		 * Sets the post meta.
		 *
		 * @param string $post_id the id of the custom field.
		 * @param string $meta_key the key of the custom field.
		 * @param string $meta_value the value of the custom field.
		 * @return void
		 */
		public static function set_post_meta( $post_id, $meta_key, $meta_value ) {
			// https://developer.wordpress.org/reference/functions/add_post_meta/#comment-465.
			if ( ! add_post_meta( $post_id, $meta_key, $meta_value, true ) ) {
				update_post_meta( $post_id, $meta_key, $meta_value );
			}
		}

		/**
		 * Loggs the message.
		 *
		 * @param string $message the message to log.
		 * @param string $level the level of the log.
		 *
		 * @return void
		 */
		protected function log( $message, $level = WC_Log_Levels::DEBUG ) {
			// https://woocommerce.wordpress.com/2017/01/26/improved-logging-in-woocommerce-2-7/.
			// https://stackoverflow.com/questions/1423157/print-php-call-stack.
			$this->logger->log( $level, $message, $this->log_context );
		}

		/**
		 * Loggs the message. Static Usage.
		 *
		 * @param string $message the message to log.
		 * @param string $level the level of the log.
		 *
		 * @return void
		 */
		public static function static_log( $message, $level = WC_Log_Levels::DEBUG ) {
			$logger      = wc_get_logger();
			$log_context = array( 'source' => self::MOD_ID );
			$logger->log( $level, $message, $log_context );
		}

		/**
		 * Returns the prepared variable with the wc_print_r function.
		 *
		 * @param mixed $var the expressions.
		 *
		 * @return string|bool
		 */
		public static function print_var( $var ) {
			// https://docs.woocommerce.com/wc-apidocs/function-wc_print_r.html.
			return wc_print_r( $var, true );
		}

		/**
		 * Virifies if the string length is equal to 0.
		 *
		 * @param string $string the string to be verifyed.
		 *
		 * @return bool
		 */
		protected static function string_empty( $string ) {
			return strlen( $string ) === 0;
		}

		// region Admin.
		/**
		 * Plugins the links to the links array.
		 *
		 * @param string $links the string of the url's.
		 *
		 * @return array
		 */
		public static function plugin_links( $links ) {
			$plugin_links = array(
				/* translators: %s: URL. */
				sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::get_settings_url() ), __( 'Settings', 'wc-moldovaagroindbank' ) ),
			);

			return array_merge( $plugin_links, $links );
		}

		/**
		 * Plugins the order actions to the actions array.
		 *
		 * @param array $actions the actions.
		 *
		 * @return array
		 */
		public static function order_actions( $actions ) {
			global $theorder;
			if ( $theorder->get_payment_method() !== self::MOD_ID ) {
				return $actions;
			}

			if ( $theorder->is_paid() ) {
				$transaction_type = get_post_meta( $theorder->get_id(), self::MOD_TRANSACTION_TYPE, true );
				if ( self::TRANSACTION_TYPE_AUTHORIZATION === $transaction_type ) {
					/* translators: %s: Mod title. */
					$actions['moldovaagroindbank_complete_transaction'] = sprintf( __( 'Complete %1$s transaction', 'wc-moldovaagroindbank' ), self::MOD_TITLE );
				}
			} elseif ( $theorder->has_status( 'pending' ) ) {
				/* translators: %s: Mod title. */
				$actions['moldovaagroindbank_verify_transaction'] = sprintf( __( 'Verify %1$s transaction', 'wc-moldovaagroindbank' ), self::MOD_TITLE );
			}

			return $actions;
		}

		/**
		 * Changes the transaction status to completed.
		 *
		 * @param object $order the order entity.
		 *
		 * @return bool
		 */
		public static function action_complete_transaction( $order ) {
			$order_id = $order->get_id();

			$plugin = new self();
			return $plugin->complete_transaction( $order_id, $order );
		}

		/**
		 * Changes the transaction status to reversed.
		 *
		 * @param object $order the order entity.
		 *
		 * @return mixed
		 */
		public static function action_reverse_transaction( $order ) {
			$order_id = $order->get_id();

			$plugin = new self();
			return $plugin->refund_transaction( $order_id, $order );
		}

		/**
		 * Verifies the transaction.
		 *
		 * @param object $order the order entity.
		 * @return void
		 */
		public static function action_verify_transaction( $order ) {
			$order_id = $order->get_id();
			$plugin   = new self();
			$trans_id = $plugin->get_order_trans_id( $order_id );

			if ( self::string_empty( $trans_id ) ) {
				/* translators: %s: method title, %s: order id. */
				$message = sprintf( __( '%1$s Transaction ID not found for order #%2$d.', 'wc-moldovaagroindbank' ), $plugin->method_title, $order_id );
				$message = $plugin->get_order_message( $message );
				$plugin->log( $message, WC_Log_Levels::ERROR );
				$order->add_order_note( $message );
			}

			$client_result = $plugin->get_transaction_result( $trans_id );
			if ( empty( $client_result ) ) {
				/* translators: %s: method title, %s: order id. */
				$message = sprintf( __( 'Could not retrieve transaction status from %1$s for order #%2$d.', 'wc-moldovaagroindbank' ), $plugin->method_title, $order_id );
				$message = $plugin->get_order_message( $message );
				$plugin->log( $message, WC_Log_Levels::ERROR );
				$order->add_order_note( $message );
			} else {

				$message = sprintf(
					/* translators: %s: method title, %s: order id, %s: query. */
					__( 'Transaction status from %1$s for order #%2$d: %3$s', 'wc-moldovaagroindbank' ),
					$plugin->method_title,
					$order_id,
					http_build_query( $client_result )
				);
				$message = $plugin->get_order_message( $message );
				$plugin->log( $message, WC_Log_Levels::INFO );
				$order->add_order_note( $message );
			}
		}

		/**
		 * Closes the Business Day.
		 *
		 * @return void
		 */
		public static function action_close_day() {
			$plugin = new self();
			$result = $plugin->close_day();

			// https://github.com/Prospress/action-scheduler/issues/215.
			$action_id = self::find_scheduled_action( ActionScheduler_Store::STATUS_RUNNING );
			$logger    = ActionScheduler::logger();
			$logger->log( $action_id, $result );
		}

		/**
		 * Registers the scheduled actions.
		 *
		 * @return void
		 */
		public static function register_scheduled_actions() {
			if ( false !== as_next_scheduled_action( self::MOD_CLOSEDAY_ACTION ) ) {
				/* translators: %s: action name. */
				$message = sprintf( __( 'Scheduled action %1$s is already registered.', 'wc-moldovaagroindbank' ), self::MOD_CLOSEDAY_ACTION );
				self::static_log( $message, WC_Log_Levels::WARNING );

				self::unregister_scheduled_actions();
			}

			$timezone_id = wc_timezone_string();
			$timestamp   = as_get_datetime_object( 'tomorrow - 1 minute', $timezone_id );
			$timestamp->setTimezone( new DateTimeZone( 'UTC' ) );

			$cron_schedule = $timestamp->format( 'i H * * *' ); // '59 23 * * *'.
			$action_id     = as_schedule_cron_action( null, $cron_schedule, self::MOD_CLOSEDAY_ACTION, array(), self::MOD_ID );

			$message = sprintf(
				/* translators: %s: action name, %s: timezone, %s: action id. */
				__( 'Registered scheduled action %1$s in timezone %2$s with ID %3$s.', 'wc-moldovaagroindbank' ),
				self::MOD_CLOSEDAY_ACTION,
				$timezone_id,
				$action_id
			);
			self::static_log( $message, WC_Log_Levels::INFO );
		}

		/**
		 * Unregisters the scheduled actions.
		 *
		 * @return void
		 */
		public static function unregister_scheduled_actions() {
			as_unschedule_all_actions( self::MOD_CLOSEDAY_ACTION );

			/* translators: %s: action name. */
			$message = sprintf( __( 'Unregistered scheduled action %1$s.', 'wc-moldovaagroindbank' ), self::MOD_CLOSEDAY_ACTION );
			self::static_log( $message, WC_Log_Levels::INFO );
		}

		/**
		 * Founds the scheduled actions by status.
		 *
		 * @param string $status the status of the action.
		 * @return mixed
		 */
		public static function find_scheduled_action( $status = null ) {
			$params    = $status ? array( 'status' => $status ) : null;
			$action_id = ActionScheduler::store()->find_action( self::MOD_CLOSEDAY_ACTION, $params );
			return $action_id;
		}
		// endregion.

		/**
		 * Adds the gateway to the methods array.
		 *
		 * @param array $methods the array of the methods.
		 * @return array
		 */
		public static function add_gateway( $methods ) {
			$methods[] = self::class;
			return $methods;
		}

		/**
		 * Verifies if the WordPress Commerce is activated.
		 *
		 * @return boolean
		 */
		public static function is_wc_active() {
			// https://docs.woocommerce.com/document/query-whether-woocommerce-is-activated/.
			return class_exists( 'WooCommerce' );
		}
	}

	// Check if WooCommerce is active.
	if ( ! WC_MoldovaAgroindbank::is_wc_active() ) {
		return;
	}

	// Add gateway to WooCommerce.
	add_filter( 'woocommerce_payment_gateways', array( WC_MoldovaAgroindbank::class, 'add_gateway' ) );

	// region Admin init.
	if ( is_admin() ) {
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( WC_MoldovaAgroindbank::class, 'plugin_links' ) );

		// Add WooCommerce order actions.
		add_filter( 'woocommerce_order_actions', array( WC_MoldovaAgroindbank::class, 'order_actions' ) );
		add_action( 'woocommerce_order_action_moldovaagroindbank_complete_transaction', array( WC_MoldovaAgroindbank::class, 'action_complete_transaction' ) );
		add_action( 'woocommerce_order_action_moldovaagroindbank_verify_transaction', array( WC_MoldovaAgroindbank::class, 'action_verify_transaction' ) );
	}
	// endregion.

	// region Scheduled actions.
	add_action( WC_MoldovaAgroindbank::MOD_CLOSEDAY_ACTION, array( WC_MoldovaAgroindbank::class, 'action_close_day' ) );
	// endregion.
}

// region Register activation hooks.
/**
 * Activations the MAIB Gateway Class.
 *
 * @return void
 */
function woocommerce_moldovaagroindbank_activation() {
	woocommerce_moldovaagroindbank_init();

	if ( ! class_exists( 'WC_MoldovaAgroindbank' ) ) {
		die( 'WooCommerce is required for this plugin to work' );
	}

	WC_MoldovaAgroindbank::register_scheduled_actions();
}

register_activation_hook( __FILE__, 'woocommerce_moldovaagroindbank_activation' );
register_deactivation_hook( __FILE__, array( WC_MoldovaAgroindbank::class, 'unregister_scheduled_actions' ) );
// endregion.
