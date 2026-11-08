# Cài đặt

Hướng dẫn cài DS Cookie Consent trên một site WordPress, những gì kích hoạt tạo ra và cách gỡ.

## Yêu cầu

- WordPress 6.0 trở lên, PHP 7.4 trở lên (header plugin: `Requires at least: 6.0`, `Requires PHP: 7.4`).
- Gói phát hành đã chứa mọi thư viện cần chạy (thư mục `vendor-prefixed/`), nên **không** cần Composer hay Node trên site thật.
- WP-Cron hoạt động nếu bạn dùng quét tuần tự động và quét máy chủ chạy nền.

## Cài từ gói phát hành

1. Vào trang [Releases](https://github.com/D-SOLUTIONS-TECHNOLOGY-MEDIA-CO-LTD/ds-cookie-consent/releases), tải file `ds-cookie-consent.zip` của bản mới nhất (không tải "Source code").
2. Trong WordPress: **Plugin > Cài mới > Tải plugin lên**, chọn file zip, bấm **Cài đặt ngay**, rồi **Kích hoạt**.
3. Cách khác: giải nén zip, chép thư mục `ds-cookie-consent` vào `wp-content/plugins/`, rồi kích hoạt ở trang Plugin. Với WP-CLI: `wp plugin install ./ds-cookie-consent.zip --activate`.

Gói zip chỉ có đúng một thư mục gốc `ds-cookie-consent/`. Đừng cài từ nút "Download ZIP" của GitHub: gói đó là mã nguồn, thiếu thư viện và JS/CSS đã đóng gói.

## Kích hoạt tạo ra những gì

Hàm kích hoạt (`src/Activator.php`) làm các việc sau, an toàn khi chạy lại:

- Tạo bốn bảng với tiền tố bảng của site: `dscc_consent_log`, `dscc_services`, `dscc_cookies`, `dscc_scan_runs`.
- Cấp quyền `dscc_view_log` (xem log và tổng quan) cho vai trò quản trị viên. Màn hình **Cài đặt** cần quyền `manage_options`.
- Lưu cài đặt mặc định (xem [configuration.md](configuration.md)).
- Lên lịch dọn log hằng ngày.

Chưa có nền tảng hay cookie nào được nạp sẵn. Danh mục cookie chỉ được nạp khi bạn tick nền tảng hoặc chủ động nhập một bộ mẫu.

Sau này, mỗi lần cập nhật plugin, phần nâng cấp cơ sở dữ liệu chạy ở yêu cầu đầu tiên tới site, không cần quản trị viên mở trang admin.

## Menu sau khi cài

Menu **Cookie Consent** gồm:

| Mục | Quyền cần | Nội dung |
|---|---|---|
| Tổng quan | `dscc_view_log` | Số cookie, lần quét gần nhất, cảnh báo tuân thủ, thống kê log |
| Cài đặt | `manage_options` | Các tab cấu hình, xem [configuration.md](configuration.md) |
| Log | `dscc_view_log` | Nhật ký lựa chọn của khách, lọc và xuất CSV |

Có thêm lối tắt **Cài đặt > Cookie Consent** và liên kết *Cài đặt* ở dòng plugin.

## Thiết lập lần đầu

1. **Giao diện**: chọn kiểu *Gọn* hoặc *Thanh ngang*, màu và logo.
2. **Nội dung**: chọn trang Chính sách Cookie (và Chính sách Bảo mật nếu có).
3. **Nền tảng**: tick các nền tảng site đang dùng. Xem [platforms-and-cookies.md](platforms-and-cookies.md).
4. **Chặn script**: mặc định là chặn. Trên site đã có lưu lượng thật, nên đặt **Chỉ quan sát** một tuần rồi mới chuyển lại. Xem [script-blocking.md](script-blocking.md).
5. **Cookie**: bấm **Quét ngay** và phân loại cookie mới. Xem [scanner-and-compliance.md](scanner-and-compliance.md).
6. Dán `[dscc_cookie_table]` vào trang Chính sách Cookie. Xem [shortcodes-and-js-api.md](shortcodes-and-js-api.md).
7. Kiểm tra trong cửa sổ ẩn danh: banner hiện, chưa có cookie phân tích/tiếp thị trước khi đồng ý, chọn lại được qua widget.

Nếu site dùng plugin cache hoặc tối ưu (LiteSpeed Cache, WP Rocket...), đọc thêm [faq.md](faq.md).

## Cập nhật

Plugin tự kiểm tra GitHub Releases và hiện bản mới ở trang Plugin như mọi plugin khác. Chi tiết, hằng cấu hình và giới hạn: [release-and-updates.md](release-and-updates.md). Cập nhật thủ công: tải zip mới và cài đè qua **Tải plugin lên**; dữ liệu được giữ.

## Tắt và gỡ cài đặt

- **Tắt plugin** chỉ dừng các tác vụ lên lịch; dữ liệu được giữ.
- **Xoá plugin** (`uninstall.php`) luôn xoá các tuỳ chọn (cài đặt, chữ tuỳ chỉnh, cache, báo cáo quan sát, cảnh báo), tác vụ lên lịch, file cơ sở dữ liệu cookie đã tải về trong `uploads/dscc/` và quyền `dscc_view_log`.
- Bốn bảng dữ liệu (gồm **log đồng ý**) chỉ bị xoá khi bạn đã bật **Nâng cao > Xoá dữ liệu khi gỡ plugin** trước khi xoá. Mặc định tắt: gỡ plugin rồi cài lại sẽ không mất log.

Nếu cần giữ bằng chứng đồng ý, hãy xuất CSV ở trang Log trước khi xoá.
