# Changelog

Định dạng theo [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), phiên bản theo [Semantic Versioning](https://semver.org/). Workflow phát hành lấy phần của từng phiên bản (từ dòng `## x.y.z` tới dòng `##` kế tiếp) làm ghi chú của GitHub Release, nên giữ đúng dạng tiêu đề này.

## 1.0.1

### Changed

- **YouTube và Google Maps** chuyển từ nhóm Tiếp thị sang nhóm **Chức năng** (cơ sở dữ liệu cookie đã biết `2026.11.08.2`, bộ mẫu D-Solutions đã dựng lại). Nền tảng Chức năng của bên thứ ba vẫn bị chặn tới khi khách cho phép nhóm Chức năng; script của chính site (Elementor, Contact Form 7...) không bị chặn.
- **Nhóm Chức năng hiện riêng theo mặc định** (`show_functional` mặc định bật): popup ở cả hai kiểu giao diện có thêm nhóm và công tắc, cookie lựa chọn có khoá `functional`, bảng `[dscc_cookie_table]` có bảng thứ tư, khung "Bật cookie Chức năng" cho video và bản đồ bị chặn. Mô tả mặc định: "Video, bản đồ nhúng và các tiện ích giúp trang hoạt động đầy đủ hơn." Site đã lưu cài đặt giữ giá trị `show_functional` của mình. Consent Mode không đổi: `functionality_storage` và `security_storage` vẫn luôn `granted`.
- **Bảng màu mặc định theo d-solutions.vn**: màu chính `#1e3a8a`, chữ `#1f2937`, nền banner `#f9fafb`, nền popup `#fafaf7`; màu hover và nền nút Từ chối tự tính từ màu chính. Mọi cặp đều đạt WCAG AA. Site đã lưu màu giữ nguyên.

### Added

- **Email cảnh báo vi phạm của quét sâu**: thư văn bản thuần gửi khi báo cáo quét sâu có ít nhất một vi phạm, tới cùng người nhận với email cookie mới. Cùng một danh sách không gửi lại trong 24 giờ, danh sách khác gửi ngay. Bật/tắt bằng cài đặt `scanner.notify_violations` (mặc định bật) ở tab Quét.
- **Cảnh báo ping không cookie của Google Consent Mode** trong quét sâu: khi trình duyệt kết nối tới máy chủ Google Analytics, Google Ads hoặc Floodlight trước khi khách đồng ý (hoặc sau khi từ chối) mà không có cookie hay khoá lưu trữ nào của dịch vụ đó, mục này hiện ở khung cảnh báo trên thẻ quét sâu chứ không tính là vi phạm: không tạo cảnh báo rò rỉ, không gửi email. Máy chủ Google đi kèm cookie Google, hoặc máy chủ của nền tảng khác, vẫn là vi phạm. Đổi danh sách dịch vụ bằng bộ lọc `dscc_consent_mode_ping_services`. Phản hồi của `POST /dscc/v1/scan-result` có thêm `warnings` (số mục theo giai đoạn).

### Fixed

- Ô chọn trong bảng Cookie ở admin có nhãn cho trình đọc màn hình (axe `label`).

## 1.0.0

Bản phát hành ổn định đầu tiên.

### Added

- **Banner và popup**: hai kiểu giao diện (Gọn và Thanh ngang kèm popup ba tab), logo, màu, font, bo góc nút, thứ tự nút, vị trí hộp, icon thu nhỏ, lớp phủ, CSS tuỳ chỉnh có làm sạch; xem trước trực tiếp (desktop và mobile) và cảnh báo độ tương phản; công tắc bật/tắt theo từng nhà cung cấp; chữ hiển thị tiếng Việt và tiếng Anh sửa được, dùng `{site}` và `{policy_link}`; chính sách nhúng trong popup hoặc nút mở trang.
- **Google Consent Mode v2**: giá trị mặc định `denied` từ script đầu trang dưới 1,5 KB, cập nhật khi khách chọn, danh sách vùng, `url_passthrough`, `ads_data_redaction`; sự kiện `dscc_consent_update` trên `dataLayer`.
- **Log lựa chọn**: ghi qua admin-ajax (có bản REST), IP đã che, giới hạn tần suất, trang Log có lọc theo ngày và lựa chọn, xuất CSV UTF-8, tự xoá theo số tháng, bảng thống kê ở trang Tổng quan.
- **Chặn trước khi đồng ý**: viết lại script, iframe và pixel của nền tảng đã chọn ở phía server (HTML giống nhau cho mọi khách, dùng được với cache trang), bật lại trong trình duyệt theo đúng thứ tự; khung thay thế cho iframe; bộ lọc `Set-Cookie` phía server; xoá cookie và khoá lưu trữ của nhóm bị từ chối; chế độ **Chỉ quan sát** kèm báo cáo; danh sách loại trừ, cookie luôn giữ, chế độ Google Tag Manager, chế độ xử lý cookie chưa phân loại (mặc định chỉ báo cáo); bộ chặn luôn "mở" khi gặp sự cố và báo trên tab Chặn script.
- **Nền tảng và cookie**: bộ chọn nền tảng từ cơ sở dữ liệu cookie đã biết, nền tảng tự định nghĩa, bảng cookie với lọc, sửa nhanh, thao tác hàng loạt và thêm thủ công, cập nhật cơ sở dữ liệu từ URL https có kiểm SHA-256.
- **Quét và tuân thủ**: quét máy chủ (HTML, container GTM, header `Set-Cookie`) và quét tuần tự động; beacon thu thập thụ động từ khách (mặc định tắt, chỉ gửi tên); báo cáo tuân thủ với phân loại nhanh (Cần thiết, Phân tích, Tiếp thị, Luôn giữ, Bỏ qua); email báo cookie mới; công cụ quét sâu bằng trình duyệt headless (Docker, ví dụ n8n) gửi kết quả về `POST /wp-json/dscc/v1/scan-result` bằng Application Password.
- **Shortcode và API**: `[dscc_cookie_settings]`, `[dscc_cookie_table]`, `[dscc_last_scan]`; `window.DSCC` (`open`, `get`, `has`, `reset`); sự kiện `dscc:consent` và `dscc:service-enabled`; các hook `dscc_*` cho lập trình viên.
- **Xuất/nhập cấu hình** JSON có màn hình xem trước, và bộ mẫu `D-Solutions — Mẫu tiêu chuẩn` chỉ áp dụng khi quản trị viên chủ động nhập.
- **Tích hợp**: Google Site Kit, PixelYourSite, Meta Pixel for WordPress, LiteSpeed Cache, WP Rocket, WP Consent API, widget chat (Pancake, Zalo); WPML, Polylang và GTranslate cho ngôn ngữ banner.
- **Bản dịch**: chuỗi quản trị gốc tiếng Anh với bản dịch tiếng Việt đầy đủ.
- **Cập nhật tự động** từ GitHub Releases qua cơ chế cập nhật chuẩn của WordPress, kiểm SHA-256 của gói khi GitHub công bố; phần nâng cấp cơ sở dữ liệu chạy ở yêu cầu đầu tiên sau khi cập nhật.
- **Phát hành**: `tools/release/build-zip.sh`, `verify-package.php`, workflow CI và `release.yml` (tag `vX.Y.Z` dựng gói và tạo GitHub Release kèm `ds-cookie-consent.zip`).
- **Tài liệu** tiếng Việt trong `docs/`, README song ngữ.
