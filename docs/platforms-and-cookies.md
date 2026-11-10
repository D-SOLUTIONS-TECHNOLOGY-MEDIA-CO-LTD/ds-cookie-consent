# Nền tảng và danh mục cookie

Plugin giữ một **danh mục** gồm *nền tảng* (còn gọi là nhà cung cấp hay dịch vụ: Google Analytics, Meta Pixel...) và *cookie* của từng nền tảng. Danh mục này là nguồn cho ba việc: popup hiển thị cho khách, bảng trong trang Chính sách Cookie, và luật chặn script (xem [script-blocking.md](script-blocking.md)).

## Các nhóm cookie

| Nhóm | Hiển thị cho khách | Chặn trước khi đồng ý |
|---|---|---|
| Cần thiết | Luôn bật, khách không tắt được | Không bao giờ |
| Chức năng | Có công tắc riêng (mặc định bật; tắt *Hiện nhóm Chức năng* ở tab Nâng cao thì gộp vào Cần thiết). Gồm video nhúng như YouTube và bản đồ Google Maps | Nền tảng bên thứ ba (YouTube, Maps...) bị chặn tới khi khách cho phép nhóm này; script của chính site (Elementor, Contact Form 7...) không bị chặn |
| Phân tích | Có công tắc | Có (mặc định) |
| Tiếp thị | Có công tắc | Có (mặc định) |
| Chưa phân loại | **Không bao giờ** hiện cho khách | Xem phần cookie chưa phân loại ở [script-blocking.md](script-blocking.md#cookie-chưa-phân-loại) |

Site đã lưu cài đặt trước phiên bản 1.0.1 giữ nguyên giá trị `show_functional` đã lưu (thường là tắt, tức nhóm Chức năng vẫn gộp vào Cần thiết); muốn dùng nhóm riêng, bật lại ở tab Nâng cao. YouTube và Google Maps nay thuộc nhóm Chức năng thay vì Tiếp thị.

Nhóm của một cookie luôn là nhóm của nền tảng chứa nó. Đổi nhóm một cookie của nhà cung cấp sẽ đổi cả nhà cung cấp; cookie không có nhà cung cấp được gán vào nền tảng riêng của site ở nhóm đích.

## Tab Nền tảng

Tab **Nền tảng** liệt kê các nền tảng trong cơ sở dữ liệu cookie đã biết, cộng nền tảng do quét tạo và nền tảng tự định nghĩa. Có ô tìm kiếm và bộ lọc theo nhóm.

Với mỗi nền tảng:

- **Đang dùng trên site**: tick để đưa nền tảng và mọi cookie của nó vào danh mục với trạng thái *đã xác nhận*, hiện trong popup cho khách. Bỏ tick thì nền tảng và cookie chuyển sang *bỏ qua*, dữ liệu được giữ nên tick lại sẽ khôi phục (lựa chọn "chặn" trước đó cũng được giữ).
- **Chặn trước khi khách đồng ý**: công tắc chặn script/pixel/iframe của nền tảng. Mặc định bật cho nhóm Phân tích và Tiếp thị; bị khoá tắt với nhóm Cần thiết.
- Nhãn **Phát hiện trên site** hoặc **Phát hiện qua GTM** do lần quét gần nhất ghi (xem [scanner-and-compliance.md](scanner-and-compliance.md)). Nền tảng do quét phát hiện có trạng thái *tự động*.
- Liên kết **Chính sách** của nhà cung cấp luôn mở ở tab mới với `rel="nofollow noopener noreferrer"`.

Nền tảng không bao giờ bị chặn dù bạn gán nhóm gì: Contact Form 7, Elementor, Cloudflare, Cloudflare Turnstile, Google reCAPTCHA và chính DS Cookie Consent (`ScriptRules::NEVER_BLOCK`). Mục đích là không làm hỏng form và cơ chế chống spam.

### Thêm nền tảng tự định nghĩa

Mục **Thêm nền tảng tự định nghĩa** nhận:

| Trường | Ý nghĩa |
|---|---|
| Tên nền tảng, Nhóm cookie | Tên hiển thị cho khách và nhóm |
| Link chính sách | Liên kết chính sách của bên thứ ba |
| Host của script | Mỗi dòng một tên miền (ví dụ `cdn.littled.example`); script/iframe có `src` thuộc host hoặc subdomain này sẽ bị chặn |
| Regex của URL script (tuỳ chọn) | Mỗi dòng một biểu thức, không có dấu phân cách, không chứa ký tự `~` |

Sau khi thêm, vào tab **Cookie** để khai báo cookie của nền tảng đó.

### Cập nhật danh mục cookie

Danh mục đã biết nằm trong `data/known-cookies.json` kèm `data/known-cookies.json.sha256` (đi cùng plugin). Có thể cập nhật mà không cần phát hành plugin mới:

1. Đặt URL https của `known-cookies.json` ở **Nâng cao > URL cập nhật danh mục cookie** (hoặc bộ lọc [`dscc_known_db_url`](developer-hooks.md#dscc_known_db_url)).
2. Ở tab Nền tảng bấm **Cập nhật danh mục cookie**.

Plugin tải file qua `wp_safe_remote_get` (từ chối địa chỉ nội bộ), tải thêm file `.sha256` cùng thư mục, và chỉ nạp khi SHA-256 khớp và cấu trúc hợp lệ (tối đa 2 MB, 400 nền tảng, 3.000 cookie). File `.sha256` có mà không khớp thì bị từ chối; file không có mã kiểm tra nào (cả `sha256` bên trong lẫn file `.sha256`) cũng bị từ chối. File hợp lệ được lưu ở `uploads/dscc/known-cookies.json` và dùng thay bản đi kèm. Cách dựng file này: [`tools/build-known-db/README.md`](../tools/build-known-db/README.md).

## Tab Cookie

Bảng cookie của site, các dòng *Mới* nằm trên cùng. Dấu số trên menu **Cookie Consent** và trên tab là số cookie đang chờ duyệt.

**Trạng thái**

| Trạng thái | Ý nghĩa |
|---|---|
| Mới | Quét thấy, chưa ai duyệt; ẩn với khách |
| Tự động | Khớp cơ sở dữ liệu đã biết hoặc cookie của WordPress/WooCommerce/PHP; dùng chữ mô tả có sẵn |
| Đã xác nhận | Bạn đã duyệt hoặc nền tảng đã được tick; hiện cho khách |
| Bỏ qua | Giữ lại dữ liệu nhưng không dùng (không chặn, không hiện) |

**Nguồn**: *Danh mục có sẵn*, *Quét*, *Quét sâu*, *Thủ công*. Cột *Ghi bởi* cho biết cookie do server, do script phía khách hay chưa rõ. Chỉ cookie do **server** ghi mới có thể bị bộ lọc server gỡ.

**Thao tác**

- Lọc theo nhóm, nguồn, tìm theo tên cookie hoặc nhà cung cấp; liên kết trạng thái phía trên bảng.
- **Sửa nhanh** từng dòng: nhóm, mục đích và thời hạn (vi/en), nhóm dòng trong bảng chính sách, "áp dụng tại".
- **Thao tác hàng loạt**: Xác nhận, Bỏ qua, Chuyển sang nhóm..., Xoá. Cookie chưa có nhà cung cấp hoặc nhóm không xác nhận được cho tới khi bạn chuyển nhóm.
- **Thêm cookie thủ công**: tên (dùng `*` cho phần thay đổi, ví dụ `_ga_*`), loại lưu trữ (cookie, localStorage, sessionStorage, indexedDB, pixel), nhà cung cấp hoặc nhóm, tên miền, mục đích/thời hạn/áp dụng tại (vi/en), nhóm dòng.
- **Quét ngay** và báo cáo tuân thủ: xem [scanner-and-compliance.md](scanner-and-compliance.md).

**Bảng trong trang chính sách**: shortcode `[dscc_cookie_table]` in cookie đã xác nhận theo nhóm. Các cookie cùng *nhóm dòng* gộp một dòng (tên nối bằng dấu phẩy, mục đích/thời hạn lấy từ cookie đầu tiên); cookie cùng nhà cung cấp và cùng mục đích cũng gộp, và nếu thời hạn khác nhau thì liệt kê từng cookie. Xem [shortcodes-and-js-api.md](shortcodes-and-js-api.md).

## Quy ước tên cookie

- Tên có dấu `*` là mẫu (ví dụ `_ga_*`, `ttcsid*`). Khi hiển thị cho khách, `*` đứng sau `_` hoặc `-` được viết thành `<mã>` (`_ga_*` thành `_ga_<mã>`).
- Cookie của chính plugin (`dscc_*`) và của WordPress/WooCommerce/PHP luôn được giữ, xem danh sách an toàn ở [script-blocking.md](script-blocking.md#danh-sách-giữ-nguyên).

## Xuất, nhập và bộ mẫu

Tab **Nhập / Xuất**:

- **Tải cấu hình**: file JSON `{"format": "dscc-export", "version": 1, ...}` gồm cài đặt, chữ tuỳ chỉnh, nền tảng và cookie. Không có log đồng ý và không có giá trị gắn với một site (xem cuối [configuration.md](configuration.md)).
- **Nhập**: chọn file `.json` hoặc một **bộ mẫu** có sẵn, bấm **Xem trước**. Màn hình hiện số mục mới/cập nhật/không đổi cho cài đặt, chữ, nền tảng, cookie và danh sách cài đặt sẽ đổi. Chưa có gì được lưu cho tới khi bấm **Áp dụng**, và bản xem trước được giữ tạm để cái được áp dụng đúng là cái đã xem.
- Quy tắc ghi: cài đặt trong file ghi đè cài đặt cùng tên, cài đặt không được nhắc tới giữ nguyên; nền tảng và cookie được thêm hoặc cập nhật, **không xoá gì**; chữ tuỳ chỉnh được gộp; log đồng ý không bị đụng tới. File sai cấu trúc bị từ chối trước khi ghi (tối đa 2 MB, 400 nền tảng, 3.000 cookie).

**Bộ mẫu** là file xuất dựng sẵn trong thư mục `presets/`, hiện ở danh sách "Hoặc bộ mẫu có sẵn". Bộ mẫu **không bao giờ tự áp dụng**; chỉ có tác dụng khi quản trị viên chọn, xem trước và bấm Áp dụng; file bạn tải lên được ưu tiên hơn bộ mẫu.

Bộ **D-Solutions — Mẫu tiêu chuẩn** (`presets/d-solutions-standard.json`) là điểm xuất phát cho site mới của D-Solutions. Nó chỉ nạp **nền tảng và cookie**, không đổi cài đặt nào của plugin: WordPress (cookie cần thiết), DS Cookie Consent, WooCommerce, Google reCAPTCHA, Google Tag Manager, Google Analytics 4, Microsoft Clarity, nguồn đơn hàng của WooCommerce, Google Ads, Meta Pixel, TikTok Pixel, YouTube và Zalo. Danh sách chính xác xem trên màn hình xem trước hoặc trong file. File được sinh từ `tools/build-known-db/preset-definition.json` và cơ sở dữ liệu cookie bằng `build-preset.mjs` (xem [`tools/build-known-db/README.md`](../tools/build-known-db/README.md)).

Muốn thêm bộ mẫu: đặt một file `.json` hợp lệ (cùng định dạng xuất, có khối `preset` với `label` và `description`) vào `presets/`; tên file chỉ gồm chữ thường, số và `-`.
