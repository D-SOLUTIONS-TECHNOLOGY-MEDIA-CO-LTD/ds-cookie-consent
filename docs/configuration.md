# Cấu hình

Cài đặt nằm ở **Cookie Consent > Cài đặt**, mỗi tab một nhóm. Tài liệu này liệt kê từng tuỳ chọn, khoá lưu trữ và giá trị mặc định. Nguồn gốc duy nhất của khoá, kiểu và mặc định là [`src/Settings/SettingsFields.php`](../src/Settings/SettingsFields.php); nếu bảng dưới khác code, code đúng.

Mọi cài đặt lưu trong một option `dscc_settings`, chia theo nhóm (`appearance`, `content`, `blocking`, `scanner`, `advanced`). Quy tắc chung:

- Giá trị sai kiểu hoặc ngoài khoảng được kẹp về khoảng cho phép hoặc giữ giá trị cũ; mỗi tab hiện thông báo khi một giá trị bị từ chối (CSS, tên cookie, vùng, URL cơ sở dữ liệu).
- Ô tick (bool) không gửi lên nghĩa là tắt.
- Danh sách theo dòng: mỗi dòng một mục, dòng không hợp lệ bị bỏ, mục trùng được gộp.
- Có thể chỉnh mặc định bằng bộ lọc [`dscc_settings_defaults`](developer-hooks.md#dscc_settings_defaults).

## Giao diện (`appearance`)

| Khoá | Mặc định | Ý nghĩa |
|---|---|---|
| `layout` | `card` | `card` = **Gọn** (hộp nhỏ ở góc), `bar` = **Thanh ngang** (kèm popup ba tab) |
| `logo_id` | `0` | Logo banner (thư viện media). Để trống thì dùng logo của site. PNG, WebP hoặc SVG; SVG được làm sạch khi tải lên |
| `logo_popup_id` | `0` | Logo popup. Để trống thì dùng logo banner |
| `logo_height` | `30` | Chiều cao logo banner, 12 đến 120 px |
| `logo_popup_height` | `22` | Chiều cao logo popup, 12 đến 120 px |
| `color_primary` | `#005ea3` | Màu chính: nút Đồng ý, toggle bật, link, tab đang chọn |
| `color_primary_hover` | trống | Màu chính khi rê chuột; trống = tự tính (tối hơn 12%) |
| `color_text` | `#1e3a5f` | Màu chữ banner |
| `color_bar_bg` | `#f1f5f9` | Nền banner |
| `color_reject_bg` | trống | Nền nút Từ chối; trống = tự tính từ màu chính (pha 12% với trắng) |
| `color_modal_bg` | `#f6f6f6` | Nền popup |
| `font` | `inherit` | `inherit` để theo theme, hoặc tên font đã có sẵn trên site. Chỉ chữ, số, khoảng trắng, dấu phẩy, `-`, `_`; tối đa 100 ký tự |
| `button_radius` | trống | Bo góc nút; trống = tự động (thanh ngang: pill, hộp gọn: 8 px). Nhập `pill` hoặc số 0 đến 40 |
| `button_order` | trống | Thứ tự nút: trống = theo kiểu giao diện (Gọn: Đồng ý đứng cuối; Thanh ngang: Đồng ý đứng đầu), `accept_first`, `accept_last` |
| `customize_style` | `link` | Nút Tuỳ chỉnh dạng `link` hoặc `button` |
| `card_position` | `bottom_left` | Vị trí hộp kiểu Gọn: `bottom_left`, `bottom_right`, `bottom_center` |
| `widget_enabled` | bật | Hiện icon khiên thu nhỏ sau khi khách chọn. Kiểu Gọn luôn hiện icon vì đây là lối duy nhất để khách đổi lựa chọn |
| `widget_side` | `left` | Góc của icon: `left` hoặc `right` |
| `widget_bottom` | `20` | Cách mép dưới, 0 đến 400 px |
| `widget_offset_x` | `20` | Cách mép ngang, 0 đến 400 px |
| `widget_show` | `always` | Icon hiện khi nào: `always` (sau mọi lựa chọn) hoặc `after_reject` (chỉ sau Từ chối, kiểu thanh ngang) |
| `widget_label` | trống | Chữ khi rê chuột vào icon, tối đa 40 ký tự; trống = chữ mặc định (tab Nội dung) |
| `overlay_opacity` | `35` | Lớp phủ tối khi mở popup, 0 đến 60 (%) |
| `per_service_toggle` | bật | Cho khách bật/tắt từng nhà cung cấp trong popup; chỉ có tác dụng khi danh mục có nhà cung cấp |
| `custom_css` | trống | CSS tuỳ chỉnh, tối đa 20.000 ký tự. Mọi selector được tự thêm tiền tố `#dscc`; không cho `@import`, `url(javascript:)`, `expression()`; ngoặc không cân bị từ chối |

Màn hình có **xem trước trực tiếp** (desktop và mobile 390 px) và cảnh báo khi độ tương phản dưới 4,5:1 (WCAG AA) cho chữ trắng trên màu chính hoặc chữ banner trên nền banner.

## Nội dung (`content`)

| Khoá | Mặc định | Ý nghĩa |
|---|---|---|
| `cookie_policy_page_id` | `0` | Trang Chính sách Cookie. Được ưu tiên hơn URL |
| `cookie_policy_url` | trống | URL Chính sách Cookie (http/https hoặc đường dẫn bắt đầu bằng `/`) |
| `privacy_page_id` | `0` | Trang Chính sách Bảo mật |
| `privacy_url` | trống | URL Chính sách Bảo mật. Không cấu hình thì tab *Chính sách bảo mật* không hiện trong popup |
| `policy_embed_mode` | `iframe` | `iframe`: nhúng trang (chỉ trang cùng tên miền) vào popup; `link`: chỉ hiện nút mở trang |

Trang nhúng được tải với tham số `?dscc_embed=1`: theme bị ẩn header/footer và trang được đánh dấu noindex.

**Chữ hiển thị cho khách**: hai ô riêng cho tiếng Việt và tiếng Anh (lưu ở option `dscc_texts_vi` và `dscc_texts_en`). Để trống = dùng chữ mặc định (hiện mờ trong ô); mỗi chữ tối đa 800 ký tự; chữ trùng mặc định không được lưu. Dùng được `{site}` (tên site) và `{policy_link}` (liên kết Chính sách Cookie). Nút **Khôi phục chữ mặc định** xoá phần ghi đè. Danh sách khoá chữ nằm ở [`src/Settings/DefaultTexts.php`](../src/Settings/DefaultTexts.php).

## Nền tảng và Cookie

Hai tab này quản lý danh mục chứ không nằm trong `dscc_settings`: xem [platforms-and-cookies.md](platforms-and-cookies.md).

## Chặn script (`blocking`)

Giải thích hành vi ở [script-blocking.md](script-blocking.md).

| Khoá | Mặc định | Ý nghĩa |
|---|---|---|
| `mode` | `block` | `block` = chặn trước khi có đồng ý; `observe` = chỉ quan sát (không đổi HTML, không xoá cookie, tích hợp không chặn gì) |
| `gtm_mode` | `consent_mode` | `consent_mode` (không chặn GTM) hoặc `block` (chỉ tải GTM khi khách đồng ý Phân tích hoặc Tiếp thị) |
| `exclusions` | trống | Danh sách loại trừ: mỗi dòng một đoạn chữ (so khớp trong thẻ script/iframe) hoặc regex viết trong `/.../`. Tối đa 50 dòng, mỗi dòng 3 đến 200 ký tự |
| `block_unclassified` | bật | Chặn như nhóm Tiếp thị các dịch vụ bên thứ ba đã phát hiện nhưng chưa phân loại; gồm cả cookie mới do quét thấy |
| `unclassified_cookie_mode` | `report` | Cookie lạ do server ghi: `report` (chỉ báo cáo) hoặc `block` (chặn như Tiếp thị tới khi phân loại) |
| `keep_cookies` | trống | Cookie luôn giữ: tên hoặc mẫu có `*`. Tối đa 30 dòng, mỗi dòng 3 đến 64 ký tự (chữ, số, `_ . - % *`) |
| `reload_on_withdraw` | bật | Tải lại trang sau khi khách rút lại đồng ý, nếu đã có script chạy |
| `meta_hold` | bật | Giữ plugin Meta Pixel for WordPress ở trạng thái "hold" tới khi khách đồng ý Tiếp thị (chỉ có tác dụng khi plugin đó bật) |

Phần **Nền tảng đang được chặn**, **Báo cáo chế độ quan sát** và **Tích hợp** ở cuối tab chỉ hiển thị, không có cài đặt.

## Quét cookie (`scanner`)

Giải thích ở [scanner-and-compliance.md](scanner-and-compliance.md).

| Khoá | Mặc định | Ý nghĩa |
|---|---|---|
| `auto_scan` | `off` | `off` hoặc `weekly` (WP-Cron mỗi tuần một lần) |
| `max_urls` | `50` | Số trang tối đa mỗi lượt quét, 1 đến 200 |
| `beacon_enabled` | tắt | Thu thập thụ động từ khách (beacon) |
| `beacon_sample` | `5` | Tỉ lệ lấy mẫu, 0 đến 100 (%) |
| `notify_enabled` | bật | Gửi email khi quét phát hiện cookie mới (tối đa một email mỗi ngày) |
| `notify_email` | trống | Người nhận; trống = email quản trị của site |

## Nhập / Xuất

Không có cài đặt riêng. **Tải cấu hình** xuất file JSON gồm cài đặt, chữ tuỳ chỉnh, nền tảng và cookie; **Nhập** nhận file hoặc bộ mẫu, luôn qua màn hình xem trước. Xem [platforms-and-cookies.md](platforms-and-cookies.md#xuất-nhập-và-bộ-mẫu).

## Nâng cao (`advanced`)

| Khoá | Mặc định | Ý nghĩa |
|---|---|---|
| `policy_version` | tháng cài đặt (`Y-m`) | Phiên bản chính sách, 1 đến 20 ký tự (chữ, số, `. _ -`). Đổi giá trị hoặc bấm *Yêu cầu tất cả khách chọn lại* để mọi khách thấy lại banner |
| `consent_days` | `180` | Thời hạn lưu lựa chọn, 1 đến 730 ngày |
| `cookie_name` | `dscc_consent` | Tên cookie lưu lựa chọn: chữ, số, `_`, `-`; tối đa 32 ký tự. Tên không hợp lệ giữ tên cũ để không hỏi lại khách |
| `cookie_domain` | trống | Trống = host hiện tại; đặt `.example.com` để dùng chung giữa các subdomain |
| `default_lang` | `vi` | Ngôn ngữ cho mọi locale không phải tiếng Việt hay tiếng Anh: `vi` hoặc `en` |
| `lang_source` | `server` | `server` (WordPress, WPML, Polylang) hoặc `client` (thuộc tính `lang` của trang, cho GTranslate). Khi GTranslate đang bật, chế độ `client` được dùng tự động |
| `skip_ua_patterns` | trống | Bỏ qua banner theo User-Agent: mỗi dòng một regex (không phân biệt hoa thường, tối đa 100 ký tự). Tối đa 30 dòng |
| `skip_ua_mode` | `deny` | Khi User-Agent khớp: `deny` (như bot: ẩn banner, giữ script bị chặn, hiện lại video và bản đồ nhúng) hoặc `grant` (chạy mọi script như khi bấm Đồng ý tất cả; không ghi cookie, không ghi log). Chỉ chọn `grant` khi ứng dụng chủ đã tự hỏi đồng ý |
| `skip_bots` | bật | Bỏ qua bot tìm kiếm (Googlebot, Bingbot...): không thấy banner, không ghi log. Lighthouse không nằm trong danh sách này |
| `hide_selectors` | trống | CSS selector của widget bên thứ ba bị ẩn khi banner mở (mỗi dòng một selector, tối đa 20 dòng, 100 ký tự). Widget chat Pancake và Zalo được thêm tự động khi nền tảng đã tick |
| `raise_widget` | bật | Tự đẩy icon thu nhỏ lên trên các phần tử ở danh sách trên nếu chiếm cùng góc |
| `retention_months` | `24` | Giữ log bao nhiêu tháng, 0 đến 120; `0` = không tự xoá |
| `delete_data_on_uninstall` | tắt | Xoá cả bốn bảng dữ liệu khi gỡ plugin |
| `show_functional` | tắt | Hiện nhóm cookie *Chức năng* riêng (mặc định gộp vào Cần thiết) |
| `trusted_ip_header` | `none` | Nguồn IP của khách: `none` (kết nối trực tiếp), `cf-connecting-ip`, `x-forwarded-for` (IP đầu tiên). Chỉ chọn header khi site thật sự nằm sau proxy đó, vì header có thể bị giả |
| `consent_regions` | trống | Vùng mà Consent Mode mặc định `denied`; trống = mọi vùng. Mỗi dòng một mã ISO 3166 (`VN`, `US-CA`); tối đa 40 vùng và 140 ký tự gộp, phần thừa bị bỏ |
| `url_passthrough` | tắt | Bật `url_passthrough` của Consent Mode: giữ thông tin quảng cáo trong URL khi chưa có đồng ý |
| `known_db_url` | trống | URL https của `known-cookies.json` để cập nhật danh mục (có file `.sha256` cùng thư mục). URL không phải https bị từ chối |

Hằng số trong `wp-config.php` (không nằm trong màn hình cài đặt): `DSCC_UPDATE_REPO`, `DSCC_GITHUB_TOKEN`, xem [release-and-updates.md](release-and-updates.md).

## Dữ liệu gắn với site, không đi theo file xuất

Khi xuất cấu hình, các giá trị gắn với một site bị loại để không sao chép nhầm: id logo, id trang chính sách, phiên bản chính sách, email nhận cảnh báo, nguồn IP và tuỳ chọn xoá dữ liệu khi gỡ plugin. Log đồng ý không bao giờ nằm trong file xuất.
