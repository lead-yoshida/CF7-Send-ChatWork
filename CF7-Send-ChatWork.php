<?php
/*
Plugin Name: CF7-Send-ChatWork
Description: Contact Form 7 のお問い合わせをChatWork（チャットワーク）に通知するプラグインです。MultiStep対応。
Version: 1.0.0
Author: Hiroshiy
*/

if (!defined('ABSPATH')) exit;

class CF7_ChatWork_Notify {
	private $opt_key = 'cf7_cw_notify_options';

	public function __construct() {
		add_action('admin_menu', [$this, 'add_settings_page']);
		add_action('admin_init', [$this, 'register_settings']);

		// CF7 が有効なときだけフロント側を実行
		add_action('init', function () {
			if (defined('WPCF7_VERSION')) {
				add_filter('wpcf7_form_hidden_fields', [$this, 'append_hidden_fields'], 10, 1);
				add_action('wp_enqueue_scripts', [$this, 'enqueue_front_js']);
				add_action('wpcf7_mail_sent', [$this, 'on_mail_sent'], 10, 1);
			}
		});
	}

	/* ========== Settings ========== */

	public function add_settings_page() {
		add_options_page(
			'CF7 ChatWork通知',
			'CF7 ChatWork通知',
			'manage_options',
			'cf7-cw-notify',
			[$this, 'render_settings_page']
		);
	}

	public function register_settings() {
		register_setting($this->opt_key, $this->opt_key, [$this, 'sanitize_options']);

		add_settings_section('cf7_cw_main', '基本設定', function () {
			echo '<p>ChatWork API と通知先を設定します。確認画面のURL（パス）は必ず正確に入力してください。</p>';
		}, 'cf7-cw-notify');

		add_settings_field('api_token', 'ChatWork APIトークン', [$this, 'field_api_token'], 'cf7-cw-notify', 'cf7_cw_main');
		add_settings_field('room_id', 'ルームID', [$this, 'field_room_id'], 'cf7-cw-notify', 'cf7_cw_main');
		add_settings_field('confirm_path', '確認画面のパス', [$this, 'field_confirm_path'], 'cf7-cw-notify', 'cf7_cw_main');
	}

	public function sanitize_options($in) {
		$out = [
			'api_token'    => isset($in['api_token']) ? trim(wp_strip_all_tags($in['api_token'])) : '',
			'room_id'      => isset($in['room_id']) ? preg_replace('/[^0-9]/', '', $in['room_id']) : '',
			'confirm_path' => isset($in['confirm_path']) ? $this->sanitize_path($in['confirm_path']) : '',
		];
		return $out;
	}

	private function sanitize_path($path) {
		$path = trim($path);
		$parsed = parse_url($path, PHP_URL_PATH);
		if ($parsed === null || $parsed === false) $parsed = '/';
		if ($parsed === '') $parsed = '/';
		// 先頭スラッシュ付与
		if ($parsed[0] !== '/') $parsed = '/' . $parsed;
		// 末尾はスラッシュで統一
		if (substr($parsed, -1) !== '/') $parsed .= '/';
		return $parsed;
	}

	private function get_options() {
		$defaults = [
			'api_token'    => '',
			'room_id'      => '',
			'confirm_path' => '/wordpress/contact/confirm/',
		];
		$opt = get_option($this->opt_key, []);
		return wp_parse_args(is_array($opt) ? $opt : [], $defaults);
	}

	public function field_api_token() {
		$opt = $this->get_options();
		printf(
			'<input type="text" name="%1$s[api_token]" value="%2$s" class="regular-text" autocomplete="off" />',
			esc_attr($this->opt_key),
			esc_attr($opt['api_token'])
		);
		echo '<p class="description">Chatwork APIトークンを入力してください。<br>取得URL（要Chatworkログイン）：<a target="_blank" href="https://www.chatwork.com/service/packages/chatwork/subpackages/api/token.php">https://www.chatwork.com/service/packages/chatwork/subpackages/api/token.php</a></p>';
	}

	public function field_room_id() {
		$opt = $this->get_options();
		printf(
			'<input type="text" name="%1$s[room_id]" value="%2$s" class="regular-text" />',
			esc_attr($this->opt_key),
			esc_attr($opt['room_id'])
		);
		echo '<p class="description">通知先ルームの数値ID。例）https://www.chatwork.com/#!rid<b>123456789</b></p>';
	}

	public function field_confirm_path() {
		$opt = $this->get_options();
		printf(
			'<input type="text" name="%1$s[confirm_path]" value="%2$s" class="regular-text" />',
			esc_attr($this->opt_key),
			esc_attr($opt['confirm_path'])
		);
		echo '<p class="description">確認画面の<strong>パス</strong>を指定（例: <code>/wordpress/contact/confirm/</code>）。末尾スラッシュ必須。</p>';
	}

