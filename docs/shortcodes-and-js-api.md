# Shortcode, JavaScript API và sự kiện

Dành cho người làm nội dung (shortcode) và lập trình viên giao diện (API, sự kiện). Mã nguồn: [`src/Frontend/Shortcodes.php`](../src/Frontend/Shortcodes.php), [`src/Frontend/CookieTableShortcode.php`](../src/Frontend/CookieTableShortcode.php), [`assets/src/js/main.js`](../assets/src/js/main.js), [`assets/src/js/consent-mode.js`](../assets/src/js/consent-mode.js).

## Shortcode

### `[dscc_cookie_settings]`

In một liên kết mở lại popup lựa chọn cookie (kết quả giống icon khiên).

| Thuộc tính | Mặc định | Ý nghĩa |
|---|---|---|
| `text` | trống | Nhãn liên kết, một chữ dùng cho mọi ngôn ngữ. Trống = nhãn *Chữ khi rê chuột vào icon* (`widget_label`) nếu có, nếu không là chữ mặc định theo ngôn ngữ |

```
[dscc_cookie_settings]
[dscc_cookie_settings text="Cài đặt cookie"]
```

Kết quả là `<a href="#cai-dat-cookie" data-dscc-open>…</a>`. Khi site dùng ngôn ngữ phía trình duyệt (GTranslate hoặc `lang_source = client`), cả hai ngôn ngữ được in và trình duyệt chọn theo `<html lang>`, nên HTML vẫn giống nhau cho mọi khách.

Cách mở popup mà không cần shortcode, ở bất cứ đâu trên trang:

- liên kết có `href` kết thúc bằng `#cai-dat-cookie` hoặc `#cookie-settings`;
- bất kỳ phần tử nào có thuộc tính `data-dscc-open`.

Nút trong khung thay thế của iframe bị chặn (`data-dscc-enable`) do plugin tự in; nó cho phép riêng nền tảng đó, không mở popup.

### `[dscc_cookie_table]`

In bảng cookie cho trang Chính sách Cookie, lấy từ danh mục đã xác nhận (xem [platforms-and-cookies.md](platforms-and-cookies.md)). Mỗi nhóm một tiêu đề và một bảng gồm các cột *Cookie | Bên đặt | Mục đích | Thời hạn | Áp dụng tại*. Cookie chưa phân loại và cookie bị bỏ qua không xuất hiện.

| Thuộc tính | Mặc định | Giá trị |
|---|---|---|
| `category` | trống (mọi nhóm) | `necessary`, `functional`, `analytics`, `marketing`; nhiều nhóm cách nhau bằng dấu phẩy. Khi nhóm Chức năng bị tắt riêng (`show_functional`), `functional` được gộp vào `necessary` |
| `columns` | `name,service,purpose,duration,applies` | Chọn và sắp lại các cột; giá trị lạ bị bỏ, rỗng thì dùng đủ năm cột |
| `lang` | ngôn ngữ hiện tại | `vi` hoặc `en` |
| `heading` | `1` | `0` để ẩn tiêu đề nhóm |

```
[dscc_cookie_table]
[dscc_cookie_table category="analytics,marketing" columns="name,purpose,duration" lang="en" heading="0"]
```

Tên nhà cung cấp liên kết tới chính sách của họ (mở tab mới, `rel="nofollow noopener noreferrer"`). Cookie cùng *nhóm dòng* hoặc cùng nhà cung cấp và mục đích gộp một dòng. Nếu nhóm không có cookie, bảng hiện thông báo trống. Bảng có class `dscc-cookie-table` để bạn tạo kiểu.

### `[dscc_last_scan]`

In ngày cập nhật danh sách cookie: ngày quét gần nhất, nếu chưa quét thì ngày thay đổi mới nhất của danh mục đã xác nhận; trống khi không có cả hai.

| Thuộc tính | Mặc định | Ý nghĩa |
|---|---|---|
| `format` | `d/m/Y` | Định dạng ngày kiểu PHP (`wp_date`) |

```
Cập nhật lần cuối: [dscc_last_scan format="d/m/Y"]
```

## `window.DSCC`

Đối tượng công khai do script chính tạo ra. Với khách bị bỏ qua theo User-Agent (bot, webview ứng dụng) banner bị gỡ và `window.DSCC` không được tạo.

| Phương thức | Trả về | Mô tả |
|---|---|---|
| `DSCC.open(tab)` | – | Mở popup. `tab` là `'prefs'` (mặc định), `'cookies'` hoặc `'privacy'`; kiểu Gọn mở khung tương ứng |
| `DSCC.get()` | `null` hoặc `{ analytics, marketing, services }` | Lựa chọn hiện tại. `services` là bản đồ `slug: boolean`. `null` khi khách chưa chọn |
| `DSCC.has(name)` | `boolean` | `name` là `'necessary'` (luôn `true`), tên nhóm (`'analytics'`, `'marketing'`, `'functional'`) hoặc slug nền tảng. Nền tảng không có công tắc riêng theo nhóm của nó. Chưa chọn thì `false` |
| `DSCC.reset()` | – | Xoá lựa chọn, ghi log một lần rút lại đồng ý (nguồn `api`), phát `dscc:consent` với mọi nhóm tắt, xoá cookie đã biết của nhóm bị tắt, tải lại trang nếu đã có script chạy (theo `reload_on_withdraw`) và hiện lại banner |

```js
if (window.DSCC && window.DSCC.has('analytics')) {
  // đã được phép
}
document.querySelector('#nut-cookie').addEventListener('click', () => window.DSCC && DSCC.open());
```

`window.DSCC` có thể không tồn tại (khách bị bỏ qua, script chưa tải xong, lỗi tải): luôn kiểm tra trước khi gọi.

## Sự kiện DOM

