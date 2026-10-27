<?php
/**
 * Plugin Name:       Warranty Manager
 * Description:       Ηλεκτρονική ενεργοποίηση και διαχείριση εγγυήσεων προϊόντων με σειριακούς αριθμούς, ειδοποιήσεις email/SMS και συμμόρφωση GDPR.
 * Version:           0.2.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Text Domain:       warranty-manager
 * License:           Proprietary
 */

defined( 'ABSPATH' ) || exit;

define( 'WR_VERSION', '0.2.0' );
define( 'WR_DB_VERSION', '2' );
define( 'WR_FILE', __FILE__ );
define( 'WR_DIR', plugin_dir_path( __FILE__ ) );
define( 'WR_URL', plugin_dir_url( __FILE__ ) );

require_once WR_DIR . 'includes/class-wr-db.php';
require_once WR_DIR . 'includes/class-wr-settings.php';
require_once WR_DIR . 'includes/class-wr-util.php';
require_once WR_DIR . 'includes/class-wr-crypto.php';
require_once WR_DIR . 'includes/class-wr-totp.php';
require_once WR_DIR . 'includes/class-wr-install.php';
require_once WR_DIR . 'includes/class-wr-audit.php';
require_once WR_DIR . 'includes/class-wr-terms.php';
require_once WR_DIR . 'includes/class-wr-warranties.php';
require_once WR_DIR . 'includes/class-wr-sms.php';
require_once WR_DIR . 'includes/class-wr-notifier.php';
require_once WR_DIR . 'includes/class-wr-cron.php';
require_once WR_DIR . 'includes/class-wr-xlsx-reader.php';
require_once WR_DIR . 'includes/class-wr-importer.php';
require_once WR_DIR . 'includes/class-wr-security.php';
require_once WR_DIR . 'includes/class-wr-frontend.php';
require_once WR_DIR . 'includes/class-wr-privacy.php';

register_activation_hook( __FILE__, array( 'WR_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WR_Install', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'WR_Install', 'maybe_upgrade' ) );
WR_Cron::init();
WR_Frontend::init();
WR_Privacy::init();

require_once WR_DIR . 'includes/class-wr-access.php';
WR_Access::init();

if ( is_admin() ) {
	require_once WR_DIR . 'includes/admin/class-wr-admin.php';
	WR_Admin::init();
}
