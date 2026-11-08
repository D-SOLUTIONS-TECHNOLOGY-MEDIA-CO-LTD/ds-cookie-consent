# Quét cookie và báo cáo tuân thủ

Có ba nguồn dữ liệu cookie, đều đi qua cùng một bộ phân loại: cookie đã biết thì tự gán nhóm và nội dung mô tả; cookie của WordPress, WooCommerce và PHP là Cần thiết; còn lại là **chưa phân loại**, ẩn với khách và chờ bạn duyệt. Bộ phân loại ở [`src/Catalog/Classifier.php`](../src/Catalog/Classifier.php), điểm hợp nhất kết quả ở [`src/Scanner/FindingStore.php`](../src/Scanner/FindingStore.php).

| Nguồn | Chạy ở đâu | Thấy được gì |
|---|---|---|
| [Quét máy chủ](#quét-máy-chủ) | Trên chính site | Cookie do server ghi cho khách mới, nền tảng trong HTML và container GTM |
| [Beacon từ khách](#beacon-thu-thập-từ-khách) | Trình duyệt của khách (mặc định tắt) | Cookie/khoá lưu trữ do script phía khách ghi, cookie rò rỉ sau khi từ chối |
| [Quét sâu](#quét-sâu-deep-scan) | Máy bạn chạy công cụ ngoài WordPress | Toàn bộ cookie trong trình duyệt thật, qua ba giai đoạn |

Mọi nguồn chỉ ghi **tên và metadata**, không bao giờ ghi giá trị cookie hay giá trị lưu trữ.

## Quét máy chủ

Bấm **Quét ngay** ở tab Cookie, hoặc bật quét tuần ở tab *Quét cookie* (`auto_scan = weekly`, dùng WP-Cron).

Cách hoạt động (mã ở [`src/Scanner/`](../src/Scanner/)):

1. **Chọn trang**: trang chủ, trang WooCommerce, trang có form và 20 bài mới nhất của mỗi loại nội dung công khai, tối đa `max_urls` trang (mặc định 50, tối đa 200). Chỉ tải trang của **chính site**. Có thể chỉnh danh sách bằng bộ lọc [`dscc_scan_urls`](developer-hooks.md#dscc_scan_urls).
2. **Tải như khách mới**: năm trang mỗi lô, User-Agent trình duyệt, không có cookie, timeout 15 giây, giới hạn thân phản hồi 2 MB, kiểm tra TLS như WordPress quyết định. Chuyển hướng được theo thủ công, tối đa 3 lần và chỉ trong site.
3. **Ghi mọi header `Set-Cookie`**: tên, domain, path, thời hạn, HttpOnly; không ghi giá trị.
4. **Nhận diện nền tảng** từ địa chỉ script/iframe/ảnh và script inline trong HTML (kể cả thẻ đã bị chặn, nhờ `data-dscc-src`).
5. **Đọc container GTM**: container `GTM-XXXX` tìm thấy trên trang của site được tải từ `https://www.googletagmanager.com/gtm.js?id=GTM-XXXX` (tối đa 5 container mỗi lượt), rồi đối chiếu id thẻ (`G-`, `AW-`, `DC-`, `UA-`) và địa chỉ nhà cung cấp. Nền tảng phát hiện theo cách này có nhãn **Phát hiện qua GTM**.
6. Tên miền bên thứ ba chưa nhận diện được liệt kê trong bảng quét nhưng **không bị chặn**; nếu là công cụ theo dõi, hãy [thêm nền tảng tự định nghĩa](platforms-and-cookies.md#thêm-nền-tảng-tự-định-nghĩa).

Yêu cầu quét mang một token ký ngắn hạn (`?dscc_scan=`). Phản hồi cho yêu cầu đó không bao giờ bị cache và liệt kê các cookie mà bộ lọc server đã giữ lại trong header `X-DSCC-Held`; nhờ đó lượt quét biết site *sẽ* ghi cookie nào mà không nhận chúng như cookie thật.

Các lô chạy nối tiếp qua sự kiện cron `dscc_scan_tick` và cả qua thanh tiến độ trong admin (một khoá ngăn hai bên làm trùng một lượt). Lượt quét chạy được khi không mở màn hình admin nào; lượt treo hơn 30 phút được coi là cũ. 20 lượt gần nhất mỗi loại được giữ.

Kết quả:

- Cookie của nền tảng đã biết: *tự động*, kèm mô tả có sẵn.
- Cookie WordPress/WooCommerce/PHP và khoá trong danh sách giữ nguyên: Cần thiết.
- Cookie còn lại: *Mới* (chưa phân loại). Hàng chờ cookie mới do beacon và quét sâu có trần 300 dòng; quét máy chủ không bị trần này.

**Email cảnh báo** (`notify_enabled`): gửi khi một lượt quét thấy cookie mới, tối đa một email mỗi ngày, tới `notify_email` hoặc email quản trị site.

## Beacon: thu thập từ khách

Mặc định **tắt** (`beacon_enabled`). Khi bật, một tỉ lệ nhỏ lượt xem trang (`beacon_sample`, mặc định 5%) báo về site:

- sau khi tải trang 5 giây và **chỉ khi khách đã chọn** (đồng ý hoặc từ chối);
- tên cookie, khoá localStorage/sessionStorage, host của tài nguyên đã tải, trạng thái đồng ý của chính khách và đường dẫn trang;
- không bao giờ gửi giá trị; không lưu IP; không gửi từ người đã đăng nhập; bỏ qua cookie của khu vực quản trị WordPress; mỗi phiên trình duyệt một lần.

Việc rút thăm tỉ lệ diễn ra **trong trình duyệt**, nên HTML trang vẫn giống nhau cho mọi khách và không phát sinh truy vấn cơ sở dữ liệu thêm.

Endpoint công khai nhưng được bảo vệ: chỉ nhận khi đã bật, từ khách chưa đăng nhập, thân tối đa 4 KB, kiểm tra Origin/Referer cùng site, xác thực chặt từng trường, giới hạn tần suất 3 lần/phút theo mạng và 120 lần/phút toàn site. Cổng là `admin-ajax.php?action=dscc_beacon` (chính) và `POST /wp-json/dscc/v1/beacon`.

Beacon giúp thấy cookie do script phía khách ghi và **cookie rò rỉ**: cookie Phân tích/Tiếp thị tồn tại trong trình duyệt của khách đã từ chối nhóm đó. Nếu hàng chờ cookie mới đã đầy, phát hiện từ khách bị bỏ và tab Cookie báo số bị bỏ.

## Báo cáo tuân thủ và phân loại nhanh

Khối **Cảnh báo tuân thủ** ở trang Tổng quan và tab Cookie tổng hợp:

| Mục | Nguồn | Ý nghĩa |
|---|---|---|
| Cookie được ghi trước khi khách đồng ý | Quét máy chủ | Cookie Phân tích/Tiếp thị/chưa phân loại mà server gửi cho khách mới |
| Đã được bộ lọc server giữ lại | Quét máy chủ | Thông tin, không phải vi phạm: cookie site định ghi nhưng bộ lọc gỡ |
| Cookie rò rỉ sau khi khách từ chối | Beacon, quét sâu | Cookie Phân tích/Tiếp thị có trong trình duyệt đã từ chối nhóm hoặc nhà cung cấp đó (tối đa 50 mục, giữ 30 ngày) |
| Cookie chưa phân loại | Mọi nguồn | Chờ bạn duyệt |
| Có trên site nhưng không có trong trang chính sách | So với trang Chính sách Cookie đã chọn | Plugin đọc nội dung trang (kể cả `[dscc_cookie_table]`) và đối chiếu tên cookie |

Có nhãn chế độ hiện tại (**Chỉ báo cáo** hoặc **Đang chặn**) liên kết tới tab Chặn script, và nút **Xoá cảnh báo rò rỉ** sau khi bạn đã xử lý.

### Chế độ báo cáo cookie chưa phân loại

Mặc định cookie lạ do server ghi chỉ được **báo cáo**, không bị chặn (`unclassified_cookie_mode = report`; ý nghĩa và rủi ro ở [script-blocking.md](script-blocking.md#cookie-chưa-phân-loại)). Ở chế độ này cookie vẫn được gửi và chưa hiện cho khách; bạn chọn nhóm cho từng dòng, và cookie Phân tích/Tiếp thị đã xác nhận sẽ bị chặn tới khi khách đồng ý.

### Nút phân loại nhanh

Mỗi dòng chưa phân loại có nút, và ô chọn để làm hàng loạt từ danh sách "Thao tác cho các dòng đã chọn":

| Nút | Tác dụng |
|---|---|
| **Cần thiết** | Xếp vào Cần thiết và xác nhận |
| **Phân tích** | Xếp vào Phân tích, xác nhận (bị chặn tới khi đồng ý, ở cả hai chế độ) |
| **Tiếp thị** | Xếp vào Tiếp thị, xác nhận (bị chặn tới khi đồng ý) |
| **Luôn giữ** | Thêm tên vào `keep_cookies` (không bao giờ lọc/xoá), xếp vào Cần thiết, xác nhận |
| **Bỏ qua** | Chuyển sang trạng thái bỏ qua |

Dòng biến mất khỏi báo cáo mà không tải lại trang; danh mục và luật chặn được dựng lại. Khi JavaScript tắt, nút dùng form thường. Thao tác cần quyền `manage_options` và nonce.

## Quét sâu (deep scan)

Công cụ [`tools/deep-scan`](../tools/deep-scan/README.md) (Node 20 + Playwright, có image Docker chạy bằng user không phải root) mở site như khách thật và kiểm tra tuân thủ. Mỗi site chạy ba giai đoạn:

1. `before_choice`: trang sạch, chờ vài giây, ghi trạng thái khi **chưa chọn**.
2. `after_reject`: bấm **Từ chối**, tải lại vài trang, ghi trạng thái.
3. `after_accept`: trình duyệt mới hoàn toàn, bấm **Đồng ý**, tải lại, ghi trạng thái.

Mỗi giai đoạn ghi trang đã tải, cookie của mọi domain (tên và thuộc tính), khoá localStorage/sessionStorage, host của mọi request và tên cookie trong header `Set-Cookie` theo host. Không ghi giá trị.

Chạy nhanh bằng Docker (đầy đủ tuỳ chọn, chạy cứng hoá, site chỉ truy cập được bằng tên nội bộ: xem README của công cụ):

```bash
cd tools/deep-scan
docker build -t dscc-deep-scan .
docker run --rm --ipc=host --env-file /etc/dscc/deep-scan.env dscc-deep-scan https://example.com --push
```

### Gửi kết quả về site

Công cụ gửi báo cáo bằng `POST /wp-json/dscc/v1/scan-result` với **Application Password** của một người dùng có quyền `manage_options`:

1. Trong WordPress: *Người dùng > Hồ sơ* của tài khoản quản trị > *Application Passwords* > tạo mật khẩu mới (ví dụ tên `deep-scan`).
2. Lưu `DSCC_USER` và `DSCC_APP_PASSWORD` vào một file chỉ chủ sở hữu đọc được (`chmod 600`), dùng làm `--env-file` của Docker hoặc `--app-password-file`. **Không** đưa mật khẩu lên dòng lệnh (công cụ không có cờ `--app-password`).
3. Chạy với `--push`. Công cụ từ chối gửi qua `http://` trừ khi host là loopback hoặc có `--allow-insecure`.

Endpoint trả: `401` khi chưa xác thực, `403` khi thiếu quyền, `413` khi quá 2 MB, `400` khi JSON sai cấu trúc (`schema: 1`, thừa khoá, có giá trị cookie, không có trang nào ở giai đoạn đầu, `scanned_at` không theo ISO-8601 hoặc quá cũ/quá xa), `422` khi `site` trong báo cáo không phải host của chính site này, `200` kèm `platforms_created`. Hợp đồng đầy đủ nằm ở [`src/Scanner/DeepScanValidator.php`](../src/Scanner/DeepScanValidator.php) và README của công cụ. Muốn server chấp nhận thêm tên host (ví dụ tên nội bộ trong Docker), dùng bộ lọc [`dscc_scan_own_hosts`](developer-hooks.md#dscc_scan_own_hosts).

### Lên lịch bằng n8n hoặc cron

`tools/deep-scan/n8n-workflow-example.json` là workflow mẫu: *Schedule Trigger* hằng tuần, *Execute Command* chạy container với `--push`, và một node `HTTP Request` tuỳ chọn để báo khi lỗi. Node Execute Command cần n8n tự host có Docker. Mật khẩu nằm trong env-file của máy chạy n8n, không đưa vào workflow. Hoặc dùng cron:

```cron
0 3 * * 1 docker run --rm --ipc=host --cap-drop=ALL --security-opt no-new-privileges --read-only --tmpfs /tmp --env-file /etc/dscc/deep-scan.env dscc-deep-scan https://example.com --push >> /var/log/dscc-deep-scan.log 2>&1
```

### Vi phạm và kết quả "chưa kết luận"

**Vi phạm** = cookie, khoá lưu trữ hoặc host dịch vụ thuộc nhóm Phân tích/Tiếp thị xuất hiện ở `before_choice` hoặc `after_reject`. Host nhà cung cấp được so theo `script_patterns.hosts` của danh mục (host hoặc subdomain), nên chỉ cần liên hệ tới `connect.facebook.net` cũng là vi phạm nếu nhà cung cấp thuộc nhóm đó. Danh sách xuất hiện ở khối cảnh báo; khi bộ chặn chạy đúng thì danh sách trống.

Lượt quét **chưa kết luận được** khi không tải được trang nào, không thấy nút Từ chối/Đồng ý trên banner, hoặc trang không có `window.DSCC`. Thẻ *Quét sâu gần nhất* khi đó báo lý do thay vì dòng xanh "không có vi phạm", và cảnh báo quét sâu cũ **được giữ nguyên**. Báo cáo hợp lệ mới thay thế cảnh báo quét sâu của lần trước.

Nền tảng mới do lượt quét tạo (`platforms_created`) được liệt kê kèm liên kết tới tab Nền tảng. Nền tảng Cần thiết và Chức năng do quét tạo có công tắc chặn tắt; Maps và YouTube thuộc Tiếp thị nên bị chặn như mọi nền tảng Tiếp thị.

Báo cáo `after_reject` phản ánh trạng thái cookie **tại thời điểm đó**: cookie đã có từ giai đoạn một mà plugin chưa xoá vẫn hiện, đúng với mục đích kiểm tra tuân thủ.
