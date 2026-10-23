<?php
/**
 * Plugin Name:       Three Seven Gravity Forms Source Capture
 * Plugin URI:        https://github.com/37designs/wordpress-marketing-attribution
 * Description:       Saves where each inquiry came from on its Gravity Forms entry: the first visit, every visit in order, the latest Google Ads click ID and the first landing page. Adds the same source data to email and phone link clicks.
 * Version:           2.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Three Seven
 * Author URI:        https://threesevenmarketing.com
 * License:           GPL-2.0-or-later
 * Text Domain:       gf-source-capture
 */

defined( 'ABSPATH' ) || exit;

final class TSSC_Source_Capture {

	const VERSION = '2.1.0';
	const OPTION  = 'tssc_settings';
	const PAGE    = 'tssc-source-capture';

	/** Hidden fields, keyed by the dynamic population parameter name. */
	const FIELDS = array(
		'tssc_first_touch'  => 'First touch',
		'tssc_touch_path'   => 'Touch path',
		'tssc_gclid'        => 'Google click ID',
		'tssc_landing_page' => 'Landing page',
	);

	const HEARD_NAME        = 'tssc_heard_about';
	const HEARD_DETAIL_NAME = 'tssc_heard_about_detail';
	const DEFAULT_CHOICES   = array(
		'Google search',
		'Google ad',
		'ChatGPT or another AI assistant',
		'A colleague or referral',
		'A conference or event',
		'A publication or article',
		'LinkedIn',
		"I'm an existing customer",
		'Other',
	);

	/** Sign-in and payment hosts that redirect back to the site and must not count as a source. */
	const DEFAULT_IGNORE = array(
		'accounts.google.com',
		'login.microsoftonline.com',
		'checkout.stripe.com',
		'paypal.com',
	);

	/** Default labels for where an email or phone link sits, as "CSS selector => label", first match wins. */
	const DEFAULT_LOCATIONS = array(
		array( '.sticky-header', 'sticky' ),
		array( 'header, .masthead', 'header' ),
		array( 'footer', 'footer' ),
		array( '.mfp-content, [role=dialog], #contact-form', 'popup' ),
	);

	/** Keys a visit may carry, with the longest value kept for each. */
	const VISIT_KEYS = array(
		't'  => 20,
		's'  => 80,
		'm'  => 40,
		'lp' => 150,
		'c'  => 100,
		'k'  => 100,
		'g'  => 200,
		'gb' => 200,
		'wb' => 200,
	);

	/** Parameter names used by the plugin's first version (msb-source-capture), renamed on migration. */
	const LEGACY_NAMES = array(
		'msb_first_touch'        => 'tssc_first_touch',
		'msb_touch_path'         => 'tssc_touch_path',
		'msb_gclid'              => 'tssc_gclid',
		'msb_landing_page'       => 'tssc_landing_page',
		'msb_heard_about'        => 'tssc_heard_about',
		'msb_heard_about_detail' => 'tssc_heard_about_detail',
	);

