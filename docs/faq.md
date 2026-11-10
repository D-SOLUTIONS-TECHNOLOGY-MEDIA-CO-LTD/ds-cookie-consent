# Câu hỏi thường gặp và xử lý sự cố

Mỗi mục: triệu chứng, nguyên nhân thường gặp, cách kiểm tra. Hành vi chi tiết nằm ở các tài liệu được dẫn.

## Banner

### Banner không hiện

Kiểm tra theo thứ tự:

1. **Bạn đã chọn rồi.** Banner chỉ hiện khi chưa có lựa chọn hợp lệ. Lựa chọn hết hiệu lực khi quá `consent_days` (mặc định 180 ngày) hoặc khi *Phiên bản chính sách* đổi. Thử cửa sổ ẩn danh, hoặc chạy `DSCC.reset()` trong console.
2. **Bạn là bot hoặc User-Agent nằm trong danh sách bỏ qua.** Xem [phần bot](#bot-user-agent-và-webview).
3. **Trang bị cache cũ.** Xoá cache trang/CDN sau khi bật plugin hoặc đổi cài đặt. Nếu trang cũ không có `<div id="dscc">` và `<script id="dscc-data">`, đó là HTML cũ.
4. **Ngữ cảnh không in banner.** Banner không in ở admin, AJAX, cron, REST, feed, embed, robots, xem trước Customizer, các trình dựng trang (Elementor, Divi, Bricks, Beaver Builder, Oxygen, Brizy, WPBakery, Thrive) và trang nhúng trong popup (`?dscc_embed=1`). Mã của site cũng có thể tắt qua bộ lọc [`dscc_should_render`](developer-hooks.md#dscc_should_render).
5. **Theme thiếu `wp_footer()`.** Banner được in ở `wp_footer` (ưu tiên 100); thiếu hook này thì không có banner.
6. **JavaScript bị chặn hoặc lỗi.** Mở DevTools: thẻ `<html>` có class `dscc-skip` nghĩa là khách bị bỏ qua; console có lỗi nghĩa là script chính không chạy; `window.DSCC` không tồn tại nghĩa là script chưa chạy hoặc khách bị bỏ qua.
7. **Plugin tối ưu gộp/hoãn script.** Plugin tự thêm `data-no-optimize`, `data-cfasync="false"` cho script của nó và với LiteSpeed Cache, WP Rocket thì tự đăng ký loại trừ. Nếu vẫn lỗi, loại trừ thủ công file `assets/dist/dscc.js` khỏi gộp/minify/hoãn.

### Banner hiện lại mãi dù đã chọn

- **Tên hoặc domain cookie đổi** (Nâng cao > Tên cookie lưu lựa chọn, Domain của cookie) làm cookie cũ không còn đọc được.
- **`www` và không `www`**: cookie host-only không dùng chung giữa hai host. Đặt *Domain của cookie* thành `.example.com` hoặc chuyển hướng về một host duy nhất.
- **Cookie bị trình duyệt từ chối**: trình duyệt hoặc tiện ích đang chặn cookie của site. Khi đó lựa chọn chỉ nhớ trong lần xem trang hiện tại.
- **Phiên bản chính sách bị đổi** (kể cả khi bạn bấm *Yêu cầu tất cả khách chọn lại*).
- Cookie quá lớn: danh sách nền tảng trong cookie bị bỏ khi vượt khoảng 1,9 KB, nhưng nhóm vẫn lưu được.

### Bot, User-Agent và webview

- Mặc định (`skip_bots`) Googlebot, Bingbot, Yandex, Baiduspider, DuckDuckBot, Slurp, facebookexternalhit và Applebot **không thấy banner** và không được ghi log. Với họ tracking vẫn bị chặn và video/bản đồ nhúng được hiện lại, nên SEO không bị ảnh hưởng.
- **Lighthouse/PageSpeed không nằm trong danh sách bot**: kiểm tra hiệu năng đo trang thật, có banner.
- Danh sách **Bỏ qua theo User-Agent** (Nâng cao): mỗi dòng một regex không phân biệt hoa thường. Hai chế độ khi khớp:
  - *Giống bot* (`deny`, mặc định): ẩn banner, giữ script bị chặn, hiện lại video và bản đồ.
  - *Ứng dụng chủ quản lý đồng ý* (`grant`): chạy mọi script như khi bấm Đồng ý tất cả, **không** ghi cookie lựa chọn và không ghi log. Chỉ chọn khi ứng dụng (ví dụ webview của app di động) đã tự hỏi đồng ý.
  - Bot tìm kiếm luôn theo cách *giống bot*, bất kể chế độ.

## Cache và tối ưu

### Plugin cache có làm sai kết quả chặn không?

Không với phần chặn script: HTML đã chặn giống hệt nhau cho mọi khách, trình duyệt mới quyết định bật lại, nên cache trang toàn phần an toàn. Giới hạn nằm ở **cookie do server ghi**: bộ lọc `Set-Cookie` chỉ hoạt động khi yêu cầu tới PHP. Trang đã nằm trong cache cùng `Set-Cookie` thì không đi qua nó. Cách xử lý tốt nhất là chặn plugin đó ghi cookie ngay từ đầu (với Meta Pixel for WordPress, tích hợp `meta_hold` làm việc này). Kiểm tra trên bản sao (staging) của site thật trước khi chạy chính thức.

### Đổi cài đặt xong mà khách vẫn thấy giao diện cũ

Banner và CSS in ở phía server nên cần xoá cache trang, cache CDN và cache của plugin tối ưu (CSS/JS đã gộp). Plugin đã đánh dấu CSS của nó để không bị inline/hoãn.

### Cloudflare, Rocket Loader, minify

Script của plugin mang `data-cfasync="false"` để Rocket Loader bỏ qua. Nếu bật gộp/minify JS ở CDN, thêm `assets/dist/dscc.js` và các host nền tảng bị chặn vào danh sách loại trừ.

## Chặn script

### Tag theo dõi vẫn chạy trước khi đồng ý

Đi từ ngoài vào trong:

1. Tab Chặn script: chế độ có phải **Chỉ quan sát** không? Ở chế độ đó không có gì bị chặn.
2. Nền tảng đã **tick** ở tab Nền tảng và công tắc *Chặn trước khi khách đồng ý* đang bật chưa? Nền tảng chưa có trong danh mục thì không bị chặn.
3. Script có khớp luật của nền tảng không (host, regex)? Với script lạ, [thêm nền tảng tự định nghĩa](platforms-and-cookies.md#thêm-nền-tảng-tự-định-nghĩa) hoặc [tự đánh dấu thẻ](script-blocking.md#tự-đánh-dấu-thẻ).
4. Thẻ có nằm trong **Danh sách loại trừ**, có `data-dscc-ignore`, hoặc bị một tích hợp giữ lại (Site Kit khi Consent Mode của nó bật) không?
5. Script có được **thêm động** bởi một script khác chưa bị chặn không (kể cả `document.write`)? Bộ chặn chỉ thấy thẻ có trong HTML.
6. Tab Chặn script có cảnh báo **bộ chặn đã để một trang đi qua không chặn** (fail-open) không?
7. Với Google Tag Manager: chế độ *GTM + Consent Mode* chỉ dừng thẻ Google. Thẻ bên thứ ba cần trigger theo `dscc_consent_update`. Xem [script-blocking.md](script-blocking.md#google-tag-manager).
8. Cookie xuất hiện sớm là cookie do server ghi trên trang đã cache? Xem mục cache ở trên.

Dùng chế độ **Chỉ quan sát** và bảng báo cáo của nó để biết bộ chặn nhìn thấy gì mà không đổi trang.

### Form, đặt hàng, chat, thanh toán bị hỏng

Contact Form 7, Elementor, Cloudflare, Turnstile, reCAPTCHA và cookie cần thiết của WordPress/WooCommerce không bao giờ bị chặn. Nếu một script khác bị chặn nhầm, thêm nó vào **Danh sách loại trừ** hoặc tắt công tắc chặn của nền tảng. Dùng chế độ *Chỉ quan sát* trong một tuần trên site có giao dịch thật để phát hiện sớm.

### Video YouTube/bản đồ chỉ hiện khung "Bật cookie ..."

Đó là khung thay thế của iframe bị chặn; nút trong khung cho phép riêng nền tảng đó. Cùng nội dung hiện lại khi khách đồng ý nhóm tương ứng. YouTube và Maps thuộc nhóm **Chức năng** (nút ghi "Bật cookie Chức năng"), không phải Tiếp thị; nếu bạn tắt *Hiện nhóm Chức năng* ở tab Nâng cao thì chúng không còn bị chặn.

### Cookie `_fbp` hoặc `_ga` vẫn thấy trong trình duyệt

Kiểm tra trong cửa sổ ẩn danh, trước khi bấm Đồng ý. Nếu cookie do server ghi và trang đã cache, xem phần cache. Nếu do script phía khách ghi, xem mục "Tag theo dõi vẫn chạy". Xoá cookie cũ của trình duyệt trước khi thử.

### Cookie lạ báo "chưa phân loại" nhiều

Chạy **Quét ngay**, rồi dùng nút phân loại nhanh ở báo cáo tuân thủ ([scanner-and-compliance.md](scanner-and-compliance.md#nút-phân-loại-nhanh)). Cookie riêng của site cần giữ nguyên thì chọn **Luôn giữ**.

## Consent Mode và log

### Kiểm tra Consent Mode có chạy không

Trong cửa sổ ẩn danh, mở console và gõ `dataLayer`: phải thấy lệnh `consent` `default` với `analytics_storage` và `ad_storage` là `denied` trước mọi sự kiện khác. Sau khi bấm Đồng ý có lệnh `consent` `update` và sự kiện `dscc_consent_update`. Chi tiết: [shortcodes-and-js-api.md](shortcodes-and-js-api.md#datalayer-và-consent-mode). Nếu plugin khác (Site Kit...) in `default` sau, plugin gửi lại `update` khi tải trang để không bị ghi đè.

### Log trống hoặc thiếu dòng

- Log ghi qua `admin-ajax.php`: tường lửa, plugin bảo mật hoặc cấu hình chặn `admin-ajax` cho khách chưa đăng nhập sẽ làm mất log.
- Yêu cầu phải cùng host với site (Origin/Referer). Trang chạy ở host khác (proxy, miền phụ) bị từ chối.
- Quá 10 yêu cầu mỗi 60 giây cho một IP bị giới hạn; số lần bị chặn hiện ở trang Tổng quan. Nếu mọi khách có cùng một IP (sau proxy), chọn đúng *Nguồn IP của khách* ở Nâng cao, nhưng **chỉ khi** site thật sự nằm sau proxy đó.
- Khách bị bỏ qua (bot, User-Agent trong danh sách) không được ghi log.
- Log bị xoá tự động sau `retention_months` (mặc định 24 tháng; `0` = không xoá).

## Quét

### Quét máy chủ không tải được trang nào

Quét chỉ tải trang của chính site, như khách mới. Các nguyên nhân hay gặp: site đang bật mật khẩu HTTP, tường lửa/Cloudflare chặn User-Agent trình duyệt mặc định (đổi bằng [`dscc_scan_user_agent`](developer-hooks.md#dscc_scan_user_agent)), host trong `home_url` khác host mà máy chủ tự gọi được, hoặc chứng chỉ TLS không hợp lệ. Có thể bổ sung tham số bằng [`dscc_scan_request_args`](developer-hooks.md#dscc_scan_request_args).

### Thanh tiến độ đứng yên

Các lô quét chạy nối tiếp bằng WP-Cron và cả bằng việc trang admin hỏi tiến độ. Nếu `DISABLE_WP_CRON` bật mà không có cron hệ thống, giữ trang admin mở để lượt quét tiếp tục.

### Quét sâu trả lỗi

| Mã | Nguyên nhân |
|---|---|
| `401` | Chưa xác thực: sai `DSCC_USER`/Application Password, hoặc header `Authorization` không tới được PHP (xem README công cụ về `.htaccess`/nginx) |
| `403` | Tài khoản không có quyền `manage_options` |
| `301`/`302` | Công cụ không theo chuyển hướng; dùng địa chỉ cuối làm `--site-url` |
| `400` | Báo cáo sai cấu trúc hoặc giai đoạn đầu không tải được trang nào |
| `413` | Báo cáo quá 2 MB |
| `422` | `site` trong báo cáo không phải host của site; quét bằng địa chỉ công khai hoặc dùng [`dscc_scan_own_hosts`](developer-hooks.md#dscc_scan_own_hosts) |

Chi tiết và cách chạy: [`tools/deep-scan/README.md`](../tools/deep-scan/README.md). Lần quét "chưa kết luận được" (không thấy nút trên banner hoặc không có `window.DSCC`) không xoá cảnh báo cũ: bật banner cho khách chưa chọn trước khi quét.

## Ngôn ngữ

- Chữ cho khách lấy theo WPML, Polylang rồi ngôn ngữ site. Ngôn ngữ không phải tiếng Việt hay tiếng Anh dùng *Ngôn ngữ mặc định* ở Nâng cao.
- Site dùng GTranslate hoặc công cụ dịch ở phía trình duyệt: chọn *Theo trình duyệt* ở *Cách chọn ngôn ngữ* (GTranslate tự được nhận diện). Cả hai ngôn ngữ nằm trong HTML và trình duyệt chọn theo thuộc tính `lang` của trang.

## Cập nhật plugin

- **Không thấy bản mới**: kết quả được cache 12 giờ (1 giờ nếu lỗi). Vào Bảng điều khiển > Cập nhật và bấm *Kiểm tra lại*. Bản phải là bản phát hành đã công bố (không phải nháp hay pre-release) có asset `ds-cookie-consent.zip`.
- **Hết lượt gọi API của GitHub** trên máy chủ dùng chung: đặt `DSCC_GITHUB_TOKEN` trong `wp-config.php` (xem [release-and-updates.md](release-and-updates.md)).
- **Cập nhật bị huỷ do sai mã kiểm**: gói tải về không khớp SHA-256 GitHub công bố; thử lại, nếu lặp lại thì báo cho D-Solutions.
- **Thông báo "không có mã SHA-256"**: bản phát hành chưa có mã kiểm để xác minh; cân nhắc trước khi cài.

## Quyền riêng tư và pháp lý

- Log lưu mã lựa chọn ngẫu nhiên, lựa chọn, phiên bản chính sách, đường dẫn trang, IP đã che (IPv4 bỏ octet cuối, IPv6 giữ /48) và User-Agent; không có tên hay email.
- Quét và beacon chỉ ghi tên, không ghi giá trị cookie.
- Plugin không tự biến site thành "tuân thủ". Nội dung Chính sách Cookie, việc chọn nhóm cho từng cookie và kiểm tra trên site thật là trách nhiệm của chủ site.

## Gỡ plugin có mất log không?

Xoá plugin luôn xoá cài đặt, cache và tác vụ lên lịch. Bốn bảng dữ liệu (kể cả log) chỉ bị xoá khi bạn đã bật *Xoá dữ liệu khi gỡ plugin* ở Nâng cao. Xuất CSV trước nếu cần giữ bằng chứng.
