<?php
/**
 * Session Database Handler
 *
 * @package     Give
 * @subpackage  Classes/Give_Session
 * @copyright   Copyright (c) 2018, WordImpress
 * @license     https://opensource.org/licenses/gpl-license GNU Public License
 * @since       2.2.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Give_DB_Sessions extends Give_DB {
	/**
	 * Cache group name
	 *
	 * @since  2.2.0
	 * @access private
	 *
	 * @var string
	 */
	private $cache_group = '';


	/**
	 * Class Constructor
	 *
	 * @since  2.2.0
	 * @access public
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name  = "{$wpdb->prefix}give_sessions";
		$this->primary_key = 'session_key';
		$this->version     = '1.0';

		$incrementer_value = wp_cache_get( 'give-cache-incrementer-sessions' );
		$incrementer_value = ! empty( $incrementer_value ) ? $incrementer_value : microtime( true );
		$this->cache_group = "{$this->cache_group}_{$incrementer_value}";


		$this->register_table();

		parent::__construct();
	}


	/**
	 * Whitelist of columns
	 *
	 * @since  2.2.0
	 * @access public
	 *
	 * @return array  Columns and formats.
	 */
	public function get_columns() {
		return array(
			'session_id'     => '%d',
			'session_key'    => '%s',
			'session_value'  => '%s',
			'session_expiry' => '%d',
		);
	}

	/**
	 * Create Meta Tables.
	 *
	 * @since  2.2.0
	 * @access public
	 */
	public function create_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$this->table_name} (
  				session_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  				session_key char(32) NOT NULL,
  				session_value longtext NOT NULL,
  				session_expiry BIGINT UNSIGNED NOT NULL,
  				PRIMARY KEY  (session_key),
  				UNIQUE KEY session_id (session_id)
			) {$charset_collate};";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );

		update_option( $this->table_name . '_db_version', $this->version );
	}


	/**
	 * Returns the session.
	 *
	 * @todo: add cache logic
	 *
	 * @param string $donor_id Donor ID.
	 * @param mixed  $default  Default session value.
	 *
	 * @return string|array
	 */
	public function get_session( $donor_id, $default = false ) {
		global $wpdb;

		if ( defined( 'WP_SETUP_CONFIG' ) ) {
			return false;
		}

		if ( $value = wp_cache_get( $donor_id, $this->cache_group ) ) {
			$value = $wpdb->get_var( $wpdb->prepare(
				"
					SELECT session_value
					FROM $this->table_name
					WHERE session_key = %s
					",
				$donor_id
				)
			);

			if ( is_null( $value ) ) {
				$value = $default;
			}

			wp_cache_add( $donor_id, $value, $this->cache_group );
		}

		return maybe_unserialize( $value );
	}

	/**
	 * Update the session expiry timestamp.
	 *
	 * @param string $donor_id  Donor ID.
	 * @param int    $timestamp Timestamp to expire the cookie.
	 */
	public function update_session_timestamp( $donor_id, $timestamp ) {
		global $wpdb;

		wp_cache_delete( $donor_id, $this->cache_group );

		// @codingStandardsIgnoreStart.
		$wpdb->update(
			$this->table_name,
			array(
				'session_expiry' => $timestamp,
			),
			array(
				'session_key' => $donor_id,
			),
			array(
				'%d'
			)
		);
		// @codingStandardsIgnoreEnd.
	}

	/**
	 * Delete the session from the cache and database.
	 *
	 * @since  2.2.0
	 * @access public
	 *
	 * @param int $donor_id Customer ID.
	 */
	public function delete_session( $donor_id ) {
		global $wpdb;

		wp_cache_delete( $donor_id, $this->cache_group );

		$wpdb->delete(
			$this->table_name,
			array(
				'session_key' => $donor_id,
			)
		);
	}


	/**
	 * Cleanup session data from the database and clear caches.
	 * Note: for internal logic only.
	 *
	 *
	 * @since  2.2.0
	 * @access public
	 */
	public function delete_expired_sessions() {
		global $wpdb;

		wp_cache_set( 'give-cache-incrementer-sessions', microtime( true ) );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $this->table_name WHERE session_expiry < %d",
				time()
			)
		);
	}

	/**
	 * Replace table data
	 * Note: only for internal use
	 *
	 * @since  2.2.0
	 * @access public
	 *
	 * @param $table_name
	 * @param $data
	 * @param $format
	 */
	public function __replace( $table_name, $data, $format = null ) {
		global $wpdb;

		wp_cache_delete( $data['session_key'], $this->cache_group );

		$wpdb->replace(
			$table_name,
			$data,
			$format
		);
	}
}
