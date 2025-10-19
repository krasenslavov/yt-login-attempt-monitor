<?php
/**
 * Plugin Name: YT Login Attempt Monitor
 * Plugin URI: https://github.com/krasenslavov/yt-login-attempt-monitor
 * Description: Monitor and log all login attempts (successful and failed) with detailed information including user, IP address, status, and timestamp.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Krasen Slavov
 * Author URI: https://krasenslavov.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: yt-login-attempt-monitor
 * Domain Path: /languages
 *
 * @package YT_Login_Attempt_Monitor
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Current plugin version.
 */
define( 'YT_LAM_VERSION', '1.0.0' );

/**
 * Plugin base name.
 */
define( 'YT_LAM_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Plugin directory path.
 */
define( 'YT_LAM_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL.
 */
define( 'YT_LAM_URL', plugin_dir_url( __FILE__ ) );

/**
 * Main plugin class for Login Attempt Monitor.
 *
 * @since 1.0.0
 */
class YT_Login_Attempt_Monitor {

	/**
	 * Single instance of the class.
	 *
	 * @var YT_Login_Attempt_Monitor|null
	 */
	private static $instance = null;

	/**
	 * Database table name.
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Plugin options.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Get single instance of the class.
	 *
	 * @return YT_Login_Attempt_Monitor
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'login_attempts';
		$this->options    = get_option( 'yt_lam_options', $this->get_default_options() );
		$this->init_hooks();
	}

	/**
	 * Get default plugin options.
	 *
	 * @return array
	 */
	private function get_default_options() {
		return array(
			'log_successful'   => true,
			'log_failed'       => true,
			'retention_days'   => 30,
			'max_logs'         => 1000,
			'track_user_agent' => true,
			'email_on_failed'  => false,
			'failed_threshold' => 5,
		);
	}

	/**
	 * Initialize WordPress hooks.
	 *
	 * @return void
	 */
	private function init_hooks() {
		// Load plugin text domain.
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		// Login monitoring hooks.
		add_action( 'wp_login', array( $this, 'log_successful_login' ), 10, 2 );
		add_action( 'wp_login_failed', array( $this, 'log_failed_login' ) );

		// Admin hooks.
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
			add_filter( 'plugin_action_links_' . YT_LAM_BASENAME, array( $this, 'add_action_links' ) );

			// AJAX handlers.
			add_action( 'wp_ajax_yt_lam_delete_log', array( $this, 'ajax_delete_log' ) );
			add_action( 'wp_ajax_yt_lam_clear_logs', array( $this, 'ajax_clear_logs' ) );
			add_action( 'wp_ajax_yt_lam_export_logs', array( $this, 'ajax_export_logs' ) );
		}

		// Scheduled cleanup.
		add_action( 'yt_lam_daily_cleanup', array( $this, 'cleanup_old_logs' ) );
	}

	/**
	 * Load plugin text domain for translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'yt-login-attempt-monitor',
			false,
			dirname( YT_LAM_BASENAME ) . '/languages'
		);
	}

	/**
	 * Create database table for login logs.
	 *
	 * @return void
	 */
	private function create_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$this->table_name} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			user_login varchar(60) NOT NULL,
			user_id bigint(20) DEFAULT NULL,
			ip_address varchar(100) NOT NULL,
			user_agent text DEFAULT NULL,
			status varchar(20) NOT NULL,
			timestamp datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_login (user_login),
			KEY ip_address (ip_address),
			KEY status (status),
			KEY timestamp (timestamp)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Add plugin admin menu.
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		// Add submenu under Users.
		add_users_page(
			__( 'Login Log', 'yt-login-attempt-monitor' ),
			__( 'Login Log', 'yt-login-attempt-monitor' ),
			'manage_options',
			'yt-login-attempt-monitor',
			array( $this, 'render_logs_page' )
		);

		// Add settings page.
		add_options_page(
			__( 'Login Monitor Settings', 'yt-login-attempt-monitor' ),
			__( 'Login Monitor', 'yt-login-attempt-monitor' ),
			'manage_options',
			'yt-login-monitor-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'yt_lam_options_group',
			'yt_lam_options',
			array( $this, 'sanitize_options' )
		);

		add_settings_section(
			'yt_lam_main_section',
			__( 'Logging Settings', 'yt-login-attempt-monitor' ),
			array( $this, 'render_section_info' ),
			'yt-login-monitor-settings'
		);

		add_settings_field(
			'log_types',
			__( 'Log Types', 'yt-login-attempt-monitor' ),
			array( $this, 'render_log_types_field' ),
			'yt-login-monitor-settings',
			'yt_lam_main_section'
		);

		add_settings_field(
			'retention_days',
			__( 'Log Retention (days)', 'yt-login-attempt-monitor' ),
			array( $this, 'render_retention_field' ),
			'yt-login-monitor-settings',
			'yt_lam_main_section'
		);

		add_settings_field(
			'max_logs',
			__( 'Maximum Logs', 'yt-login-attempt-monitor' ),
			array( $this, 'render_max_logs_field' ),
			'yt-login-monitor-settings',
			'yt_lam_main_section'
		);

		add_settings_field(
			'track_user_agent',
			__( 'Track Browser Info', 'yt-login-attempt-monitor' ),
			array( $this, 'render_user_agent_field' ),
			'yt-login-monitor-settings',
			'yt_lam_main_section'
		);
	}

	/**
	 * Sanitize plugin options.
	 *
	 * @param array $input Raw input data.
	 * @return array Sanitized data.
	 */
	public function sanitize_options( $input ) {
		$sanitized = array();

		$sanitized['log_successful'] = isset( $input['log_successful'] ) ? (bool) $input['log_successful'] : false;
		$sanitized['log_failed']     = isset( $input['log_failed'] ) ? (bool) $input['log_failed'] : false;

		$sanitized['retention_days'] = isset( $input['retention_days'] )
			? absint( $input['retention_days'] )
			: 30;

		$sanitized['max_logs'] = isset( $input['max_logs'] )
			? absint( $input['max_logs'] )
			: 1000;

		$sanitized['track_user_agent'] = isset( $input['track_user_agent'] ) ? (bool) $input['track_user_agent'] : true;

		$sanitized['email_on_failed'] = isset( $input['email_on_failed'] ) ? (bool) $input['email_on_failed'] : false;

		$sanitized['failed_threshold'] = isset( $input['failed_threshold'] )
			? absint( $input['failed_threshold'] )
			: 5;

		return $sanitized;
	}

	/**
	 * Render settings section information.
	 *
	 * @return void
	 */
	public function render_section_info() {
		echo '<p>' . esc_html__( 'Configure how login attempts are logged and monitored.', 'yt-login-attempt-monitor' ) . '</p>';
	}

	/**
	 * Render log types checkboxes.
	 *
	 * @return void
	 */
	public function render_log_types_field() {
		$log_successful = isset( $this->options['log_successful'] ) ? $this->options['log_successful'] : true;
		$log_failed     = isset( $this->options['log_failed'] ) ? $this->options['log_failed'] : true;
		?>
		<label style="display: block; margin-bottom: 5px;">
			<input type="checkbox"
				name="yt_lam_options[log_successful]"
				value="1"
				<?php checked( $log_successful, true ); ?> />
			<?php esc_html_e( 'Log successful login attempts', 'yt-login-attempt-monitor' ); ?>
		</label>
		<label style="display: block; margin-bottom: 5px;">
			<input type="checkbox"
				name="yt_lam_options[log_failed]"
				value="1"
				<?php checked( $log_failed, true ); ?> />
			<?php esc_html_e( 'Log failed login attempts', 'yt-login-attempt-monitor' ); ?>
		</label>
		<?php
	}

	/**
	 * Render retention days field.
	 *
	 * @return void
	 */
	public function render_retention_field() {
		$value = isset( $this->options['retention_days'] ) ? $this->options['retention_days'] : 30;
		?>
		<input type="number"
			name="yt_lam_options[retention_days]"
			value="<?php echo esc_attr( $value ); ?>"
			min="1"
			max="365"
			class="small-text" />
		<p class="description">
			<?php esc_html_e( 'Automatically delete logs older than this many days (1-365).', 'yt-login-attempt-monitor' ); ?>
		</p>
		<?php
	}

	/**
	 * Render max logs field.
	 *
	 * @return void
	 */
	public function render_max_logs_field() {
		$value = isset( $this->options['max_logs'] ) ? $this->options['max_logs'] : 1000;
		?>
		<input type="number"
			name="yt_lam_options[max_logs]"
			value="<?php echo esc_attr( $value ); ?>"
			min="100"
			step="100"
			class="small-text" />
		<p class="description">
			<?php esc_html_e( 'Maximum number of logs to keep in database.', 'yt-login-attempt-monitor' ); ?>
		</p>
		<?php
	}

	/**
	 * Render user agent tracking field.
	 *
	 * @return void
	 */
	public function render_user_agent_field() {
		$value = isset( $this->options['track_user_agent'] ) ? $this->options['track_user_agent'] : true;
		?>
		<label>
			<input type="checkbox"
				name="yt_lam_options[track_user_agent]"
				value="1"
				<?php checked( $value, true ); ?> />
			<?php esc_html_e( 'Record browser and device information', 'yt-login-attempt-monitor' ); ?>
		</label>
		<?php
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( 'users_page_yt-login-attempt-monitor' !== $hook && 'settings_page_yt-login-monitor-settings' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'yt-lam-admin',
			YT_LAM_URL . 'assets/css/yt-login-attempt-monitor.css',
			array(),
			YT_LAM_VERSION
		);

		wp_enqueue_script(
			'yt-lam-admin',
			YT_LAM_URL . 'assets/js/yt-login-attempt-monitor.js',
			array( 'jquery' ),
			YT_LAM_VERSION,
			true
		);

		wp_localize_script(
			'yt-lam-admin',
			'ytLamData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'yt_lam_nonce' ),
				'strings' => array(
					'confirmDelete'   => __( 'Are you sure you want to delete this log entry?', 'yt-login-attempt-monitor' ),
					'confirmClearAll' => __( 'Are you sure you want to delete ALL log entries? This cannot be undone!', 'yt-login-attempt-monitor' ),
					'deleteSuccess'   => __( 'Log entry deleted successfully.', 'yt-login-attempt-monitor' ),
					'clearSuccess'    => __( 'All logs cleared successfully.', 'yt-login-attempt-monitor' ),
					'error'           => __( 'An error occurred. Please try again.', 'yt-login-attempt-monitor' ),
				),
			)
		);
	}

	/**
	 * Log successful login attempt.
	 *
	 * @param string  $user_login Username.
	 * @param WP_User $user       User object.
	 * @return void
	 */
	public function log_successful_login( $user_login, $user ) {
		if ( ! $this->options['log_successful'] ) {
			return;
		}

		$this->log_attempt( $user_login, $user->ID, 'success' );
	}

	/**
	 * Log failed login attempt.
	 *
	 * @param string $username Username used in failed attempt.
	 * @return void
	 */
	public function log_failed_login( $username ) {
		if ( ! $this->options['log_failed'] ) {
			return;
		}

		$this->log_attempt( $username, null, 'failed' );
	}

	/**
	 * Log login attempt to database.
	 *
	 * @param string   $user_login Username.
	 * @param int|null $user_id    User ID (null for failed attempts).
	 * @param string   $status     Status (success or failed).
	 * @return void
	 */
	private function log_attempt( $user_login, $user_id, $status ) {
		global $wpdb;

		$ip_address = $this->get_ip_address();
		$user_agent = $this->options['track_user_agent'] && isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: null;

		$wpdb->insert(
			$this->table_name,
			array(
				'user_login' => sanitize_user( $user_login ),
				'user_id'    => $user_id,
				'ip_address' => $ip_address,
				'user_agent' => $user_agent,
				'status'     => $status,
				'timestamp'  => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		// Check if we need to enforce max logs.
		$this->enforce_max_logs();
	}

	/**
	 * Get user's IP address.
	 *
	 * @return string
	 */
	private function get_ip_address() {
		$ip_keys = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_CLIENT_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_FORWARDED',
			'HTTP_X_CLUSTER_CLIENT_IP',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED',
			'REMOTE_ADDR',
		);

		foreach ( $ip_keys as $key ) {
			if ( isset( $_SERVER[ $key ] ) && filter_var( wp_unslash( $_SERVER[ $key ] ), FILTER_VALIDATE_IP ) ) {
				return sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Enforce maximum logs limit.
	 *
	 * @return void
	 */
	private function enforce_max_logs() {
		global $wpdb;

		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name}" ); // phpcs:ignore

		if ( $count > $this->options['max_logs'] ) {
			$delete_count = $count - $this->options['max_logs'];
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$this->table_name} ORDER BY timestamp ASC LIMIT %d", // phpcs:ignore
					$delete_count
				)
			);
		}
	}

	/**
	 * Cleanup old logs based on retention days.
	 *
	 * @return void
	 */
	public function cleanup_old_logs() {
		global $wpdb;

		$days = $this->options['retention_days'];

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table_name} WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)", // phpcs:ignore
				$days
			)
		);
	}

	/**
	 * Render logs page.
	 *
	 * @return void
	 */
	public function render_logs_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'yt-login-attempt-monitor' ) );
		}

		// Get filter parameters.
		$filter_status = isset( $_GET['filter_status'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_status'] ) ) : 'all';
		$search_term   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged         = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$per_page      = 20;

		// Get logs.
		$logs        = $this->get_logs( $filter_status, $search_term, $paged, $per_page );
		$total_logs  = $this->get_logs_count( $filter_status, $search_term );
		$total_pages = ceil( $total_logs / $per_page );

		// Get statistics.
		$stats = $this->get_statistics();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Login Attempt Log', 'yt-login-attempt-monitor' ); ?></h1>

			<div class="yt-lam-stats">
				<div class="yt-lam-stat-box yt-lam-stat-total">
					<div class="yt-lam-stat-value"><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></div>
					<div class="yt-lam-stat-label"><?php esc_html_e( 'Total Attempts', 'yt-login-attempt-monitor' ); ?></div>
				</div>
				<div class="yt-lam-stat-box yt-lam-stat-success">
					<div class="yt-lam-stat-value"><?php echo esc_html( number_format_i18n( $stats['successful'] ) ); ?></div>
					<div class="yt-lam-stat-label"><?php esc_html_e( 'Successful', 'yt-login-attempt-monitor' ); ?></div>
				</div>
				<div class="yt-lam-stat-box yt-lam-stat-failed">
					<div class="yt-lam-stat-value"><?php echo esc_html( number_format_i18n( $stats['failed'] ) ); ?></div>
					<div class="yt-lam-stat-label"><?php esc_html_e( 'Failed', 'yt-login-attempt-monitor' ); ?></div>
				</div>
				<div class="yt-lam-stat-box yt-lam-stat-today">
					<div class="yt-lam-stat-value"><?php echo esc_html( number_format_i18n( $stats['today'] ) ); ?></div>
					<div class="yt-lam-stat-label"><?php esc_html_e( 'Today', 'yt-login-attempt-monitor' ); ?></div>
				</div>
			</div>

			<div class="yt-lam-actions">
				<div class="yt-lam-filters">
					<form method="get" action="">
						<input type="hidden" name="page" value="yt-login-attempt-monitor" />

						<select name="filter_status" id="filter-status">
							<option value="all" <?php selected( $filter_status, 'all' ); ?>><?php esc_html_e( 'All Statuses', 'yt-login-attempt-monitor' ); ?></option>
							<option value="success" <?php selected( $filter_status, 'success' ); ?>><?php esc_html_e( 'Successful', 'yt-login-attempt-monitor' ); ?></option>
							<option value="failed" <?php selected( $filter_status, 'failed' ); ?>><?php esc_html_e( 'Failed', 'yt-login-attempt-monitor' ); ?></option>
						</select>

						<input type="search" name="s" value="<?php echo esc_attr( $search_term ); ?>" placeholder="<?php esc_attr_e( 'Search username or IP...', 'yt-login-attempt-monitor' ); ?>" />

						<button type="submit" class="button"><?php esc_html_e( 'Filter', 'yt-login-attempt-monitor' ); ?></button>
					</form>
				</div>

				<div class="yt-lam-bulk-actions">
					<button type="button" id="yt-lam-export-logs" class="button">
						<?php esc_html_e( 'Export CSV', 'yt-login-attempt-monitor' ); ?>
					</button>
					<button type="button" id="yt-lam-clear-logs" class="button">
						<?php esc_html_e( 'Clear All Logs', 'yt-login-attempt-monitor' ); ?>
					</button>
				</div>
			</div>

			<table class="wp-list-table widefat fixed striped yt-lam-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'yt-login-attempt-monitor' ); ?></th>
						<th><?php esc_html_e( 'Username', 'yt-login-attempt-monitor' ); ?></th>
						<th><?php esc_html_e( 'IP Address', 'yt-login-attempt-monitor' ); ?></th>
						<th><?php esc_html_e( 'Status', 'yt-login-attempt-monitor' ); ?></th>
						<th><?php esc_html_e( 'Browser', 'yt-login-attempt-monitor' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'yt-login-attempt-monitor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr>
							<td colspan="6" class="yt-lam-no-logs">
								<?php esc_html_e( 'No login attempts found.', 'yt-login-attempt-monitor' ); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $logs as $log ) : ?>
							<tr data-log-id="<?php echo esc_attr( $log->id ); ?>">
								<td>
									<strong><?php echo esc_html( $this->format_time_diff( $log->timestamp ) ); ?></strong>
									<br>
									<span class="yt-lam-timestamp"><?php echo esc_html( mysql2date( 'Y-m-d H:i:s', $log->timestamp ) ); ?></span>
								</td>
								<td>
									<strong><?php echo esc_html( $log->user_login ); ?></strong>
									<?php if ( $log->user_id ) : ?>
										<br>
										<a href="<?php echo esc_url( get_edit_user_link( $log->user_id ) ); ?>">
											<?php esc_html_e( 'View User', 'yt-login-attempt-monitor' ); ?>
										</a>
									<?php endif; ?>
								</td>
								<td>
									<code><?php echo esc_html( $log->ip_address ); ?></code>
								</td>
								<td>
									<?php if ( 'success' === $log->status ) : ?>
										<span class="yt-lam-status yt-lam-status-success">
											✓ <?php esc_html_e( 'Success', 'yt-login-attempt-monitor' ); ?>
										</span>
									<?php else : ?>
										<span class="yt-lam-status yt-lam-status-failed">
											✗ <?php esc_html_e( 'Failed', 'yt-login-attempt-monitor' ); ?>
										</span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $log->user_agent ) : ?>
										<span class="yt-lam-user-agent" title="<?php echo esc_attr( $log->user_agent ); ?>">
											<?php echo esc_html( $this->parse_user_agent( $log->user_agent ) ); ?>
										</span>
									<?php else : ?>
										<span class="yt-lam-na"><?php esc_html_e( 'N/A', 'yt-login-attempt-monitor' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<button type="button"
										class="button button-small yt-lam-delete-log"
										data-log-id="<?php echo esc_attr( $log->id ); ?>">
										<?php esc_html_e( 'Delete', 'yt-login-attempt-monitor' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						echo paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'prev_text' => __( '&laquo;', 'yt-login-attempt-monitor' ),
								'next_text' => __( '&raquo;', 'yt-login-attempt-monitor' ),
								'total'     => $total_pages,
								'current'   => $paged,
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'yt-login-attempt-monitor' ) );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'yt_lam_options_group' );
				do_settings_sections( 'yt-login-monitor-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Get logs from database.
	 *
	 * @param string $status      Filter by status.
	 * @param string $search_term Search term.
	 * @param int    $paged       Page number.
	 * @param int    $per_page    Logs per page.
	 * @return array
	 */
	private function get_logs( $status = 'all', $search_term = '', $paged = 1, $per_page = 20 ) {
		global $wpdb;

		$offset = ( $paged - 1 ) * $per_page;
		$where  = array();

		if ( 'all' !== $status ) {
			$where[] = $wpdb->prepare( 'status = %s', $status );
		}

		if ( ! empty( $search_term ) ) {
			$where[] = $wpdb->prepare(
				'(user_login LIKE %s OR ip_address LIKE %s)',
				'%' . $wpdb->esc_like( $search_term ) . '%',
				'%' . $wpdb->esc_like( $search_term ) . '%'
			);
		}

		$where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$query = "SELECT * FROM {$this->table_name} {$where_sql} ORDER BY timestamp DESC LIMIT %d OFFSET %d";

		return $wpdb->get_results(
			$wpdb->prepare( $query, $per_page, $offset ) // phpcs:ignore
		);
	}

	/**
	 * Get total logs count.
	 *
	 * @param string $status      Filter by status.
	 * @param string $search_term Search term.
	 * @return int
	 */
	private function get_logs_count( $status = 'all', $search_term = '' ) {
		global $wpdb;

		$where = array();

		if ( 'all' !== $status ) {
			$where[] = $wpdb->prepare( 'status = %s', $status );
		}

		if ( ! empty( $search_term ) ) {
			$where[] = $wpdb->prepare(
				'(user_login LIKE %s OR ip_address LIKE %s)',
				'%' . $wpdb->esc_like( $search_term ) . '%',
				'%' . $wpdb->esc_like( $search_term ) . '%'
			);
		}

		$where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name} {$where_sql}" ); // phpcs:ignore
	}

	/**
	 * Get login statistics.
	 *
	 * @return array
	 */
	private function get_statistics() {
		global $wpdb;

		return array(
			'total'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name}" ), // phpcs:ignore
			'successful' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'success'" ), // phpcs:ignore
			'failed'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'failed'" ), // phpcs:ignore
			'today'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name} WHERE DATE(timestamp) = CURDATE()" ), // phpcs:ignore
		);
	}

	/**
	 * Format time difference.
	 *
	 * @param string $timestamp MySQL timestamp.
	 * @return string
	 */
	private function format_time_diff( $timestamp ) {
		return sprintf(
			/* translators: %s: Time difference */
			__( '%s ago', 'yt-login-attempt-monitor' ),
			human_time_diff( strtotime( $timestamp ), current_time( 'timestamp' ) ) // phpcs:ignore
		);
	}

	/**
	 * Parse user agent string to readable format.
	 *
	 * @param string $user_agent User agent string.
	 * @return string
	 */
	private function parse_user_agent( $user_agent ) {
		// Simple parsing - can be enhanced with a library.
		if ( strpos( $user_agent, 'Chrome' ) !== false ) {
			return 'Chrome';
		} elseif ( strpos( $user_agent, 'Firefox' ) !== false ) {
			return 'Firefox';
		} elseif ( strpos( $user_agent, 'Safari' ) !== false ) {
			return 'Safari';
		} elseif ( strpos( $user_agent, 'Edge' ) !== false ) {
			return 'Edge';
		} elseif ( strpos( $user_agent, 'Opera' ) !== false ) {
			return 'Opera';
		}

		return __( 'Unknown', 'yt-login-attempt-monitor' );
	}

	/**
	 * AJAX handler to delete single log.
	 *
	 * @return void
	 */
	public function ajax_delete_log() {
		check_ajax_referer( 'yt_lam_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'yt-login-attempt-monitor' ) ) );
		}

		$log_id = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0;

		if ( ! $log_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid log ID.', 'yt-login-attempt-monitor' ) ) );
		}

		global $wpdb;
		$deleted = $wpdb->delete( $this->table_name, array( 'id' => $log_id ), array( '%d' ) );

		if ( $deleted ) {
			wp_send_json_success( array( 'message' => __( 'Log deleted successfully.', 'yt-login-attempt-monitor' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to delete log.', 'yt-login-attempt-monitor' ) ) );
		}
	}

	/**
	 * AJAX handler to clear all logs.
	 *
	 * @return void
	 */
	public function ajax_clear_logs() {
		check_ajax_referer( 'yt_lam_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'yt-login-attempt-monitor' ) ) );
		}

		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$this->table_name}" ); // phpcs:ignore

		wp_send_json_success( array( 'message' => __( 'All logs cleared successfully.', 'yt-login-attempt-monitor' ) ) );
	}

	/**
	 * AJAX handler to export logs to CSV.
	 *
	 * @return void
	 */
	public function ajax_export_logs() {
		check_ajax_referer( 'yt_lam_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'yt-login-attempt-monitor' ) );
		}

		global $wpdb;
		$logs = $wpdb->get_results( "SELECT * FROM {$this->table_name} ORDER BY timestamp DESC", ARRAY_A ); // phpcs:ignore

		// Set headers for CSV download.
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=login-attempts-' . gmdate( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );

		// Add CSV headers.
		fputcsv( $output, array( 'ID', 'Username', 'User ID', 'IP Address', 'Status', 'Browser', 'Timestamp' ) );

		// Add data rows.
		foreach ( $logs as $log ) {
			fputcsv(
				$output,
				array(
					$log['id'],
					$log['user_login'],
					$log['user_id'] ?: 'N/A', // phpcs:ignore
					$log['ip_address'],
					$log['status'],
					$log['user_agent'] ?: 'N/A', // phpcs:ignore
					$log['timestamp'],
				)
			);
		}

		fclose( $output );
		exit;
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array $links Existing action links.
	 * @return array Modified action links.
	 */
	public function add_action_links( $links ) {
		$plugin_links = array(
			'<a href="' . admin_url( 'users.php?page=yt-login-attempt-monitor' ) . '">' . __( 'View Logs', 'yt-login-attempt-monitor' ) . '</a>',
			'<a href="' . admin_url( 'options-general.php?page=yt-login-monitor-settings' ) . '">' . __( 'Settings', 'yt-login-attempt-monitor' ) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Plugin activation hook.
	 *
	 * @return void
	 */
	public static function activate() {
		$instance = self::get_instance();
		$instance->create_table();

		// Schedule daily cleanup.
		if ( ! wp_next_scheduled( 'yt_lam_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'yt_lam_daily_cleanup' );
		}

		// Set default options.
		if ( ! get_option( 'yt_lam_options' ) ) {
			add_option( 'yt_lam_options', $instance->get_default_options() );
		}
	}

	/**
	 * Plugin deactivation hook.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Remove scheduled event.
		$timestamp = wp_next_scheduled( 'yt_lam_daily_cleanup' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'yt_lam_daily_cleanup' );
		}
	}
}

/**
 * Plugin uninstall hook.
 *
 * @return void
 */
function yt_lam_uninstall() {
	global $wpdb;

	// Delete options.
	delete_option( 'yt_lam_options' );

	// Drop table.
	$table_name = $wpdb->prefix . 'login_attempts';
	$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore

	// Clear cache.
	wp_cache_flush();
}

// Register activation hook.
register_activation_hook( __FILE__, array( 'YT_Login_Attempt_Monitor', 'activate' ) );

// Register deactivation hook.
register_deactivation_hook( __FILE__, array( 'YT_Login_Attempt_Monitor', 'deactivate' ) );

// Register uninstall hook.
register_uninstall_hook( __FILE__, 'yt_lam_uninstall' );

// Initialize the plugin.
add_action( 'plugins_loaded', array( 'YT_Login_Attempt_Monitor', 'get_instance' ) );