	public function render_settings_page() {
		if (!current_user_can('manage_options')) return;
		?>
		<div class="wrap">
			<h1>CF7 ChatWork通知 設定</h1>
			<form method="post" action="options.php">
				<?php
				settings_fields($this->opt_key);
				do_settings_sections('cf7-cw-notify');
				submit_button();
				?>
			</form>
			<hr />
			<p>
				<strong>使い方</strong><br>
				1) 上記を保存<br>
				2) Contact Form 7 のフォームはそのままでOK（コードが hidden フィールドを自動追加）<br>
				3) 入力→確認では通知されず、確認→完了のみ通知されます
			</p>
		</div>
		<?php
	}

	/* ========== Front ========== */

	public function append_hidden_fields($hidden) {
		$hidden['cw_token'] = '';
		$hidden['cw_allow'] = '';
		return $hidden;
	}

	public function enqueue_front_js() {
		if (is_admin()) return;
		$opt = $this->get_options();
		$handle = 'cf7-cw-allow';
		wp_register_script($handle, false, [], null, true);
		wp_enqueue_script($handle);

		$confirmPath = esc_js($opt['confirm_path']);

		$inline_js = <<<JS
(function(){
  function setup(form){
    var tokenInput = form.querySelector('input[name="cw_token"]');
    if (tokenInput) {
      var key = 'cf7_cw_token_global';
      var token = sessionStorage.getItem(key);
      if(!token){
        if (window.crypto && crypto.randomUUID) {
          token = crypto.randomUUID();
        } else {
          token = String(Date.now()) + Math.random().toString(16).slice(2);
        }
        sessionStorage.setItem(key, token);
      }
      tokenInput.value = token;
    }
    var allowInput = form.querySelector('input[name="cw_allow"]');
    if (!allowInput) return;
    allowInput.value = '';
    form.addEventListener('submit', function(){
      try {
        var path = location.pathname || '';
        if (path.slice(-1) !== '/') path += '/';
        if (path.indexOf('{$confirmPath}') > -1) {
          allowInput.value = '1';
        } else {
          allowInput.value = '';
        }
      } catch(e){}
    });
  }
  function applyAll(){
    document.querySelectorAll('form.wpcf7-form').forEach(setup);
  }
  document.addEventListener('DOMContentLoaded', applyAll);
  var mo = new MutationObserver(applyAll);
  mo.observe(document.body, {childList:true, subtree:true});
})();
JS;
		wp_add_inline_script($handle, $inline_js, 'after');
	}

	/* ========== Send ========== */

	private function send_to_chatwork($body, $api_token, $room_id) {
		$endpoint = 'https://api.chatwork.com/v2/rooms/' . rawurlencode($room_id) . '/messages';
		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL            => $endpoint,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => ['X-ChatWorkToken: ' . $api_token],
			CURLOPT_POSTFIELDS     => ['body' => $body],
			CURLOPT_TIMEOUT        => 8,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		]);
		$res  = curl_exec($ch);
		$err  = curl_error($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($err || $code >= 300) {
			if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
				error_log('[CF7_CW] send failed: http=' . $code . ' err=' . $err . ' body_len=' . strlen((string)$body));
			}
			return new WP_Error('chatwork_error', $err ? $err : 'http_' . $code);
		}
		if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
			error_log('[CF7_CW] send ok: http=' . $code . ' body_len=' . strlen((string)$body));
		}
		return $res;
	}

	public function on_mail_sent($contact_form) {
		$submission = WPCF7_Submission::get_instance();
		if (!$submission) return;

		$data = $submission->get_posted_data();

		// 確認→完了のみ許可
		if (empty($data['cw_allow']) || $data['cw_allow'] !== '1') {
			if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
				error_log('[CF7_CW] skip: cw_allow != 1');
			}
			return;
		}

		// 設定値
		$opt = $this->get_options();
		$api_token = $opt['api_token'];
		$room_id   = $opt['room_id'];
		if ($api_token === '' || $room_id === '') {
			if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
				error_log('[CF7_CW] skip: token/room not set');
			}
			return;
		}

		// CF7の「Mail」本文をレンダリング
		$mail     = $contact_form->prop('mail'); // ['subject','sender','body','use_html',...]
		$tpl_body = is_array($mail) && isset($mail['body']) ? (string)$mail['body'] : '';
		$use_html = is_array($mail) && !empty($mail['use_html']);

		if (function_exists('wpcf7_mail_replace_tags')) {
			$rendered = wpcf7_mail_replace_tags($tpl_body, $use_html);
		} else {
			$rendered = $tpl_body;
			foreach ((array)$data as $k => $v) {
				if (is_array($v)) $v = implode(', ', $v);
				$rendered = str_replace('[' . $k . ']', $v, $rendered);
			}
		}

		// ChatWorkはHTML不可
		if ($use_html) {
			$rendered = wp_strip_all_tags($rendered);
		}

		$this->send_to_chatwork($rendered, $api_token, $room_id);
	}
}

new CF7_ChatWork_Notify();