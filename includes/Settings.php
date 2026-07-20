<?php
/**
 * Operational settings screen (Settings -> BITS Groups.io Sync).
 *
 * @package BITS\GroupsIOSync
 */

namespace BITS\GroupsIOSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the admin-configurable operational settings
 * from PRD section 3.2 (everything except the wp-config.php constants
 * in section 3.1, which this class never touches). Single primary
 * responsibility: this class does not itself act on these settings —
 * later phases' sync engine reads them via self::get().
 */
final class Settings {

	public const OPTION_NAME = 'bits_groupsio_sync_settings';

	private const BOUNDS = array(
		'grace_period_days'                    => array(
			'min' => 0,
			'max' => 30,
		),
		'log_retention_days'                   => array(
			'min' => 30,
			'max' => 3650,
		),
		'mass_action_threshold_count'          => array(
			'min' => 1,
			'max' => 10000,
		),
		'mass_action_threshold_window_minutes' => array(
			'min' => 1,
			'max' => 1440,
		),
		'magic_link_rate_limit_per_email_hour' => array(
			'min' => 1,
			'max' => 100,
		),
		'magic_link_rate_limit_per_ip_hour'    => array(
			'min' => 1,
			'max' => 1000,
		),
	);

	private const DESCRIPTIONS = array(
		'global_mandatory_groups'              => 'Subgroups every member is enrolled in regardless of membership level, one subgroup slug per line.',
		'grace_period_days'                    => 'Number of days a lapsed member keeps their Groups.io subgroup access after their PMPro membership ends, before automatic removal runs. Unit: days.',
		'log_retention_days'                   => 'Number of days sync audit log entries are kept before being purged. Unit: days.',
		'kill_switch'                          => 'When checked, halts all sync processing (adds, removals, and reconciliation) until unchecked.',
		'mass_action_threshold_count'          => 'Number of membership changes within the time window below that triggers a mass-action anomaly alert instead of processing normally. Unit: job count.',
		'mass_action_threshold_window_minutes' => 'Time window over which the job-count threshold above is measured. Unit: minutes.',
		'magic_link_rate_limit_per_email_hour' => 'Maximum magic-link requests allowed for a single email address within one hour before further requests are blocked.',
		'magic_link_rate_limit_per_ip_hour'    => 'Maximum magic-link requests allowed from a single IP address within one hour before further requests are blocked.',
		'dry_run_mode'                         => 'When checked, reconciliation runs compute and log intended changes without actually adding or removing anyone on Groups.io.',
	);

	private const DEFAULTS = array(
		'global_mandatory_groups'              => array(),
		'grace_period_days'                    => 3,
		'log_retention_days'                   => 90,
		'kill_switch'                          => false,
		'mass_action_threshold_count'          => 20,
		'mass_action_threshold_window_minutes' => 10,
		'magic_link_rate_limit_per_email_hour' => 3,
		'magic_link_rate_limit_per_ip_hour'    => 10,
		'dry_run_mode'                         => true,
	);

