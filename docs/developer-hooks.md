# Hook cho lập trình viên

Mọi `apply_filters()` và `do_action()` do plugin tự gọi (tiền tố `dscc_`). Đây là danh sách đầy đủ tại thời điểm viết; nguồn là các file được dẫn ở từng mục, hãy tìm `apply_filters( 'dscc_` hoặc `do_action( 'dscc_` trong [`src/`](../src/) để kiểm tra lại. Hook của plugin khác mà DS Cookie Consent đăng ký (WP Rocket, LiteSpeed, PixelYourSite, Meta Pixel...) nằm trong [`src/Integrations/`](../src/Integrations/) và không thuộc tài liệu này.

Mục lục: [dscc_settings_defaults](#dscc_settings_defaults) · [dscc_should_render](#dscc_should_render) · [dscc_default_language](#dscc_default_language) · [dscc_client_side_language](#dscc_client_side_language) · [dscc_catalog_services](#dscc_catalog_services) · [dscc_update_repo](#dscc_update_repo) · [dscc_github_token](#dscc_github_token) · [dscc_known_db_url](#dscc_known_db_url) · [dscc_cookie_safelist](#dscc_cookie_safelist) · [dscc_blocker_enabled](#dscc_blocker_enabled) · [dscc_blocker_skip_tag](#dscc_blocker_skip_tag) · [dscc_blocker_slow_ms](#dscc_blocker_slow_ms) · [dscc_admin_tabs](#dscc_admin_tabs) · [dscc_policy_page_text](#dscc_policy_page_text) · [dscc_integration_detected](#dscc_integration_detected) · [dscc_client_ip](#dscc_client_ip) · [dscc_scan_urls](#dscc_scan_urls) · [dscc_scan_own_hosts](#dscc_scan_own_hosts) · [dscc_scan_request_args](#dscc_scan_request_args) · [dscc_scan_user_agent](#dscc_scan_user_agent) · [dscc_catalog_changed](#dscc_catalog_changed) · [dscc_scan_finished](#dscc_scan_finished)

Ví dụ dùng dữ liệu giả `LittleD` và `example.com`. Đặt mã vào plugin riêng hoặc `mu-plugin`, không sửa trực tiếp plugin này.

## Hiển thị và ngôn ngữ

### dscc_settings_defaults

`apply_filters( 'dscc_settings_defaults', array $defaults ): array` — `src/Settings/SettingsSchema.php`

Mặc định của `dscc_settings`, dạng `[ nhóm => [ khoá => giá trị ] ]` (các nhóm: `appearance`, `content`, `blocking`, `scanner`, `advanced`; khoá ở [configuration.md](configuration.md)). Chỉ đổi giá trị mặc định cho site chưa lưu; giá trị đã lưu vẫn thắng. Giữ nguyên cấu trúc mảng.

```php
add_filter( 'dscc_settings_defaults', function ( $defaults ) {
	$defaults['appearance']['layout']        = 'bar';
	$defaults['advanced']['retention_months'] = 12;
	return $defaults;
} );
```

### dscc_should_render

`apply_filters( 'dscc_should_render', bool $render = true ): bool` — `src/Frontend/Renderer.php`

Gọi sau khi plugin đã loại admin, AJAX, cron, feed, embed, REST, robots, xem trước Customizer và các trình dựng trang. Trả `false` để **tắt toàn bộ** ở yêu cầu đó: banner, script đầu trang (Consent Mode) và cả bộ chặn script (vì bộ chặn dùng cùng điều kiện).

```php
add_filter( 'dscc_should_render', function ( $render ) {
	return is_page( 'littled-landing' ) ? false : $render;
} );
```

### dscc_default_language

`apply_filters( 'dscc_default_language', string $lang ): string` — `src/Frontend/LanguageResolver.php`

Ngôn ngữ dùng cho mọi locale không phải tiếng Việt hay tiếng Anh. `$lang` mặc định là `advanced.default_lang`. Giá trị trả về khác `en` được coi là `vi`.

```php
add_filter( 'dscc_default_language', fn( $lang ) => 'en' );
```

### dscc_client_side_language

`apply_filters( 'dscc_client_side_language', bool $client ): bool` — `src/Frontend/LanguageResolver.php`

`true` khi cả hai ngôn ngữ được in trong HTML và trình duyệt chọn theo `<html lang>`. `$client` là `true` nếu `advanced.lang_source = client` hoặc plugin GTranslate đang bật. Dùng khi một công cụ dịch khác đổi `lang` ở phía trình duyệt.

```php
add_filter( 'dscc_client_side_language', function ( $client ) {
	return $client || class_exists( 'LittleD_Translator' );
} );
```

### dscc_admin_tabs

`apply_filters( 'dscc_admin_tabs', array $tabs ): array` — `src/Admin/SettingsPage.php`

Danh sách tab của màn hình Cài đặt: `slug => [ 'label' => string, 'render' => callable, 'save' => callable|null ]`. `render` tự in nội dung (kể cả form); `save` nhận mảng `dscc[]` đã bỏ slash, hoặc `null` nếu tab tự xử lý lưu. Nên dùng `SettingsPage::insert_tab()` để chèn trước tab Nâng cao.

```php
add_filter( 'dscc_admin_tabs', function ( $tabs ) {
	$tabs['littled'] = array(
		'label'  => 'LittleD',
		'render' => function () { echo '<p>LittleD</p>'; },
		'save'   => null,
	);
	return $tabs;
} );
```

## Danh mục và chặn script

### dscc_catalog_services

`apply_filters( 'dscc_catalog_services', array $items ): array` — `src/Frontend/ServiceCatalog.php`

Danh sách nền tảng **đã xác nhận** mà popup, bảng `[dscc_cookie_table]` và dữ liệu cho trình duyệt dùng. Plugin tự nạp danh mục của site ở ưu tiên 5; hook ở ưu tiên mặc định (10) trở đi để thêm hoặc sửa. Mỗi phần tử:

| Khoá | Ghi chú |
|---|---|
| `slug` | Bắt buộc; `a-z`, `0-9`, `_`, `-`, tối đa 40 ký tự; trùng hoặc sai bị bỏ |
| `name`, `category` | Bắt buộc; `category` là `necessary`, `functional`, `analytics` hoặc `marketing` (nhóm khác bị ẩn) |
| `privacy_url` | Liên kết chính sách |
| `blockable` | Mặc định `true`: nền tảng có công tắc riêng trong popup |
| `cookies[]` | `name`, `duration`, `purpose`, `domain`, `group`, `applies`; `name`/`duration`/`purpose`/`applies` là chuỗi hoặc `['vi' => …, 'en' => …]` |

Tối đa 60 nền tảng. Hook này chỉ ảnh hưởng **hiển thị**; luật chặn script được dựng từ danh mục trong cơ sở dữ liệu (xem [script-blocking.md](script-blocking.md)).

```php
add_filter( 'dscc_catalog_services', function ( $items ) {
	$items[] = array(
		'slug'        => 'littled-widget',
		'name'        => 'LittleD Widget',
		'category'    => 'marketing',
		'privacy_url' => 'https://example.com/privacy',
		'cookies'     => array(
			array(
				'name'     => 'littled_id',
				'duration' => array( 'vi' => '30 ngày', 'en' => '30 days' ),
				'purpose'  => array( 'vi' => 'Nhận diện trình duyệt', 'en' => 'Identifies the browser' ),
			),
		),
	);
	return $items;
} );
```

### dscc_blocker_enabled

`apply_filters( 'dscc_blocker_enabled', bool $enabled = true ): bool` — `src/Blocker/OutputBuffer.php`

Tắt riêng **bộ chặn đầu ra** (viết lại HTML và bộ lọc cookie server) ở một yêu cầu, mà vẫn in banner. Chạy sau các điều kiện cố định (không phải trang front-end, xem trước, trackback, favicon, yêu cầu HEAD).

```php
add_filter( 'dscc_blocker_enabled', function ( $enabled ) {
	return isset( $_GET['littled_raw'] ) ? false : $enabled; // phpcs:ignore WordPress.Security.NonceVerification
} );
```

### dscc_blocker_skip_tag

`apply_filters( 'dscc_blocker_skip_tag', bool $skip, string $kind, array $attrs, string $src ): bool` — `src/Blocker/OutputBuffer.php`

Cho phép giữ **một thẻ** khỏi bộ chặn. `$kind` là `script`, `iframe` hoặc `img`; `$attrs` là thuộc tính theo tên viết thường, mỗi phần tử dạng `[ 'name', 'value', 'start', 'end' ]`; `$src` là địa chỉ. Chỉ được gọi cho thẻ đã khớp một luật. Đăng ký với 4 tham số.

```php
add_filter( 'dscc_blocker_skip_tag', function ( $skip, $kind, $attrs, $src ) {
	if ( 'script' === $kind && false !== strpos( $src, 'cdn.example.com/littled-player.js' ) ) {
		return true;
	}
	return $skip;
}, 10, 4 );
```

### dscc_blocker_slow_ms

`apply_filters( 'dscc_blocker_slow_ms', int $ms = 30 ): int` — `src/Blocker/OutputBuffer.php`, `src/Blocker/PerfMonitor.php`

Ngưỡng (mili giây) mà vượt qua thì xử lý trang bị ghi là chậm và hiện cảnh báo ở tab Chặn script.

```php
add_filter( 'dscc_blocker_slow_ms', fn() => 60 );
```

### dscc_cookie_safelist

`apply_filters( 'dscc_cookie_safelist', array $patterns ): array` — `src/Blocker/CookieSafelist.php`

Thêm tên cookie hoặc mẫu `*` mà bộ lọc server và bộ xoá cookie **không bao giờ** động tới. Phần tử phải qua cùng kiểm tra với ô *Cookie luôn giữ* (3 đến 64 ký tự an toàn, mẫu chỉ có `*` bị bỏ).

```php
add_filter( 'dscc_cookie_safelist', function ( $patterns ) {
	$patterns[] = 'littled_session';
	$patterns[] = 'littled_cart_*';
	return $patterns;
} );
```

### dscc_catalog_changed

`do_action( 'dscc_catalog_changed' )` — `src/Catalog/CookieRepository.php`, `src/Catalog/ServiceRepository.php`, `src/Admin/QuickSetup.php`

Phát sau khi danh mục nền tảng/cookie thay đổi (tạo, sửa, xoá, đổi nhóm, phân loại nhanh). Plugin dùng nó để huỷ cache danh mục hiển thị và luật chặn. Dùng để xoá cache riêng của bạn có đọc danh mục. Lưu ý: cập nhật chỉ số thống kê của quét không phát hook này.

```php
add_action( 'dscc_catalog_changed', function () {
	delete_transient( 'littled_cookie_policy_cache' );
} );
```

## Quét và nhận diện

### dscc_scan_urls

`apply_filters( 'dscc_scan_urls', string[] $urls ): string[]` — `src/Scanner/UrlCollector.php`

Danh sách trang của quét máy chủ, theo thứ tự ưu tiên (trang chủ trước). Thêm trang đặc biệt hoặc bớt trang. Sau hook, địa chỉ được chuẩn hoá, bỏ trùng, cắt theo `max_urls`, và **mỗi địa chỉ phải thuộc host của chính site** trước khi được tải.

```php
add_filter( 'dscc_scan_urls', function ( $urls ) {
	$urls[] = home_url( '/littled-landing/' );
	return $urls;
} );
```

### dscc_scan_own_hosts

`apply_filters( 'dscc_scan_own_hosts', string[] $hosts ): string[]` — `src/Scanner/DeepScanIngest.php`

Host được coi là *chính site này* khi kiểm tra `site` trong báo cáo quét sâu (mặc định: host của địa chỉ nhà và địa chỉ site, kèm biến thể `www.`). Dùng khi container quét vào site bằng tên nội bộ.

```php
add_filter( 'dscc_scan_own_hosts', function ( $hosts ) {
	$hosts[] = 'web.internal';
	return $hosts;
} );
```

### dscc_scan_request_args

`apply_filters( 'dscc_scan_request_args', array $args ): array` — `src/Scanner/ServerScanner.php`

Tham số `wp_remote_get()` của yêu cầu quét máy chủ: `timeout` (15), `redirection` (0, plugin tự theo chuyển hướng), `user-agent`, `headers`, `cookies` (rỗng), `limit_response_size` (2 MB). Ví dụ thêm xác thực cơ bản cho staging:

```php
add_filter( 'dscc_scan_request_args', function ( $args ) {
	$args['headers']['Authorization'] = 'Basic ' . base64_encode( 'littled:example' ); // staging
	return $args;
} );
```

### dscc_scan_user_agent

`apply_filters( 'dscc_scan_user_agent', string $ua ): string` — `src/Scanner/ServerScanner.php`

User-Agent của yêu cầu quét (mặc định là một chuỗi trình duyệt Chrome trên Windows). Dùng khi tường lửa chặn UA mặc định.

```php
add_filter( 'dscc_scan_user_agent', fn( $ua ) => 'LittleD-Scan/1.0 ' . $ua );
```

### dscc_scan_finished

`do_action( 'dscc_scan_finished', int $run_id, array $summary, array $report )` — `src/Scanner/ScanRunner.php`

Phát khi một lượt **quét máy chủ** kết thúc (cả khi thất bại: `summary['message'] === 'no_page_loaded'`). `$summary` có các khoá như `total`, `done`, `failed`, `cookies`, `new_cookies`, `new_platforms`; `$report` là báo cáo đã lưu cùng lượt quét. Không phát cho quét sâu.

```php
add_action( 'dscc_scan_finished', function ( $run_id, $summary, $report ) {
	if ( ! empty( $summary['new_cookies'] ) ) {
		error_log( 'LittleD: ' . (int) $summary['new_cookies'] . ' new cookies in scan ' . (int) $run_id );
	}
}, 10, 3 );
```

### dscc_policy_page_text

`apply_filters( 'dscc_policy_page_text', string $text, int $post_id ): string` — `src/Admin/PolicyPageText.php`

Văn bản của trang Chính sách Cookie mà báo cáo dùng để tìm cookie "có trên site nhưng không có trong trang chính sách". `$text` là nội dung đã bỏ thẻ HTML (kể cả kết quả `[dscc_cookie_table]` và dữ liệu Elementor). Dùng khi chính sách nằm ở nơi khác (ACF, trình dựng trang riêng).

```php
add_filter( 'dscc_policy_page_text', function ( $text, $post_id ) {
	return $text . ' ' . wp_strip_all_tags( (string) get_post_meta( $post_id, 'littled_policy_body', true ) );
}, 10, 2 );
```

## Cập nhật và hạ tầng

### dscc_update_repo

`apply_filters( 'dscc_update_repo', string $repo ): string` — `src/Update/ReleaseFeed.php`

Repo GitHub dạng `chủ/tên` mà bộ cập nhật đọc Releases. Giá trị vào là hằng `DSCC_UPDATE_REPO` hoặc repo mặc định. Giá trị sai định dạng tắt bộ cập nhật.

```php
add_filter( 'dscc_update_repo', fn() => 'littled-org/ds-cookie-consent-fork' );
```

### dscc_github_token

`apply_filters( 'dscc_github_token', string $token ): string` — `src/Update/ReleaseFeed.php`

Token API tuỳ chọn (hằng `DSCC_GITHUB_TOKEN`). Repo công khai không cần; chỉ dùng để tăng giới hạn gọi API hoặc cho bản fork riêng tư. Token chỉ được đặt trong header gửi tới `api.github.com`. Đọc token từ nơi bảo mật của bạn, không viết thẳng vào mã.

```php
add_filter( 'dscc_github_token', function ( $token ) {
	return getenv( 'LITTLED_GH_TOKEN' ) ?: $token;
} );
```

### dscc_known_db_url

`apply_filters( 'dscc_known_db_url', string $url ): string` — `src/Catalog/KnownDbUpdater.php`

URL https của `known-cookies.json` cho nút *Cập nhật danh mục cookie*. Giá trị vào là `advanced.known_db_url`. Cần file `.sha256` cùng thư mục hoặc trường `sha256` trong JSON.

```php
add_filter( 'dscc_known_db_url', fn() => 'https://example.com/dscc/known-cookies.json' );
```

### dscc_client_ip

`apply_filters( 'dscc_client_ip', string $ip ): string` — `src/Consent/LogEndpoint.php`

IP khách sau khi áp dụng `advanced.trusted_ip_header`. Dùng để che IP trong log và giới hạn tần suất (log và beacon). Chỉ trả IP bạn tin cậy; hàm sau đó vẫn che địa chỉ trước khi lưu.

```php
add_filter( 'dscc_client_ip', function ( $ip ) {
	return ! empty( $_SERVER['HTTP_X_LITTLED_CLIENT_IP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_LITTLED_CLIENT_IP'] ) ) : $ip;
} );
```

### dscc_integration_detected

`apply_filters( 'dscc_integration_detected', bool $detected, string $slug ): bool` — `src/Integrations/Registry.php`

Ghi đè kết quả nhận diện plugin tích hợp. `$slug` là `meta-pixel`, `pixelyoursite`, `site-kit`, `wp-consent-api`, `litespeed-cache` hoặc `wp-rocket`. Chủ yếu dùng trong kiểm thử; dùng cẩn thận ở site thật vì tích hợp sẽ đăng ký hook của plugin không có mặt.

```php
add_filter( 'dscc_integration_detected', function ( $detected, $slug ) {
	return 'wp-rocket' === $slug ? false : $detected;
}, 10, 2 );
```

## Hook cron và AJAX do plugin đăng ký

Không phải điểm mở rộng, nhưng hữu ích khi gỡ lỗi: sự kiện cron `dscc_cleanup_log` (dọn log hằng ngày theo `retention_months`), `dscc_scan_weekly` (quét tuần), `dscc_scan_tick` (lô quét kế tiếp); điểm vào công khai `admin-ajax.php?action=dscc_log` và `action=dscc_beacon`; route REST `POST /wp-json/dscc/v1/log`, `/beacon` (công khai, có bảo vệ) và `/scan-result` (cần quyền `manage_options`).
