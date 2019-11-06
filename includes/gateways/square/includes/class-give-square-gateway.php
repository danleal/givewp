<?php
/**
 * Give Square Gateway
 *
 * @package     Give
 * @sub-package Square Core
 * @copyright   Copyright (c) 2019, GiveWP
 * @license     https://opensource.org/licenses/gpl-license GNU Public License
 * @since       2.6.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Give_Square_Gateway.
 *
 * @since 2.6.0
 */
class Give_Square_Gateway {

	/**
	 * Access Token.
	 *
	 * @var string
	 *
	 * @since 2.6.0
	 */
	private static $access_token = '';

	/**
	 * Transactions Instance
	 *
	 * @var \SquareConnect\Api\TransactionsApi
	 *
	 * @since 2.6.0
	 */
	private $transaction_api;

	/**
	 * Charge Request Instance
	 *
	 * @var \SquareConnect\Model\ChargeRequest
	 *
	 * @since 2.6.0
	 */
	private $charge_request;

	/**
	 * Customer object.
	 *
	 * @since 2.6.0
	 * @var array
	 */
	private $customer = array();

	/**
	 * Give_Square_Gateway constructor.
	 *
	 * @since 2.6.0
	 */
	public function __construct() {

		// Set the Access Token prior to any API calls.
		\SquareConnect\Configuration::getDefaultConfiguration()->setAccessToken( give_square_get_access_token() );

		$this->transaction_api = new SquareConnect\Api\TransactionsApi();
		$this->charge_request  = new SquareConnect\Model\ChargeRequest();
		$this->customer        = new Give_Square_Customer();

		add_action( 'give_gateway_square', array( $this, 'process_donation' ) );
	}

	/**
	 * Get the Square Access Token.
	 *
	 * @since  2.6.0
	 * @access public
	 *
	 * @return string
	 */
	public static function get_access_token() {

		self::$access_token = give_square_decrypt_string( give_get_option( 'give_square_live_access_token' ) );

		// If Test Mode enabled & Square OAuth API not connect, use sandbox access token.
		if ( give_is_test_mode() && ! give_square_is_connected() ) {
			self::$access_token = give_square_decrypt_string( give_get_option( 'give_square_sandbox_access_token' ) );
		}

		return self::$access_token;
	}

	/**
	 * This function will be used to validate CC fields.
	 *
	 * @param array $post_data List of posted variables.
	 *
	 * @since  2.6.0
	 * @access public
	 *
	 * @return void
	 */
	public function validate_fields( $post_data ) {

		if (
			give_is_setting_enabled( give_get_option( 'give_square_collect_billing_details' ) ) &&
			isset( $post_data['card_info']['card_name'] ) &&
			empty( $post_data['card_info']['card_name'] )
		) {
			give_set_error( 'no_card_name', __( 'Please enter a name of your credit card account holder.', 'give' ) );
		}

	}