	/**
	 * Registers the admin_menu and admin_init hooks. Called once from
	 * Plugin's constructor.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu_page' ) );
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
	}

	/**
	 * Adds the Settings -> BITS Groups.io Sync submenu page.
	 *
	 * @return void
	 */
	public static function add_menu_page(): void {
		add_options_page(
			__( 'BITS Groups.io Sync', 'bits-groupsio-sync' ),
			__( 'BITS Groups.io Sync', 'bits-groupsio-sync' ),
			'manage_options',
			'bits-groupsio-sync',
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Registers the setting, section, and fields with the Settings API.
	 *
	 * @return void
	 */
	public static function register_setting(): void {
		register_setting(
			'bits_groupsio_sync',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::DEFAULTS,
			)
		);

		add_settings_section(
			'bits_groupsio_sync_main',
			__( 'Groups.io Sync Settings', 'bits-groupsio-sync' ),
			'__return_false',
			'bits-groupsio-sync'
		);

		$fields = array(
			'global_mandatory_groups'              => __( 'Global mandatory groups (one subgroup slug per line)', 'bits-groupsio-sync' ),
			'grace_period_days'                    => __( 'Grace period (days)', 'bits-groupsio-sync' ),
			'log_retention_days'                   => __( 'Log retention (days)', 'bits-groupsio-sync' ),
			'kill_switch'                          => __( 'Kill switch (halt all sync processing)', 'bits-groupsio-sync' ),
			'mass_action_threshold_count'          => __( 'Mass-action anomaly threshold: job count', 'bits-groupsio-sync' ),
			'mass_action_threshold_window_minutes' => __( 'Mass-action anomaly threshold: window (minutes)', 'bits-groupsio-sync' ),
			'magic_link_rate_limit_per_email_hour' => __( 'Magic link rate limit: per email address (per hour)', 'bits-groupsio-sync' ),
			'magic_link_rate_limit_per_ip_hour'    => __( 'Magic link rate limit: per IP address (per hour)', 'bits-groupsio-sync' ),
			'dry_run_mode'                         => __( 'Reconciliation dry-run mode', 'bits-groupsio-sync' ),
		);

		$first = true;

		foreach ( $fields as $key => $label ) {
			$field_id = self::OPTION_NAME . '_' . $key;

			add_settings_field(
				$key,
				sprintf( '<label for="%1$s">%2$s</label>', esc_attr( $field_id ), esc_html( $label ) ),
				array( self::class, 'render_field' ),
				'bits-groupsio-sync',
				'bits_groupsio_sync_main',
				array(
					'key'   => $key,
					'first' => $first,
				)
			);

			$first = false;
		}
	}

	/**
	 * Renders a single settings field. Callback for add_settings_field().
	 *
	 * @param array<string, mixed> $args Field arguments, contains 'key' and 'first'.
	 * @return void
	 */
	public static function render_field( array $args ): void {
		$key         = $args['key'];
		$value       = self::get( $key );
		$field_id    = self::OPTION_NAME . '_' . $key;
		$name        = self::OPTION_NAME . '[' . $key . ']';
		$desc_id     = $field_id . '_description';
		$autofocus   = ! empty( $args['first'] ) ? ' autofocus' : '';
		$description = self::DESCRIPTIONS[ $key ] ?? '';

		if ( 'global_mandatory_groups' === $key ) {
			printf(
				'<textarea id="%1$s" name="%2$s" rows="5" cols="40" aria-describedby="%3$s"%4$s>%5$s</textarea>',
				esc_attr( $field_id ),
				esc_attr( $name ),
				esc_attr( $desc_id ),
				$autofocus,
				esc_textarea( implode( "\n", (array) $value ) )
			);
			self::render_description( $desc_id, $description );
			return;
		}

		if ( in_array( $key, array( 'kill_switch', 'dry_run_mode' ), true ) ) {
			printf(
				'<input type="checkbox" id="%1$s" name="%2$s" value="1" aria-describedby="%3$s"%4$s %5$s />',
				esc_attr( $field_id ),
				esc_attr( $name ),
				esc_attr( $desc_id ),
				$autofocus,
				checked( (bool) $value, true, false )
			);
			self::render_description( $desc_id, $description );
			return;
		}

		printf(
			'<input type="number" id="%1$s" name="%2$s" value="%3$s" min="%4$s" max="%5$s" aria-describedby="%6$s"%7$s />',
			esc_attr( $field_id ),
			esc_attr( $name ),
			esc_attr( (string) $value ),
			esc_attr( (string) ( self::BOUNDS[ $key ]['min'] ?? 0 ) ),
			esc_attr( (string) ( self::BOUNDS[ $key ]['max'] ?? PHP_INT_MAX ) ),
			esc_attr( $desc_id ),
			$autofocus
		);
		self::render_description( $desc_id, $description );
	}

	/**
	 * Renders a field's helper description, visible and exposed to
	 * assistive tech via the aria-describedby the control already points at.
	 *
	 * @param string $desc_id     Element id matching the control's aria-describedby.
	 * @param string $description Human-readable helper text.
	 * @return void
	 */
	private static function render_description( string $desc_id, string $description ): void {
		if ( '' === $description ) {
			return;
		}

		printf(
			'<p class="description" id="%1$s">%2$s</p>',
			esc_attr( $desc_id ),
			esc_html( $description )
		);
	}

	/**
	 * Renders the full settings page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'BITS Groups.io Sync', 'bits-groupsio-sync' ) . '</h1><form action="options.php" method="post">';
		settings_fields( 'bits_groupsio_sync' );
		do_settings_sections( 'bits-groupsio-sync' );
		submit_button( __( 'Save Changes', 'bits-groupsio-sync' ), 'primary', 'submit', false );
		echo ' ';
		printf(
			'<input type="reset" class="button" value="%s" />',
			esc_attr__( 'Reset', 'bits-groupsio-sync' )
		);
		echo ' ';
		printf(
			'<button type="button" class="button" disabled aria-describedby="bits_groupsio_sync_dry_run_stub_description">%s</button>' .
			'<p class="description" id="bits_groupsio_sync_dry_run_stub_description">%s</p>',
			esc_html__( 'Run Dry-Run Now', 'bits-groupsio-sync' ),
			esc_html__( 'Not yet available: the reconciliation engine has not been built yet (Phase 5).', 'bits-groupsio-sync' )
		);
		echo '</form></div>';
	}

	/**
	 * Sanitizes and clamps submitted settings. Registered as this
	 * setting's sanitize_callback, which WordPress may call with
	 * non-array input in edge cases, hence the runtime is_array() check
	 * despite the array-shaped happy path.
	 *
	 * @param mixed $input Raw submitted value, expected to be an array.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input     = is_array( $input ) ? $input : array();
		$sanitized = self::DEFAULTS;

		if ( isset( $input['global_mandatory_groups'] ) ) {
			$sanitized['global_mandatory_groups'] = self::sanitize_lines( $input['global_mandatory_groups'] );
		}

		foreach ( array_keys( self::BOUNDS ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$sanitized[ $key ] = self::clamp_int(
					(int) $input[ $key ],
					self::BOUNDS[ $key ]['min'],
					self::BOUNDS[ $key ]['max']
				);
			}
		}

		foreach ( array( 'kill_switch', 'dry_run_mode' ) as $key ) {
			$sanitized[ $key ] = ! empty( $input[ $key ] );
		}

		return $sanitized;
	}

	/**
	 * Clamps an integer to an inclusive [min, max] range.
	 *
	 * @param int $value Value to clamp.
	 * @param int $min   Inclusive minimum.
	 * @param int $max   Inclusive maximum.
	 * @return int
	 */
	public static function clamp_int( int $value, int $min, int $max ): int {
		return max( $min, min( $max, $value ) );
	}

	/**
	 * Splits a textarea's raw value into a trimmed, non-empty line array.
	 * Guards against array input (rather than casting it to the literal
	 * string "Array" and silently corrupting the stored option) by
	 * treating each array element as its own line.
	 *
	 * @param mixed $raw Raw textarea value.
	 * @return array<int, string>
	 */
	public static function sanitize_lines( $raw ): array {
		$raw = is_array( $raw ) ? implode( "\n", array_map( 'strval', $raw ) ) : (string) $raw;

		$lines = preg_split( '/[\r\n]+/', $raw );
		$lines = array_map( 'trim', $lines );
		$lines = array_map( 'sanitize_text_field', $lines );

		return array_values( array_filter( $lines ) );
	}

	/**
	 * Reads a single setting's current value, falling back to its default.
	 *
	 * @param string $key One of the keys in self::DEFAULTS.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$settings = wp_parse_args( get_option( self::OPTION_NAME, array() ), self::DEFAULTS );

		return $settings[ $key ] ?? ( self::DEFAULTS[ $key ] ?? null );
	}
}
