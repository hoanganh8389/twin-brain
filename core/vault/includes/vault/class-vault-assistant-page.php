<?php
/**
 * BizCity_Vault_Assistant_Page — wp-admin "🤖 Trợ lý của tôi" (PHASE-0.153 V3, doc 00 §4.4, Q153-7).
 *
 * Owner 15/11: "Mỗi user id có thể setup QR chatid của riêng mình, mỗi chủ trợ lý đều có màn giao diện và hiện thông tin ngay trên màn ma trận,
 * nhưng mỗi user id cũng cần có UI matrix tương tự."
 *
 *   assistant card   the Zalo numbers this user owns (bizcity_zalo_accounts.owner_user_id) + "Quét QR" (REST zalo-remote …/login, open to the
 *                    number's owner since V3) — adding a NEW number to the hub stays an admin's job
 *   matrix           GET / PUT bizcity-vault/v1/policy (auto → ask → off by click; locked cells shown as such)
 *   spaces           GET bizcity-vault/v1/spaces + PUT /policy/space (one group / one customer overrides the matrix)
 *   admin            "Đang xem trợ lý của …" (as_user, Q153-6)
 *
 * PHP + plain JS (no build step): the page works as soon as the file is uploaded. Every number / name shown comes from the site, escaped.
 *
 * [2026-11-15 11:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 V3 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Vault
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Vault_Assistant_Page', false ) ) {
	return;
}

final class BizCity_Vault_Assistant_Page {

	const SLUG   = 'bizcity-my-assistant';
	const PARENT = 'bizcity-knowledge';
	const CAP    = 'edit_posts'; // Q153-11: edit_posts and up may have an assistant of their own

	private static $hooked = false;

	public static function init(): void {
		if ( self::$hooked || ! function_exists( 'add_action' ) ) {
			return;
		}
		self::$hooked = true;
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 31 );
	}

	public static function add_menu(): void {
		add_submenu_page( self::PARENT, 'Trợ lý của tôi', '🤖 Trợ lý của tôi', self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
	}

	/** The Zalo numbers a user owns: [{ref, label, status}] (bridge `rzh:<ref>`; other bridges are listed without QR). */
	public static function numbers_of( int $user_id ): array {
		if ( $user_id <= 0 || ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) || ! method_exists( 'BizCity_Zalo_Mapping_Repo', 'list_personal_accounts_for_owner' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner( $user_id ) as $row ) {
			$row    = (array) $row;
			$bridge = (string) ( $row['bridge_account_id'] ?? '' );
			$out[]  = array(
				'ref'    => 0 === strpos( $bridge, 'rzh:' ) ? substr( $bridge, 4 ) : '',
				'label'  => (string) ( $row['label'] ?? $row['account_name'] ?? $bridge ),
				'status' => (string) ( $row['status'] ?? '' ),
			);
		}
		return $out;
	}

	/** Users who own at least one number (admin picker). [{id, name}] */
	public static function owners(): array { // PHASE-0.153 V5 — also the Kho tri thức picker
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$table = $wpdb->prefix . 'bizcity_zalo_accounts';
		$ids   = (array) $wpdb->get_col( "SELECT DISTINCT owner_user_id FROM `{$table}` WHERE owner_user_id > 0 AND kind = 'personal'" ); // phpcs:ignore WordPress.DB
		$out   = array();
		foreach ( $ids as $id ) {
			$u = get_userdata( (int) $id );
			if ( $u ) {
				$out[] = array( 'id' => (int) $id, 'name' => (string) $u->display_name );
			}
		}
		return $out;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bạn không có quyền xem trang này.', 'bizcity-twin-ai' ) );
		}
		$admin   = current_user_can( 'manage_options' );
		$as_user = $admin ? absint( $_GET['as_user'] ?? 0 ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$me      = (int) get_current_user_id();
		$who     = $as_user > 0 ? $as_user : $me;
		$user    = get_userdata( $who );
		$numbers = self::numbers_of( $who );
		$has     = class_exists( 'BizCity_Vault_Owner' ) && ( $admin || BizCity_Vault_Owner::has_own_kho( $me ) );
		$config  = array(
			'vault'   => esc_url_raw( rest_url( 'bizcity-vault/v1' ) ),
			'channel' => esc_url_raw( rest_url( 'bizcity-channel/v1' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'asUser'  => $as_user,
			'numbers' => $numbers,
		);
		?>
		<div class="wrap" id="bz-assistant">
			<h1>🤖 Trợ lý của tôi</h1>
			<?php if ( $admin ) : ?>
				<form method="get" style="margin:8px 0 16px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<label for="bz-as-user">Admin đang xem trợ lý của</label>
					<select id="bz-as-user" name="as_user">
						<option value="0">Tôi</option>
						<?php foreach ( self::owners() as $o ) : ?>
							<option value="<?php echo esc_attr( (string) $o['id'] ); ?>" <?php selected( $as_user, $o['id'] ); ?>><?php echo esc_html( $o['name'] . ' · user #' . $o['id'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<button class="button">Xem</button>
				</form>
			<?php endif; ?>

			<div class="card" style="max-width:none;padding:16px 20px">
				<h2 style="margin-top:0">Thẻ trợ lý · <?php echo esc_html( $user ? $user->display_name : '' ); ?> <span style="color:#646970;font-weight:400">(user #<?php echo esc_html( (string) $who ); ?>)</span></h2>
				<p style="margin:0 0 10px;color:#50575e">Kho tri thức, Sổ và lịch của trợ lý đều đứng tên tài khoản này.</p>
				<?php if ( ! $numbers ) : ?>
					<p><strong>Chưa có số Zalo nào.</strong> Nhờ admin ghép một số Zalo cho tài khoản này; sau đó bạn tự quét QR tại đây.</p>
				<?php else : ?>
					<table class="widefat striped" style="max-width:720px">
						<thead><tr><th>Số Zalo</th><th>Trạng thái</th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $numbers as $i => $n ) : ?>
							<tr>
								<td><?php echo esc_html( $n['label'] ); ?></td>
								<td><?php echo esc_html( 'connected' === $n['status'] ? 'Đang kết nối' : ( '' !== $n['status'] ? $n['status'] : '—' ) ); ?></td>
								<td><?php if ( '' !== $n['ref'] ) : ?><button type="button" class="button bz-qr" data-i="<?php echo esc_attr( (string) $i ); ?>">Quét QR</button><?php endif; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<div id="bz-qr-box" style="margin-top:12px"></div>
				<?php endif; ?>
			</div>

			<?php if ( ! $has ) : ?>
				<div class="notice notice-info inline" style="margin-top:16px"><p>Bạn chưa có trợ lý riêng nên chưa có ma trận lưu. Khi tài khoản được ghép một số Zalo, ma trận sẽ hiện ở đây.</p></div>
			<?php else : ?>
				<div class="card" style="max-width:none;padding:16px 20px;margin-top:16px">
					<h2 style="margin-top:0">Lưu gì, vào đâu</h2>
					<p style="margin:0 0 10px;color:#50575e">Bấm vào ô để đổi: <b>Tự động</b> → <b>Khi được yêu cầu</b> → <b>Tắt</b>. Mặc định: khách không lưu tài liệu; nhóm có chủ tự động lưu.</p>
					<?php if ( count( $numbers ) > 1 ) : ?>
						<p><label for="bz-acc">Áp dụng cho</label>
						<select id="bz-acc"><option value="">Mọi số của tôi</option>
							<?php foreach ( $numbers as $n ) : if ( '' === $n['ref'] ) { continue; } ?>
								<option value="<?php echo esc_attr( $n['ref'] ); ?>"><?php echo esc_html( $n['label'] ); ?></option>
							<?php endforeach; ?>
						</select></p>
					<?php endif; ?>
					<div id="bz-matrix">Đang tải…</div>
					<p><button type="button" class="button button-primary" id="bz-save" disabled>Lưu ma trận</button> <span id="bz-msg"></span></p>
				</div>

				<div class="card" style="max-width:none;padding:16px 20px;margin-top:16px">
					<h2 style="margin-top:0">Ngăn nhóm và ngăn khách</h2>
					<div id="bz-spaces">Đang tải…</div>
				</div>
			<?php endif; ?>
		</div>
		<script>
		window.bzAssistant = <?php echo wp_json_encode( $config ); ?>;
		(function () {
			var C = window.bzAssistant, LABEL = { auto: 'Tự động', ask: 'Khi được yêu cầu', off: 'Tắt', na: 'Không áp dụng' }, NEXT = { auto: 'ask', ask: 'off', off: 'auto' };
			var state = null, dirty = {};
			function q(url) { return url + (url.indexOf('?') < 0 ? '?' : '&') + (C.asUser ? 'as_user=' + C.asUser : ''); }
			function api(base, path, opt) {
				opt = opt || {};
				return fetch(q(base + path), { method: opt.method || 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce, 'Content-Type': 'application/json' }, body: opt.body ? JSON.stringify(opt.body) : undefined })
					.then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw new Error(j.message || 'Lỗi'); } return j; }); });
			}
			function el(tag, txt) { var e = document.createElement(tag); if (txt !== undefined) { e.textContent = txt; } return e; }
			function account() { var s = document.getElementById('bz-acc'); return s ? s.value : ''; }
			function drawMatrix() {
				var box = document.getElementById('bz-matrix'); box.textContent = '';
				var t = el('table'); t.className = 'widefat'; var hr = el('tr'); hr.appendChild(el('th', 'Việc'));
				Object.keys(state.cols).forEach(function (c) { hr.appendChild(el('th', state.cols[c])); });
				var th = el('thead'); th.appendChild(hr); t.appendChild(th); var tb = el('tbody');
				Object.keys(state.rows).forEach(function (r) {
					var tr = el('tr'); tr.appendChild(el('td', state.rows[r]));
					Object.keys(state.cols).forEach(function (c) {
						var td = el('td'), mode = (dirty[r] && dirty[r][c]) || state.matrix[r][c];
						if (state.defaults[r][c] === 'na') { td.appendChild(el('span', LABEL.na)); td.style.color = '#8c8f94'; }
						else {
							var b = el('button', LABEL[mode]); b.type = 'button'; b.className = 'button' + (mode === 'auto' ? ' button-primary' : '');
							b.setAttribute('aria-label', state.rows[r] + ' · ' + state.cols[c] + ': ' + LABEL[mode]);
							b.onclick = function () { dirty[r] = dirty[r] || {}; dirty[r][c] = NEXT[mode]; document.getElementById('bz-save').disabled = false; drawMatrix(); };
							td.appendChild(b);
						}
						tr.appendChild(td);
					});
					tb.appendChild(tr);
				});
				t.appendChild(tb); box.appendChild(t);
			}
			function loadMatrix() {
				api(C.vault, '/policy' + (account() ? '?account=' + encodeURIComponent(account()) : '')).then(function (j) { state = j; dirty = {}; drawMatrix(); document.getElementById('bz-save').disabled = true; })
					.catch(function (e) { document.getElementById('bz-matrix').textContent = e.message; });
			}
			function loadSpaces() {
				var box = document.getElementById('bz-spaces');
				api(C.vault, '/spaces').then(function (j) {
					box.textContent = '';
					if (!j.spaces.length) { box.appendChild(el('p', 'Chưa có ngăn nào. Ngăn nhóm / khách xuất hiện khi trợ lý lưu ghi chú, tệp hoặc biên bản cho họ.')); return; }
					var t = el('table'); t.className = 'widefat striped'; var hr = el('tr');
					['Ngăn', 'Loại', 'Ghi chú', 'Tệp', 'Lưu tệp ở ngăn này'].forEach(function (h) { hr.appendChild(el('th', h)); });
					var th = el('thead'); th.appendChild(hr); t.appendChild(th); var tb = el('tbody');
					j.spaces.forEach(function (s) {
						var tr = el('tr'); tr.appendChild(el('td', s.label || ('#' + s.hash))); tr.appendChild(el('td', s.type === 'group' ? 'Nhóm' : 'Khách'));
						tr.appendChild(el('td', String(s.notes))); tr.appendChild(el('td', s.usage.files + ' / ' + s.usage.max_files));
						var td = el('td'), sel = el('select'); sel.setAttribute('aria-label', 'Lưu tệp ở ngăn ' + (s.label || s.hash));
						[['', 'Theo ma trận'], ['auto', LABEL.auto], ['ask', LABEL.ask], ['off', LABEL.off]].forEach(function (o) { var op = el('option', o[1]); op.value = o[0]; sel.appendChild(op); });
						sel.value = (state && state.spaces[s.space] && state.spaces[s.space].files) || '';
						sel.onchange = function () { api(C.vault, '/policy/space', { method: 'PUT', body: { space: s.space, patch: { files: sel.value || null } } }).then(loadMatrix).catch(function (e) { alert(e.message); }); };
						td.appendChild(sel); tr.appendChild(td); tb.appendChild(tr);
					});
					t.appendChild(tb); box.appendChild(t);
				}).catch(function (e) { box.textContent = e.message; });
			}
			var save = document.getElementById('bz-save');
			if (save) {
				save.onclick = function () {
					save.disabled = true;
					api(C.vault, '/policy', { method: 'PUT', body: { account: account(), matrix: dirty } }).then(function (j) { state = j; dirty = {}; drawMatrix(); document.getElementById('bz-msg').textContent = 'Đã lưu.'; })
						.catch(function (e) { save.disabled = false; document.getElementById('bz-msg').textContent = e.message; });
				};
				var acc = document.getElementById('bz-acc'); if (acc) { acc.onchange = loadMatrix; }
				loadMatrix(); loadSpaces();
			}
			Array.prototype.forEach.call(document.querySelectorAll('.bz-qr'), function (btn) {
				btn.onclick = function () {
					var n = C.numbers[+btn.getAttribute('data-i')], box = document.getElementById('bz-qr-box'), started = Date.now();
					box.textContent = 'Đang mở phiên QR…';
					var path = '/zalo-remote/accounts/' + encodeURIComponent(n.ref) + '/login';
					function poll() {
						api(C.channel, path).then(function (j) {
							var d = j.data || {}, img = d.qrDataUri || d.qr || '';
							box.textContent = '';
							box.appendChild(el('p', 'Trạng thái: ' + (d.status || '—')));
							if (img && /^data:image\/(png|jpeg);base64,/.test(img)) { var i = el('img'); i.src = img; i.alt = 'Mã QR đăng nhập Zalo'; i.style.maxWidth = '240px'; box.appendChild(i); }
							if (['connected', 'success', 'done', 'timeout', 'error', 'failed'].indexOf(d.status) < 0 && Date.now() - started < 180000) { setTimeout(poll, 3000); }
						}).catch(function (e) { box.textContent = e.message; });
					}
					api(C.channel, path, { method: 'POST', body: { force: true } }).then(poll).catch(function (e) { box.textContent = e.message; });
				};
			});
		})();
		</script>
		<?php
	}
}