	/**
	 * Process Square checkout submission.
	 *
	 * @param array $posted_data List of posted data.
	 *
	 * @since  2.6.0
	 * @access public
	 *
	 * @return void
	 */
	public function process_donation( $posted_data ) {
		// Make sure we don't have any left over errors present.
		give_clear_errors();

		// Validate CC Fields.
		$this->validate_fields( $posted_data );

		// Any errors?
		$errors = give_get_errors();

		// No errors, proceed.
		if ( ! $errors ) {

			$form_id         = intval( $posted_data['post_data']['give-form-id'] );
			$price_id        = ! empty( $posted_data['post_data']['give-price-id'] ) ? $posted_data['post_data']['give-price-id'] : 0;
			$donation_amount = ! empty( $posted_data['price'] ) ? $posted_data['price'] : 0;
			$source_id       = ! empty( $posted_data['post_data']['card_nonce'] ) ? $posted_data['post_data']['card_nonce'] : false;

			// Setup the payment details.
			$donation_data = array(
				'price'           => $donation_amount,
				'give_form_title' => $posted_data['post_data']['give-form-title'],
				'give_form_id'    => $form_id,
				'give_price_id'   => $price_id,
				'date'            => $posted_data['date'],
				'user_email'      => $posted_data['user_email'],
				'purchase_key'    => $posted_data['purchase_key'],
				'currency'        => give_get_currency( $form_id ),
				'user_info'       => $posted_data['user_info'],
				'status'          => 'pending',
				'gateway'         => 'square',
			);

			// Record the pending donation.
			$donation_id = give_insert_payment( $donation_data );
			$amount      = give_square_prepare_donation_amount( $donation_amount );

			$donation_data['donation_id'] = $donation_id;

			// Get and Set Location ID to donation note and meta.
			$location_id = give_square_get_location_id();
			give_update_meta( $donation_id, '_give_square_donation_location_id', $location_id );
			give_insert_payment_note( $donation_id, "Location ID: {$location_id}" );

			// Get and Set Customer ID to donation note and meta.
			$customer_id = $this->customer->create( $donation_data );
			give_update_meta( $donation_id, '_give_square_donation_customer_id', $customer_id );
			give_insert_payment_note( $donation_id, "Customer ID: {$customer_id}" );

			// Get and Set Idempotency Key to donation note and meta.
			$idempotency_key = uniqid();
			give_update_meta( $donation_id, '_give_square_donation_idempotency_key', $idempotency_key );
			give_insert_payment_note( $donation_id, "Idempotency Key: {$idempotency_key}" );

			// Set default configuration.
			$api_client = give_square_set_default_configuration();

			// Create instance of Square Payments API.
			$payments_api = new \SquareConnect\Api\PaymentsApi( $api_client );

			// Create instance of Square Payment Request.
			$payment_request = new \SquareConnect\Model\CreatePaymentRequest();

			// Set required parameters to payment request.
			$payment_request->setSourceId( $source_id );
			$payment_request->setAmountMoney( $amount );
			$payment_request->setLocationId( $location_id );
			$payment_request->setIdempotencyKey( $idempotency_key );
			$payment_request->setCustomerId( $customer_id );
			$payment_request->setBuyerEmailAddress( $donation_data['user_email'] );

			// Apply Application fee when Give - Square Premium add-on is not active.
			if ( ! defined( 'GIVE_SQUARE_VERSION' ) ) {

				$currency        = give_get_currency( $form_id );
				$application_fee = give_square_get_application_fee( $donation_amount, $currency );
				$payment_request->setAppFeeMoney( $application_fee );
			}

			$payment_request->setNote( sprintf(
				/* translators: 1. Give Donation Form Title. */
				__( 'Donation: %1$s', 'give' ),
				mb_strlen( $donation_data['give_form_title'] ) > 47 ? mb_substr( $donation_data['give_form_title'], 0, 47 ) . '...' : $donation_data['give_form_title']
			) );


			try {

				// Process the donation through the Square API.
				$donation = $payments_api->createPayment( $payment_request );

				// Save Transaction ID to Donation.
				$transaction_id = $donation->getPayment()->getId();
				give_set_payment_transaction_id( $donation_id, $transaction_id );
				give_insert_payment_note( $donation_id, "Transaction ID: {$transaction_id}" );

				if ( ! empty( $transaction_id ) ) {

					// Set status to completed.
					give_update_payment_status( $donation_id );

					// All done. Send to success page.
					give_send_to_success_page();
				}
			} catch ( \SquareConnect\ApiException $e ) {

				$response = $e->getResponseBody();

				switch ( $response->errors[0]->code ) {

					case 'VERIFY_CVV_FAILURE':
						$error_message = __( 'Unable to verify CVV.', 'give' );
						break;

					case 'CARD_EXPIRED':
						$error_message = __( 'Credit Card is expired. ', 'give' );
						break;

					case 'CARD_TOKEN_USED':
						$error_message = __( 'Unable to process your payment.', 'give' );
						break;

					case 'INVALID_EXPIRATION':
						$error_message = __( 'Invalid expiration date.', 'give' );
						break;

					case 'CARD_DECLINED':
						$error_message = __( 'Credit card was declined.', 'give' );
						break;

					case 'CARD_DECLINED_CALL_ISSUER':
						$error_message = __( 'Credit card was declined. Please call your card issuer.', 'give' );
						break;

					case 'UNSUPPORTED_CARD_BRAND':
						$error_message = __( 'Credit card type is not supported. Please try again with another type.', 'give' );
						break;

					case 'MISSING_REQUIRED_PARAMETER':
						if ( 'card_nonce' === $response->errors[0]->field ) {
							$error_message = __( 'Card Nonce not found. Please try again or contact administrator.', 'give' );
							break;
						} else {
							$error_message = __( 'Credit card type is not supported. Please try again with another type.', 'give' );
							break;
						}

					case 'CARD_PROCESSING_NOT_ENABLED':
						$error_message = __( 'Card Processing not enabled on your Square Account. Please check and try again.', 'give' );
						break;

					case 'NOT_FOUND':
						$error_message = sprintf(
							/* translators: 1. Location ID. */
							__( 'This merchant does not have a location with the ID %1$s', 'give' ),
							$location_id
						);
						break;

					default:
						$error_message = __( 'An error occurred while processing the donation. Please try again.', 'give' );
						break;
				} // End switch().

				// Something went wrong outside of Square.
				give_record_gateway_error(
					__( 'Square Error', 'give' ),
					sprintf(
						/* translators: %s Exception error message. */
						__( 'The Square Gateway returned an error while processing a donation. Details: %s', 'give' ),
						$e->getMessage()
					)
				);

				// Mark donation as failed.
				give_update_payment_status( $donation_id, 'failed' );

				// Provide note why failed.
				give_insert_payment_note( $donation_id, sprintf( __( 'Donation failed. Reason: %s', 'give' ), $error_message ) );

				// Set Error to notify user.
				give_set_error( 'give_square_gateway_error', $error_message );

				// Send user back to checkout.
				give_send_back_to_checkout( '?payment-mode=square' );

			} // End try().
		} else {

			// Send user back to checkout.
			give_send_back_to_checkout( '?payment-mode=square' );
		} // End if().
	}

}

return new Give_Square_Gateway();
