<?php
/**
 * Shim — BizCity_Twin_Tool moved to core/runtime/kernel/includes/interface-twin-tool.php.
 *
 * [2026-11-14 09:51 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.151 W1 (D151-2) — core/twin-core moved whole to core/runtime/kernel/. Add-ons
 * (bizcity-twin-brain-addon, bizcity-twinweb-addon) still require this old path (11 places, all behind interface_exists). The shim keeps them
 * working until they point at the kernel (PHASE-0.151 S151-5.1); then this file goes. Nothing else may live in core/twin-core/.
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Twin_Tool', false ) ) {
	require_once dirname( __DIR__, 2 ) . '/runtime/kernel/includes/interface-twin-tool.php';
}