Phát trên `document`, kèm `detail`.

| Sự kiện | Khi nào | `detail` |
|---|---|---|
| `dscc:consent` | Mỗi lần khách chọn (Đồng ý, Từ chối, Lưu lựa chọn) hoặc rút lại | `{ analytics: boolean, marketing: boolean, services: {slug: 0/1}, id: string, v: string }` (`id` là mã lựa chọn, `v` là phiên bản chính sách) |
| `dscc:service-enabled` | Một nền tảng vừa được phép trong lần xem trang này (có hoặc không có thẻ bị chặn) | `{ service: slug }` |

```js
document.addEventListener('dscc:consent', (e) => {
  console.log(e.detail.analytics, e.detail.marketing);
});
document.addEventListener('dscc:service-enabled', (e) => {
  if (e.detail.service === 'littled-widget') initWidget();
});
```

`dscc:service-enabled` phát lại khi nền tảng mất rồi lấy lại quyền; mỗi nền tảng một lần cho tới khi bị rút.

## `dataLayer` và Consent Mode

Script đầu trang chạy trước mọi thẻ (giới hạn 1,5 KB) nên Consent Mode luôn có giá trị mặc định trước khi GTM/gtag đọc.

**Mặc định** (`gtag('consent', 'default', …)`) tính từ cookie lựa chọn nếu có hợp lệ, nếu không thì từ chối:

| Tín hiệu | Giá trị |
|---|---|
| `analytics_storage` | `granted` nếu khách cho phép Phân tích, ngược lại `denied` |
| `ad_storage`, `ad_user_data`, `ad_personalization` | `granted` nếu khách cho phép Tiếp thị, ngược lại `denied` |
| `functionality_storage`, `security_storage` | luôn `granted` (nhóm Chức năng của plugin không đổi các giá trị này; `personalization_storage` không được plugin đặt) |
| `wait_for_update` | `500` (ms) |
| `region` | danh sách `consent_regions` nếu có; trống = áp dụng mọi vùng |

Thêm `gtag('set', 'ads_data_redaction', true)` khi Tiếp thị bị từ chối, và `gtag('set', 'url_passthrough', true)` khi bật `url_passthrough`.

**Cập nhật** (`gtag('consent', 'update', …)`) gửi ngay khi khách chọn với bốn tín hiệu `analytics_storage`, `ad_storage`, `ad_user_data`, `ad_personalization`, và đặt lại `ads_data_redaction` theo Tiếp thị. Khi tải trang với khách đã chọn, plugin gửi lại một lệnh update (không sự kiện, không log) để plugin khác in default muộn hơn (ví dụ Site Kit) không ghi đè.

**Sự kiện dataLayer** (cũng được đẩy khi tải trang với khách đã chọn; ví dụ khi khách cho phép Phân tích và từ chối Tiếp thị; `dscc_analytics`/`dscc_marketing` là boolean):

```js
{ event: 'dscc_consent_update', dscc_analytics: true, dscc_marketing: false, dscc_services: { 'google-analytics': 1, 'meta-pixel': 0 } }
```

Dùng làm trigger *Custom Event* `dscc_consent_update` trong GTM, và biến dataLayer `dscc_analytics`, `dscc_marketing`, `dscc_services`. Ngoài ra nếu có `window.fbq`, plugin gọi `fbq('consent', 'grant' | 'revoke')` theo Tiếp thị.

## Cookie lựa chọn

Cookie (tên `dscc_consent`, đổi được ở tab Nâng cao) chứa JSON mã hoá URL: `{ v, id, t, c, s, src }`.

| Khoá | Nội dung |
|---|---|
| `v` | Phiên bản chính sách tại thời điểm chọn. Khác phiên bản hiện tại nghĩa là hết hiệu lực |
| `id` | Mã lựa chọn ngẫu nhiên (cũng là khoá trong log) |
| `t` | Thời điểm chọn, giây Unix. Thiếu hoặc hết hạn (`consent_days`) thì coi như chưa chọn |
| `c` | Nhóm: `{ analytics: 0/1, marketing: 0/1, … }` |
| `s` | Nền tảng: `{ slug: 0/1 }`; bị bỏ nếu cookie vượt khoảng 1,9 KB |
| `src` | Nơi chọn: `banner`, `prefs`, `widget`, `api` |

Thuộc tính cookie: `Path=/`, `SameSite=Lax`, `Secure` trên https, `Max-Age` theo `consent_days`, `Domain` theo `cookie_domain` nếu đặt. Cookie này nằm trong danh sách giữ nguyên, không bao giờ bị lọc. Nên đọc lựa chọn bằng `DSCC.get()` thay vì tự phân tích cookie.

## Ghi log lựa chọn

Mỗi lựa chọn được gửi ẩn danh tới `admin-ajax.php?action=dscc_log` (`navigator.sendBeacon`, dự phòng `fetch`) và có thêm bản REST `POST /wp-json/dscc/v1/log`. Trường: `id`, `choice` (`accept_all`, `reject_all`, `custom`, `withdraw`), `c`, `s`, `v`, `lang`, `url` (chỉ đường dẫn), `src`. Server kiểm kích thước (4 KB), Origin/Referer cùng site, tính nhất quán giữa `choice` và các cờ, giới hạn 10 yêu cầu mỗi 60 giây cho mỗi IP đã che; IPv4 bị che octet cuối, IPv6 giữ /48. Không có giá trị cookie hay thông tin cá nhân trong log. Trang Log (quyền `dscc_view_log`) lọc theo ngày/lựa chọn và xuất CSV UTF-8 có BOM với các cột: `created_at_utc`, `choice`, `analytics`, `marketing`, `services`, `consent_id`, `policy_version`, `lang`, `page_url`, `ip_masked`, `user_agent`, `src`.
