<?php
/**
 * Plugin Name: LINE Pay Taiwan for WooCommerce
 * Plugin URI: https://wpbrewer.com
 * Description: LINE Pay Gateway for Taiwan
 * Author: WPBrewer
 * Author URI: https://wpbrewer.com
 * Version: 0.0.9
 *
 * WC requires at least: 3.0.0
 * WC tested up to: 3.0.x, 3.2.x, 3.6.x
 *
 * Copyright (c) 2015 LINEPay
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

define( 'LINEPAY_TW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LINEPAY_TW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LINEPAY_TW_BASENAME', plugin_basename( __FILE__ ) );


function linepay_tw_needs_woocommerce() {

	echo '<div id="message" class="error">';
	echo '  <p>'.__('LINE Pay Taiwan for WooCommerce needs WooCommerce, please intall and activate WooCommerce first!', 'woo-linepay-tw').'</p>';
	echo '</div>';

}

function run_linepay_tw() {

    if ( !in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
        require_once( ABSPATH . 'wp-admin/includes/plugin.php');
        if ( is_plugin_active('woo-linepay-tw/woo-linepay-tw.php') ) {
            deactivate_plugins( LINEPAY_TW_BASENAME );
            add_action( 'admin_notices', 'linepay_tw_needs_woocommerce');
            return;
        }
    }

    require_once LINEPAY_TW_PLUGIN_DIR . 'includes/class-linepay-tw.php';
	LINEPay_TW::init();

}

add_action( 'plugins_loaded', 'run_linepay_tw');