	public static function init() {
		add_action( 'gform_pre_submission', array( __CLASS__, 'fill_fields' ) );
		add_filter( 'gform_field_css_class', array( __CLASS__, 'field_class' ), 10, 2 );
		add_action( 'gform_enqueue_scripts', array( __CLASS__, 'enqueue_fill_script' ) );
		add_filter( 'gform_merge_tag_filter', array( __CLASS__, 'format_all_fields' ), 10, 6 );
		add_action( 'wp_head', array( __CLASS__, 'print_script' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_contact_script' ), 100 );

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ) );
			add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
			add_action( 'admin_post_tssc_save', array( __CLASS__, 'handle_save' ) );
			add_action( 'admin_post_tssc_add_fields', array( __CLASS__, 'handle_add_fields' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'action_links' ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                           */
	/* ------------------------------------------------------------------ */

	public static function settings() {
		$defaults = array(
			'delivery' => 'gtm',
			'domain'   => self::default_domain(),
			'ignore'   => array(),
			'choices'  => self::DEFAULT_CHOICES,
			'contact'  => array(
				'track_clicks'   => true,
				'body_lines'     => false,
				'main_email'     => '',
				'ad_email'       => '',
				'ad_window_days' => 30,
				'locations'      => self::DEFAULT_LOCATIONS,
			),
		);
		$saved = get_option( self::OPTION, array() );
		if ( is_array( $saved ) && isset( $saved['contact'] ) && is_array( $saved['contact'] ) ) {
			$saved['contact'] = wp_parse_args( $saved['contact'], $defaults['contact'] );
		}
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	private static function default_domain() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return preg_replace( '/^www\./i', '', strtolower( $host ) );
	}

	private static function clean_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		$host = preg_replace( '#^[a-z]+://#', '', $host );
		$host = preg_replace( '#[/:].*$#', '', $host );
		$host = trim( $host, '.' );
		return preg_match( '/^[a-z0-9.-]+$/', $host ) ? $host : '';
	}

	/* ------------------------------------------------------------------ */
	/* Migration from msb-source-capture 1.x                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Copies the 1.x settings and renames the msb_ field parameters on every form, once.
	 * Field IDs don't change, so existing entries keep their values.
	 */
	public static function maybe_migrate() {
		if ( get_option( 'tssc_migrated' ) || ! class_exists( 'GFAPI' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$old = get_option( 'msb_sc_settings' );
		if ( is_array( $old ) && false === get_option( self::OPTION ) ) {
			update_option( self::OPTION, $old );
		}

		$renamed = 0;
		foreach ( (array) GFAPI::get_forms( null ) as $form ) {
			$changed = false;
			foreach ( $form['fields'] as $field ) {
				$name = isset( $field->inputName ) ? (string) $field->inputName : '';
				if ( isset( self::LEGACY_NAMES[ $name ] ) ) {
					$field->inputName = self::LEGACY_NAMES[ $name ];
					$changed          = true;
					++$renamed;
				}
			}
			if ( $changed && is_wp_error( GFAPI::update_form( $form ) ) ) {
				return; // Try again on the next admin page load.
			}
		}

		update_option( 'tssc_migrated', self::VERSION );
		if ( $renamed || is_array( $old ) ) {
			set_transient( 'tssc_migration_notice', $renamed, DAY_IN_SECONDS );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Part 1: the cookie script                                          */
	/* ------------------------------------------------------------------ */

	/** The tracker JavaScript with this site's domain and ignore list filled in. */
	public static function script_body() {
		$settings = self::settings();
		$js       = (string) file_get_contents( __DIR__ . '/assets/tracker.js' );
		$config   = wp_json_encode(
			array(
				'domain' => $settings['domain'],
				'ignore' => array_values( array_unique( array_merge( self::DEFAULT_IGNORE, $settings['ignore'] ) ) ),
			),
			JSON_UNESCAPED_SLASHES
		);

		return trim(
			preg_replace_callback(
				'#/\*TSSC_CONFIG\*/.*?/\*END_TSSC_CONFIG\*/#s',
				function () use ( $config ) {
					return $config;
				},
				$js,
				1
			)
		);
	}

	/** Prints the script site-wide when Settings > Source Capture is set to load it from the plugin. */
	public static function print_script() {
		$settings = self::settings();
		if ( 'plugin' !== $settings['delivery'] ) {
			return;
		}

		/**
		 * Filters the opening script tag, so a consent tool can block it until marketing cookies
		 * are allowed (for example type="text/plain" data-category="marketing").
		 */
		$open = apply_filters( 'tssc_script_tag', '<script id="tssc-source-capture">' );

		echo $open . "\n" . self::script_body() . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/* ------------------------------------------------------------------ */
	/* Part 3: email and phone links                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Loads the contact link script on every page. When it rewrites email links, the
	 * propagate-martech email tracking is switched off so two scripts don't edit the same links.
	 */
	public static function enqueue_contact_script() {
		$c       = self::settings()['contact'];
		$rewrite = $c['body_lines'] || ( $c['ad_email'] && $c['main_email'] );
		if ( ! $c['track_clicks'] && ! $rewrite ) {
			return;
		}

		$file = __DIR__ . '/assets/contact.js';
		wp_enqueue_script( 'tssc-contact', plugins_url( 'assets/contact.js', __FILE__ ), array(), (string) filemtime( $file ), true );
		wp_add_inline_script(
			'tssc-contact',
			'window.tsscContact = ' . wp_json_encode(
				array(
					'trackClicks'  => (bool) $c['track_clicks'],
					'bodyLines'    => (bool) $c['body_lines'],
					'mainEmail'    => $c['main_email'],
					'adEmail'      => $c['main_email'] ? $c['ad_email'] : '',
					'adWindowDays' => (int) $c['ad_window_days'],
					'locations'    => $c['locations'],
				),
				JSON_UNESCAPED_SLASHES
			) . ';',
			'before'
		);

		/** Filters whether to switch off propagate-martech's email link rewriting. */
		if ( $rewrite && apply_filters( 'tssc_replace_propagate_email_tracking', true ) ) {
			wp_dequeue_script( 'prop-email-tracking' );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Part 2: fill the hidden fields                                     */
	/* ------------------------------------------------------------------ */

	/** The CSS class the browser-side fill script looks for, e.g. tssc_gclid -> tssc-gclid. */
	private static function css_class( $name ) {
		return 'tssc-' . str_replace( '_', '-', substr( $name, 5 ) );
	}

	/** Marks each source field with a class the browser-side fill script can find. */
	public static function field_class( $classes, $field ) {
		$name = isset( $field->inputName ) ? (string) $field->inputName : '';
		return isset( self::FIELDS[ $name ] ) ? $classes . ' ' . self::css_class( $name ) : $classes;
	}

	/** Loads the fill script on pages showing a form with source fields. */
	public static function enqueue_fill_script( $form ) {
		if ( is_array( $form ) && array_intersect( array_keys( self::FIELDS ), self::input_names( $form ) ) ) {
			wp_enqueue_script( 'tssc-fill', plugins_url( 'assets/fill.js', __FILE__ ), array(), self::VERSION, true );
		}
	}

	/**
	 * Sets the final field values on the submission request. The browser has already copied the
	 * cookies into the fields; this re-reads the cookies when they reach the server, falls back to
	 * the posted copies when a cache layer strips them, and cleans whichever it uses.
	 */
	public static function fill_fields( $form ) {
		if ( empty( $form['fields'] ) ) {
			return;
		}

		$posted = array();
		foreach ( $form['fields'] as $field ) {
			$name = isset( $field->inputName ) ? (string) $field->inputName : '';
			if ( isset( self::FIELDS[ $name ] ) && isset( $_POST[ 'input_' . $field->id ] ) && is_string( $_POST[ 'input_' . $field->id ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$posted[ $name ] = wp_unslash( $_POST[ 'input_' . $field->id ] ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			}
		}

		$values = self::source_values( $posted );

		foreach ( $form['fields'] as $field ) {
			$name = isset( $field->inputName ) ? (string) $field->inputName : '';
			if ( '' !== $name && isset( $values[ $name ] ) ) {
				$_POST[ 'input_' . $field->id ] = $values[ $name ];
			}
		}
	}

	/** Builds the four field values from the cookies, or from the posted copies when the cookies are missing. */
	public static function source_values( array $posted = array() ) {
		$ft = self::read_cookie_json( 'ft' );
		if ( ! is_array( $ft ) && isset( $posted['tssc_first_touch'] ) ) {
			$ft = json_decode( $posted['tssc_first_touch'], true );
		}
		$ft = is_array( $ft ) ? self::clean_visit( $ft ) : null;

		$path = array();
		$raw  = self::read_cookie_json( 'path' );
		if ( ! is_array( $raw ) && isset( $posted['tssc_touch_path'] ) ) {
			$raw = json_decode( $posted['tssc_touch_path'], true );
		}
		if ( is_array( $raw ) ) {
			foreach ( $raw as $visit ) {
				$visit = is_array( $visit ) ? self::clean_visit( $visit ) : null;
				if ( $visit ) {
					$path[] = $visit;
				}
			}
		}

		$gclid = '';
		foreach ( array_reverse( $path ) as $visit ) {
			if ( ! empty( $visit['g'] ) ) {
				$gclid = $visit['g'];
				break;
			}
		}

		$landing = '';
		if ( $ft && isset( $ft['lp'] ) ) {
			$landing = $ft['lp'];
		} elseif ( isset( $path[0]['lp'] ) ) {
			$landing = $path[0]['lp'];
		}

		return array(
			'tssc_first_touch'  => $ft ? wp_json_encode( $ft, JSON_UNESCAPED_SLASHES ) : '',
			'tssc_touch_path'   => $path ? wp_json_encode( $path, JSON_UNESCAPED_SLASHES ) : '',
			'tssc_gclid'        => $gclid,
			'tssc_landing_page' => $landing,
		);
	}

	/** Reads tssc_{name}, or the 1.x msb_{name} cookie if the visitor hasn't been migrated yet. */
	private static function read_cookie_json( $name ) {
		foreach ( array( 'tssc_' . $name, 'msb_' . $name ) as $cookie ) {
			if ( isset( $_COOKIE[ $cookie ] ) && is_string( $_COOKIE[ $cookie ] ) ) {
				return json_decode( wp_unslash( $_COOKIE[ $cookie ] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			}
		}
		return null;
	}

	/** Keeps only known keys, as plain trimmed strings. Returns null for a visit with no source. */
	private static function clean_visit( array $visit ) {
		$clean = array();
		foreach ( self::VISIT_KEYS as $key => $max ) {
			if ( isset( $visit[ $key ] ) && is_scalar( $visit[ $key ] ) ) {
				$value = trim( wp_strip_all_tags( (string) $visit[ $key ] ) );
				if ( '' !== $value ) {
					$clean[ $key ] = substr( $value, 0, $max );
				}
			}
		}
		return isset( $clean['s'] ) ? $clean : null;
	}

	/* ------------------------------------------------------------------ */
	/* Readable source in notification emails                             */
	/* ------------------------------------------------------------------ */

	/** Shows First touch and Touch path as readable lines in {all_fields} instead of raw JSON. */
	public static function format_all_fields( $value, $merge_tag, $modifier = '', $field = null, $raw_value = '', $format = 'html' ) {
		if ( 'all_fields' !== $merge_tag || ! is_object( $field ) ) {
			return $value;
		}

		$name = isset( $field->inputName ) ? $field->inputName : '';
		if ( 'tssc_first_touch' !== $name && 'tssc_touch_path' !== $name ) {
			return $value;
		}

		$raw    = ( is_string( $raw_value ) && '' !== $raw_value ) ? $raw_value : wp_specialchars_decode( (string) $value, ENT_QUOTES );
		$visits = json_decode( $raw, true );
		if ( ! is_array( $visits ) ) {
			return $value;
		}
		if ( 'tssc_first_touch' === $name ) {
			$visits = array( $visits );
		}

		$lines = array();
		foreach ( $visits as $i => $visit ) {
			if ( is_array( $visit ) ) {
				$line    = self::describe_visit( $visit );
				$lines[] = ( count( $visits ) > 1 ? ( $i + 1 ) . '. ' : '' ) . ( 'html' === $format ? esc_html( $line ) : $line );
			}
		}

		return $lines ? implode( 'html' === $format ? '<br>' : "\n", $lines ) : $value;
	}

	private static function describe_visit( array $v ) {
		$parts = array();
		if ( ! empty( $v['t'] ) ) {
			$parts[] = str_replace( 'T', ' ', $v['t'] ) . ' UTC';
		}
		$parts[] = ( isset( $v['s'] ) ? $v['s'] : '?' ) . ' / ' . ( isset( $v['m'] ) && '' !== $v['m'] ? $v['m'] : '(not set)' );
		if ( ! empty( $v['c'] ) ) {
			$parts[] = 'campaign: ' . $v['c'];
		}
		if ( ! empty( $v['k'] ) ) {
			$parts[] = 'term: ' . $v['k'];
		}
		if ( ! empty( $v['g'] ) || ! empty( $v['gb'] ) || ! empty( $v['wb'] ) ) {
			$parts[] = 'Google Ads click';
		}
		if ( ! empty( $v['lp'] ) ) {
			$parts[] = 'landed on ' . $v['lp'];
		}
		return implode( ' · ', $parts );
	}

	/* ------------------------------------------------------------------ */
	/* Admin: Settings > Source Capture                                   */
	/* ------------------------------------------------------------------ */

	public static function admin_menu() {
		add_options_page( 'Gravity Forms Source Capture', 'Source Capture', 'manage_options', self::PAGE, array( __CLASS__, 'render_page' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">Settings</a>' );
		return $links;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = self::settings();
		$snippet  = "<script>\n" . self::script_body() . "\n</script>";
		$notice   = isset( $_GET['tssc_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['tssc_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$migrated = get_transient( 'tssc_migration_notice' );
		?>
		<div class="wrap">
			<h1>Gravity Forms Source Capture</h1>
			<p>By <a href="https://threesevenmarketing.com" target="_blank" rel="noopener">Three Seven</a>. Saves where each inquiry came from on its Gravity Forms entry.</p>

			<?php if ( false !== $migrated ) : ?>
				<div class="notice notice-warning">
					<p><strong>Moved from Source Capture 1.x.</strong> Settings were copied and <?php echo esc_html( (int) $migrated ); ?> form fields were renamed from <code>msb_</code> to <code>tssc_</code>. Two steps are left: replace the snippet in your GTM tag with the one below and publish the container, then deactivate and delete the old 1.x Source Capture plugin.</p>
					<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tssc_save&dismiss_migration=1' ), 'tssc_save' ) ); ?>">Done, hide this</a></p>
				</div>
			<?php endif; ?>

			<?php if ( $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<h2>1. Cookie script</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tssc_save">
				<?php wp_nonce_field( 'tssc_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Load the script from</th>
						<td>
							<label><input type="radio" name="delivery" value="gtm" <?php checked( $settings['delivery'], 'gtm' ); ?>> Google Tag Manager (recommended). Paste the snippet below into a Custom HTML tag that fires on page view once marketing cookies are allowed.</label><br>
							<label><input type="radio" name="delivery" value="plugin" <?php checked( $settings['delivery'], 'plugin' ); ?>> This plugin, on every page. The consent banner must block it until marketing cookies are allowed (see the <code>tssc_script_tag</code> filter).</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tssc-domain">Cookie domain</label></th>
						<td>
							<input id="tssc-domain" class="regular-text" type="text" name="domain" value="<?php echo esc_attr( $settings['domain'] ); ?>">
							<p class="description">The live site's domain without "www", for example example.com. Visits from this domain and its subdomains count as clicks within the site.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tssc-ignore">Extra domains to ignore</label></th>
						<td>
							<textarea id="tssc-ignore" class="large-text code" rows="4" name="ignore"><?php echo esc_textarea( implode( "\n", $settings['ignore'] ) ); ?></textarea>
							<p class="description">One per line. Add any payment gateway or sign-in provider the site redirects through. Already ignored: <?php echo esc_html( implode( ', ', self::DEFAULT_IGNORE ) ); ?>.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tssc-choices">"How did you hear about us?" answers</label></th>
						<td>
							<textarea id="tssc-choices" class="regular-text" rows="9" name="choices"><?php echo esc_textarea( implode( "\n", $settings['choices'] ) ); ?></textarea>
							<p class="description">One per line. Used when the question is added to a form below. Forms that already have the question keep their own answers; edit those in the form editor.</p>
						</td>
					</tr>
				</table>

				<h2>Email and phone links</h2>
				<p>For sites where people email or call instead of filling in a form. See <code>docs/SPEC-contact-link-tracking.md</code> for the GTM and GA4 setup.</p>
				<?php $c = $settings['contact']; ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Click data</th>
						<td>
							<label><input type="checkbox" name="contact_track_clicks" value="1" <?php checked( $c['track_clicks'] ); ?>> Send a <code>tssc_contact_click</code> event to the data layer on every email and phone link click, with the visitor's source data.</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Email body</th>
						<td>
							<label><input type="checkbox" name="contact_body_lines" value="1" <?php checked( $c['body_lines'] ); ?>> Add the visitor's source and landing page to the body of every email link.</label>
							<p class="description">Turning this on switches off the propagate-martech email tracking, which does the same job.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tssc-main-email">Main contact address</label></th>
						<td>
							<input id="tssc-main-email" class="regular-text" type="email" name="contact_main_email" value="<?php echo esc_attr( $c['main_email'] ); ?>" placeholder="info@example.com">
							<p class="description">The address the site's email links use. Needed for the ad address below.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tssc-ad-email">Address for visitors from ads</label></th>
						<td>
							<input id="tssc-ad-email" class="regular-text" type="email" name="contact_ad_email" value="<?php echo esc_attr( $c['ad_email'] ); ?>" placeholder="Leave empty to turn off">
							<p class="description">Shown in place of the main address to visitors who clicked an ad. Use a plus address (info+ads@example.com) only if the mail server accepts it, or an alias that forwards to the main inbox. Send a test email from an outside account before turning this on.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tssc-ad-window">Ad click window</label></th>
						<td>
							<input id="tssc-ad-window" class="small-text" type="number" min="1" max="90" name="contact_ad_window_days" value="<?php echo esc_attr( (int) $c['ad_window_days'] ); ?>"> days
							<p class="description">A visitor counts as an ad visitor when any visit in this many days came from an ad. 30 matches the Google Ads default click-through conversion window.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tssc-locations">Link locations</label></th>
						<td>
							<textarea id="tssc-locations" class="large-text code" rows="5" name="contact_locations"><?php echo esc_textarea( implode( "\n", array_map( function ( $r ) { return $r[0] . ' => ' . $r[1]; }, $c['locations'] ) ) ); ?></textarea>
							<p class="description">One per line as <code>CSS selector =&gt; label</code>. The first match wins; anything else is "content". A <code>data-tssc-location</code> attribute on a link or its parent overrides this list.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save settings' ); ?>
			</form>

			<h3>Snippet for Google Tag Manager</h3>
			<p>Save settings first; the snippet updates with them.</p>
			<textarea class="large-text code" rows="14" readonly onclick="this.select()"><?php echo esc_textarea( $snippet ); ?></textarea>

			<h2>2. Fields on each inquiry form</h2>
			<?php self::render_forms_table(); ?>
		</div>
		<?php
	}

	private static function render_forms_table() {
		if ( ! class_exists( 'GFAPI' ) ) {
			echo '<p>Gravity Forms is not active.</p>';
			return;
		}

		$forms = GFAPI::get_forms();
		if ( ! $forms ) {
			echo '<p>No forms found.</p>';
			return;
		}
		?>
		<p>Tick the forms that count as inquiries. Missing hidden fields are added at the end of the form. The question and its "Tell us more" box go just before the submit button. Fields a form already has are skipped, so running this twice is safe.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="tssc_add_fields">
			<?php wp_nonce_field( 'tssc_add_fields' ); ?>
			<table class="widefat striped" style="max-width:960px">
				<thead>
					<tr>
						<th>Form</th>
						<th>Hidden source fields</th>
						<th>"How did you hear about us?"</th>
						<th>Add hidden fields</th>
						<th>Add question</th>
						<th>Question required</th>
					</tr>
				</thead>
				<tbody>
				<?php
				foreach ( $forms as $form ) :
					$names   = self::input_names( $form );
					$missing = array_diff( array_keys( self::FIELDS ), $names );
					$has_q   = in_array( self::HEARD_NAME, $names, true );
					$id      = (int) $form['id'];
					?>
					<tr>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_edit_forms&id=' . $id ) ); ?>"><?php echo esc_html( $form['title'] ); ?></a> <span class="description">#<?php echo esc_html( $id ); ?></span></td>
						<td><?php echo $missing ? esc_html( ( 4 - count( $missing ) ) . ' of 4' ) : '&#10003; all 4'; ?></td>
						<td><?php echo $has_q ? '&#10003;' : '&mdash;'; ?></td>
						<td><?php if ( $missing ) : ?><input type="checkbox" name="hidden[]" value="<?php echo esc_attr( $id ); ?>"><?php endif; ?></td>
						<td><?php if ( ! $has_q ) : ?><input type="checkbox" name="question[]" value="<?php echo esc_attr( $id ); ?>"><?php endif; ?></td>
						<td><?php if ( ! $has_q ) : ?><input type="checkbox" name="required[]" value="<?php echo esc_attr( $id ); ?>" checked><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( 'Add fields to ticked forms' ); ?>
		</form>
		<?php
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'tssc_save' );

		if ( isset( $_GET['dismiss_migration'] ) ) {
			delete_transient( 'tssc_migration_notice' );
			self::redirect( '' );
		}

		$delivery = isset( $_POST['delivery'] ) && 'plugin' === $_POST['delivery'] ? 'plugin' : 'gtm';
		$domain   = self::clean_host( isset( $_POST['domain'] ) ? wp_unslash( $_POST['domain'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$domain   = preg_replace( '/^www\./', '', $domain );

		$ignore = array();
		$lines  = preg_split( '/[\s,]+/', isset( $_POST['ignore'] ) ? wp_unslash( $_POST['ignore'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( $lines as $line ) {
			$host = self::clean_host( $line );
			if ( $host && ! in_array( $host, self::DEFAULT_IGNORE, true ) ) {
				$ignore[] = $host;
			}
		}

		$choices = array();
		$lines   = preg_split( '/\r\n|\r|\n/', isset( $_POST['choices'] ) ? wp_unslash( $_POST['choices'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( $lines as $line ) {
			$line = sanitize_text_field( $line );
			if ( '' !== $line ) {
				$choices[] = $line;
			}
		}

		$locations = array();
		$lines     = preg_split( '/\r\n|\r|\n/', isset( $_POST['contact_locations'] ) ? wp_unslash( $_POST['contact_locations'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( $lines as $line ) {
			$parts = array_map( 'trim', explode( '=>', $line, 2 ) );
			if ( 2 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1] ) {
				$locations[] = array( wp_strip_all_tags( $parts[0] ), sanitize_key( $parts[1] ) );
			}
		}
		$window = isset( $_POST['contact_ad_window_days'] ) ? absint( $_POST['contact_ad_window_days'] ) : 30;

		update_option(
			self::OPTION,
			array(
				'delivery' => $delivery,
				'domain'   => $domain ? $domain : self::default_domain(),
				'ignore'   => array_values( array_unique( $ignore ) ),
				'choices'  => $choices ? array_values( array_unique( $choices ) ) : self::DEFAULT_CHOICES,
				'contact'  => array(
					'track_clicks'   => ! empty( $_POST['contact_track_clicks'] ),
					'body_lines'     => ! empty( $_POST['contact_body_lines'] ),
					'main_email'     => isset( $_POST['contact_main_email'] ) ? sanitize_email( wp_unslash( $_POST['contact_main_email'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					'ad_email'       => isset( $_POST['contact_ad_email'] ) ? sanitize_email( wp_unslash( $_POST['contact_ad_email'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					'ad_window_days' => min( 90, max( 1, $window ? $window : 30 ) ),
					'locations'      => $locations ? $locations : self::DEFAULT_LOCATIONS,
				),
			)
		);

		self::redirect( 'Settings saved.' );
	}

	public static function handle_add_fields() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'tssc_add_fields' );
		if ( ! class_exists( 'GFAPI' ) || ! class_exists( 'GF_Fields' ) ) {
			self::redirect( 'Gravity Forms is not active.' );
		}

		$hidden   = array_map( 'absint', isset( $_POST['hidden'] ) ? (array) $_POST['hidden'] : array() );
		$question = array_map( 'absint', isset( $_POST['question'] ) ? (array) $_POST['question'] : array() );
		$required = array_map( 'absint', isset( $_POST['required'] ) ? (array) $_POST['required'] : array() );

		$messages = array();
		foreach ( array_unique( array_merge( $hidden, $question ) ) as $form_id ) {
			$form = GFAPI::get_form( $form_id );
			if ( ! $form ) {
				continue;
			}
			$added = self::add_fields(
				$form,
				in_array( $form_id, $hidden, true ),
				in_array( $form_id, $question, true ),
				in_array( $form_id, $required, true )
			);
			if ( is_wp_error( $added ) ) {
				$messages[] = $form['title'] . ': ' . $added->get_error_message();
			} elseif ( $added ) {
				$messages[] = $form['title'] . ': added ' . $added . ' field' . ( 1 === $added ? '' : 's' );
			}
		}

		self::redirect( $messages ? implode( '. ', $messages ) . '.' : 'Nothing to add.' );
	}

	/** Adds the missing fields to one form. Returns the number added, or a WP_Error. */
	private static function add_fields( array $form, $add_hidden, $add_question, $question_required ) {
		$names   = self::input_names( $form );
		$next_id = 1;
		$page    = 1;
		foreach ( $form['fields'] as $field ) {
			$next_id = max( $next_id, (int) $field->id + 1 );
			$page    = max( $page, (int) $field->pageNumber );
		}
		$added = 0;

		if ( $add_question && ! in_array( self::HEARD_NAME, $names, true ) ) {
			$settings = self::settings();
			$choices  = array();
			foreach ( $settings['choices'] as $choice ) {
				$choices[] = array(
					'text'       => $choice,
					'value'      => $choice,
					'isSelected' => false,
				);
			}
			$new = array(
				GF_Fields::create(
					array(
						'type'        => 'select',
						'id'          => $next_id++,
						'formId'      => $form['id'],
						'pageNumber'  => $page,
						'label'       => 'How did you hear about us?',
						'inputName'   => self::HEARD_NAME,
						'isRequired'  => (bool) $question_required,
						'placeholder' => 'Select one',
						'choices'     => $choices,
					)
				),
				GF_Fields::create(
					array(
						'type'       => 'text',
						'id'         => $next_id++,
						'formId'     => $form['id'],
						'pageNumber' => $page,
						'label'      => 'Tell us more (optional)',
						'inputName'  => self::HEARD_DETAIL_NAME,
					)
				),
			);

			// Put the question before an inline submit button if the form has one; otherwise at the end.
			$at = count( $form['fields'] );
			foreach ( $form['fields'] as $i => $field ) {
				if ( 'submit' === $field->type ) {
					$at = $i;
					break;
				}
			}
			array_splice( $form['fields'], $at, 0, $new );
			$added += 2;
		}

		if ( $add_hidden ) {
			foreach ( self::FIELDS as $name => $label ) {
				if ( in_array( $name, $names, true ) ) {
					continue;
				}
				$form['fields'][] = GF_Fields::create(
					array(
						'type'              => 'hidden',
						'id'                => $next_id++,
						'formId'            => $form['id'],
						'pageNumber'        => $page,
						'label'             => $label,
						'inputName'         => $name,
						'allowsPrepopulate' => true,
					)
				);
				++$added;
			}
		}

		if ( ! $added ) {
			return 0;
		}

		$result = GFAPI::update_form( $form );
		return is_wp_error( $result ) ? $result : $added;
	}

	private static function input_names( array $form ) {
		$names = array();
		foreach ( (array) $form['fields'] as $field ) {
			if ( ! empty( $field->inputName ) ) {
				$names[] = (string) $field->inputName;
			}
		}
		return $names;
	}

	private static function redirect( $notice ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		if ( '' !== $notice ) {
			$url = add_query_arg( 'tssc_notice', rawurlencode( $notice ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}
}

TSSC_Source_Capture::init();

require_once __DIR__ . '/includes/class-tssc-updater.php';
TSSC_Updater::init( __FILE__, TSSC_Source_Capture::VERSION );
