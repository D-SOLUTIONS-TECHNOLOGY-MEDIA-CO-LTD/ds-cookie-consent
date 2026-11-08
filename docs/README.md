# Tài liệu DS Cookie Consent

Tài liệu công khai cho chủ site, quản trị viên và lập trình viên. Tổng quan plugin nằm ở [README gốc](../README.md). Khi tài liệu và mã nguồn khác nhau, mã nguồn đúng: mỗi trang đều dẫn tới file chịu trách nhiệm.

## Cho chủ site và quản trị viên

| Tài liệu | Dùng khi |
|---|---|
| [installation.md](installation.md) | Cài, kích hoạt, thiết lập lần đầu, tắt và gỡ plugin |
| [configuration.md](configuration.md) | Tra từng tab, từng tuỳ chọn và giá trị mặc định |
| [platforms-and-cookies.md](platforms-and-cookies.md) | Chọn nền tảng, quản lý bảng cookie, cập nhật cơ sở dữ liệu cookie, xuất/nhập và bộ mẫu |
| [script-blocking.md](script-blocking.md) | Hiểu và tinh chỉnh việc chặn script, chế độ quan sát, GTM, bộ lọc cookie phía server |
| [scanner-and-compliance.md](scanner-and-compliance.md) | Quét cookie, beacon, báo cáo tuân thủ, quét sâu bằng Docker/n8n |
| [faq.md](faq.md) | Banner không hiện, cache, bot, Lighthouse, log, cập nhật |

## Cho lập trình viên

| Tài liệu | Dùng khi |
|---|---|
| [shortcodes-and-js-api.md](shortcodes-and-js-api.md) | Shortcode, `window.DSCC`, sự kiện DOM, `dataLayer`, Consent Mode, cookie lựa chọn |
| [developer-hooks.md](developer-hooks.md) | Mọi filter và action `dscc_*` kèm chữ ký và ví dụ |
| [release-and-updates.md](release-and-updates.md) | Bộ cập nhật GitHub, dựng gói zip, CI và quy trình phát hành |

## Công cụ đi kèm

- [`tools/deep-scan/README.md`](../tools/deep-scan/README.md): công cụ quét sâu bằng trình duyệt headless.
- [`tools/build-known-db/README.md`](../tools/build-known-db/README.md): dựng cơ sở dữ liệu cookie đã biết và bộ mẫu.
- [`CHANGELOG.md`](../CHANGELOG.md): lịch sử thay đổi.

## Quy ước tài liệu

- Tiếng Việt có dấu là ngôn ngữ chính; README gốc có thêm một phần tiếng Anh ngắn.
- Dữ liệu minh hoạ dùng tên miền `example.com` và tiền tố `LittleD`; không có thông tin xác thực thật nào trong tài liệu.
- Danh sách tuỳ chọn, hook, thuộc tính và khoá chỉ được ghi khi có trong mã nguồn; không sao chép bảng số liệu có thể đổi (số nền tảng, số cookie...).
