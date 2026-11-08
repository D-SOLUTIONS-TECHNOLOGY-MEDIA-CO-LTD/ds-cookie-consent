# DS Cookie Consent

Plugin WordPress hỏi khách truy cập về cookie, chặn script theo dõi cho tới khi khách đồng ý, ghi lại lựa chọn và giúp bạn lập danh mục cookie của site. Do **D-Solutions** phát triển để dùng chung cho mọi site khách hàng: plugin không gắn với một khách nào, mọi dữ liệu mẫu đều là bộ mẫu bạn tự bấm nạp.

- Phiên bản hiện tại: 1.0.0, giấy phép GPL-2.0-or-later.
- Bản phát hành: [GitHub Releases](https://github.com/D-SOLUTIONS-TECHNOLOGY-MEDIA-CO-LTD/ds-cookie-consent/releases) (file `ds-cookie-consent.zip`), có cập nhật ngay trong trang Plugin của WordPress.
- English: xem [phần tiếng Anh](#english) ở cuối trang.

> Plugin là công cụ kỹ thuật, không tự biến site thành "tuân thủ". Nội dung Chính sách Cookie và việc kiểm tra kết quả trên site thật vẫn là trách nhiệm của chủ site.

## Mục lục

- [Tính năng](#tính-năng)
- [Yêu cầu](#yêu-cầu)
- [Cài đặt](#cài-đặt)
- [Cập nhật tự động](#cập-nhật-tự-động)
- [Bắt đầu nhanh](#bắt-đầu-nhanh)
- [Tài liệu](#tài-liệu)
- [Phát triển](#phát-triển)
- [Phát hành](#phát-hành)
- [Giấy phép và liên hệ](#giấy-phép-và-liên-hệ)
- [English](#english)

## Tính năng

**Banner và popup**

- Hai kiểu giao diện: **Gọn** (hộp nhỏ ở góc, mặc định) và **Thanh ngang** (kèm popup ba tab). Chỉnh logo, màu, font, bo góc nút, thứ tự nút, vị trí, icon thu nhỏ, CSS tuỳ chỉnh, có xem trước trực tiếp và cảnh báo độ tương phản.
- Công tắc bật/tắt theo từng nhà cung cấp ngay trong popup.
- Chữ hiển thị cho khách có tiếng Việt và tiếng Anh, sửa được trong admin. Chọn ngôn ngữ theo WordPress, WPML, Polylang hoặc GTranslate.
- Script đầu trang dưới 1,5 KB, JS dưới 20 KB gzip; HTML giống hệt nhau cho mọi khách nên dùng được với cache trang.

**Đồng ý và nhật ký**

- **Google Consent Mode v2**: giá trị mặc định `denied` ngay từ đầu trang, cập nhật khi khách chọn, tuỳ chọn danh sách vùng và URL passthrough.
- **Log lựa chọn** với IP đã che, lọc theo ngày/lựa chọn, xuất **CSV**, tự xoá theo số tháng cài đặt.
- Phiên bản chính sách: đổi để yêu cầu mọi khách chọn lại.

**Chặn trước khi đồng ý**

- **Chặn script** (script, iframe, pixel) của các nền tảng đã tick: đổi HTML ở phía server, kích hoạt lại trong trình duyệt sau khi khách đồng ý.
- **Bộ lọc cookie phía server** gỡ `Set-Cookie` của cookie chưa được phép.
- Chế độ **Chỉ quan sát** để xem trước điều gì sẽ bị chặn mà không đổi gì trên site.
- Danh sách loại trừ, cookie luôn giữ, chế độ GTM, xử lý cookie chưa phân loại.

**Nền tảng và cookie**

- **Bộ chọn nền tảng** từ cơ sở dữ liệu cookie đã biết (Google Analytics, Ads, Tag Manager, Meta Pixel, TikTok, Clarity, Hotjar, LinkedIn, YouTube, Maps, WooCommerce, Zalo... ); thêm nền tảng tự định nghĩa; cập nhật cơ sở dữ liệu từ URL https có kiểm SHA-256.
- Bảng cookie của site: lọc, sửa nhanh, xác nhận/bỏ qua/đổi nhóm hàng loạt, thêm thủ công.

**Quét và tuân thủ**

- **Quét máy chủ** (HTML, container GTM, header `Set-Cookie`), quét tuần tự động.
- **Beacon** thu thập thụ động từ khách (mặc định tắt, chỉ gửi tên, không gửi giá trị).
- **Báo cáo tuân thủ** với chế độ **báo cáo cookie chưa phân loại** và các nút phân loại nhanh (Cần thiết, Phân tích, Tiếp thị, Luôn giữ, Bỏ qua).
- **Quét sâu (deep scan)** bằng trình duyệt headless chạy ngoài WordPress (Docker, n8n, cron), gửi báo cáo về REST endpoint bằng Application Password.

**Tích hợp và vận hành**

- **Shortcode**: `[dscc_cookie_settings]`, `[dscc_cookie_table]`, `[dscc_last_scan]`; API JavaScript `window.DSCC`; sự kiện `dataLayer` và DOM.
- **Xuất/nhập cấu hình** JSON có màn hình xem trước, kèm **bộ mẫu** (chỉ áp dụng khi quản trị viên chủ động nhập).
- Tích hợp **Google Site Kit**, **PixelYourSite**, **Meta Pixel for WordPress**, **LiteSpeed Cache**, **WP Rocket**, **WP Consent API** và nhận diện widget chat (Pancake, Zalo).
- Giao diện quản trị gốc tiếng Anh với bản dịch tiếng Việt đầy đủ.

## Yêu cầu

- WordPress 6.0 trở lên (đã thử tới 7.1), PHP 7.4 trở lên (CI chạy PHP 7.4 và 8.3).
- Site có WP-Cron hoạt động nếu muốn quét tự động; không cần Composer hay Node trên site chạy thật.
- Quét sâu: một máy có Docker (hoặc Node 20+) để chạy công cụ ngoài WordPress.

## Cài đặt

1. Tải `ds-cookie-consent.zip` ở trang Releases.
2. Vào **Plugin > Cài mới > Tải plugin lên**, chọn file zip, bấm **Cài đặt** rồi **Kích hoạt**. Hoặc giải nén vào `wp-content/plugins/ds-cookie-consent/`.
3. Kích hoạt tạo các bảng dữ liệu của plugin, quyền xem log cho quản trị viên và cài đặt mặc định. Chưa có nền tảng hay cookie nào được nạp sẵn.
4. Menu **Cookie Consent** gồm Tổng quan, Cài đặt và Log.

Hướng dẫn đầy đủ, gỡ cài đặt và xoá dữ liệu: [docs/installation.md](docs/installation.md).

## Cập nhật tự động

Plugin kiểm tra bản phát hành mới nhất trên GitHub Releases của repo công khai (không cần token) và đưa vào cơ chế cập nhật chuẩn của WordPress, nên hoạt động với màn hình Plugin, cập nhật tự động, WP-CLI và các công cụ quản lý từ xa. Repo mặc định đổi được bằng hằng `DSCC_UPDATE_REPO` trong `wp-config.php`. Chi tiết: [docs/release-and-updates.md](docs/release-and-updates.md).

## Bắt đầu nhanh

1. Cài đặt và kích hoạt.
2. **Cài đặt > Giao diện**: chọn kiểu, màu, logo; xem trước ngay trong trang.
3. **Nội dung**: chọn trang Chính sách Cookie và Chính sách Bảo mật.
4. **Nền tảng**: tick các nền tảng site đang dùng (hoặc nhập bộ mẫu ở tab Nhập / Xuất).
5. **Chặn script**: chế độ mặc định là chặn. Với site đã có lưu lượng thật, nên đặt **Chỉ quan sát** khoảng một tuần, xem báo cáo, rồi chuyển lại sang chặn.
6. **Cookie**: bấm **Quét ngay**, phân loại các cookie đánh dấu *Mới*.
7. Dán `[dscc_cookie_table]` vào trang Chính sách Cookie.
8. Kiểm tra bằng cửa sổ ẩn danh: trước khi bấm Đồng ý không có `_ga`, `_fbp`... trong cookie của trình duyệt.

## Tài liệu

Mục lục đầy đủ ở [docs/README.md](docs/README.md).

| Chủ đề | Tài liệu |
|---|---|
| Cài đặt, gỡ cài đặt | [docs/installation.md](docs/installation.md) |
| Từng tab và từng tuỳ chọn | [docs/configuration.md](docs/configuration.md) |
| Nền tảng và cơ sở dữ liệu cookie | [docs/platforms-and-cookies.md](docs/platforms-and-cookies.md) |
| Chặn script, GTM, bộ lọc cookie | [docs/script-blocking.md](docs/script-blocking.md) |
| Quét, beacon, quét sâu, báo cáo tuân thủ | [docs/scanner-and-compliance.md](docs/scanner-and-compliance.md) |
| Shortcode, JavaScript API, sự kiện | [docs/shortcodes-and-js-api.md](docs/shortcodes-and-js-api.md) |
| Hook cho lập trình viên | [docs/developer-hooks.md](docs/developer-hooks.md) |
| Phát hành và cập nhật | [docs/release-and-updates.md](docs/release-and-updates.md) |
| Câu hỏi thường gặp, xử lý sự cố | [docs/faq.md](docs/faq.md) |
| Công cụ quét sâu | [tools/deep-scan/README.md](tools/deep-scan/README.md) |
| Dựng cơ sở dữ liệu cookie | [tools/build-known-db/README.md](tools/build-known-db/README.md) |
| Lịch sử thay đổi | [CHANGELOG.md](CHANGELOG.md) |

## Phát triển

Mã nguồn PHP nằm trong `src/` (namespace `DSolutions\CookieConsent`, tự nạp bằng autoloader riêng nên plugin chạy không cần `composer install`; chỉ thư viện đã đổi tiền tố trong `vendor-prefixed/` là bắt buộc). JS/CSS nguồn ở `assets/src/`, bản đóng gói ở `assets/dist/`.

```bash
composer install && npm install
npm run build                  # đóng gói JS/CSS vào assets/dist
npm run size                   # kiểm ngân sách dung lượng
composer test:unit             # PHPUnit + Brain Monkey
npm run env:start              # wp-env: site dev :8888, site test :8889
npm run test:php:integration   # PHPUnit tích hợp trong wp-env
npm run test:e2e               # Playwright, chạy trên site dev :8888
npm run lint:php               # phpcs (WordPress Coding Standards)
npm run lint:php74             # php -l bằng PHP 7.4 (Docker)
npm run i18n                   # sinh lại languages/*.pot, .po, .mo (cần wp-env đang chạy)
```

Quy ước chính: mã, comment và tên hàm bằng tiếng Anh; chuỗi giao diện quản trị viết gốc tiếng Anh với text domain `ds-cookie-consent`, bản dịch tiếng Việt nằm trong `languages/`; chữ cho khách nằm trong `src/Settings/DefaultTexts.php`. Dữ liệu thử dùng tiền tố `LittleD`. Lệnh chạy chính xác của từng việc nằm trong `package.json` và `composer.json`.

Cách mở rộng bằng hook: [docs/developer-hooks.md](docs/developer-hooks.md).

## Phát hành

Quy trình tóm tắt (chi tiết ở [docs/release-and-updates.md](docs/release-and-updates.md)):

1. Nâng phiên bản đồng nhất ở header `ds-cookie-consent.php`, hằng `DSCC_VERSION`, `Stable tag` trong `readme.txt` và thêm mục `## x.y.z` vào `CHANGELOG.md`.
2. `npm run release:zip` để thử dựng gói trên máy.
3. Gắn tag `vX.Y.Z` và đẩy lên GitHub: workflow `release.yml` chạy CI, dựng gói, kiểm tra tag khớp phiên bản plugin rồi tạo GitHub Release kèm `ds-cookie-consent.zip`.

## Giấy phép và liên hệ

- Giấy phép: GPL-2.0-or-later. Cơ sở dữ liệu cookie có đối chiếu với Open Cookie Database (Apache-2.0): xem `data/LICENSE-open-cookie-database.txt` và `data/NOTICE`.
- Tác giả: **D-Solutions** (Công ty TNHH Công nghệ Truyền thông D-Solutions, D-Solutions Technology Media Co., Ltd), https://d-solutions.vn/.
- Báo lỗi, góp ý: [GitHub Issues](https://github.com/D-SOLUTIONS-TECHNOLOGY-MEDIA-CO-LTD/ds-cookie-consent/issues).

---

## English

**DS Cookie Consent** is a WordPress cookie consent plugin by D-Solutions, built to be reused across many sites. It asks visitors for their choice, blocks tracking scripts until they agree, logs the choices and helps you build the site's cookie list.

**Features**

- Two layouts (compact card, horizontal bar with a three-tab popup), live preview, contrast warnings, per-vendor switches, Vietnamese and English visitor texts (WPML, Polylang and GTranslate aware).
- Google Consent Mode v2 (defaults, updates, regions, URL passthrough) from a head script under 1.5 KB; page-cache friendly markup.
- Consent log with masked IPs, CSV export and retention.
- Prior blocking of scripts, iframes and pixels, a server-side cookie guard, an "observe only" mode, exclusions, a GTM mode and an unclassified-cookie policy.
- Platform picker backed by a known-cookie database (hash-verified updates), custom platforms and a cookie table with bulk actions.
- Scanner: server scan (HTML, GTM containers, `Set-Cookie`), optional visitor beacon (names only), compliance report with quick classification actions, and a headless deep scan tool (Docker, n8n, cron) that reports through a REST endpoint with an Application Password.
- Shortcodes `[dscc_cookie_settings]`, `[dscc_cookie_table]`, `[dscc_last_scan]`; `window.DSCC` API; `dataLayer` and DOM events; JSON export/import with a preview and bundled presets.
- Integrations: Google Site Kit, PixelYourSite, Meta Pixel for WordPress, LiteSpeed Cache, WP Rocket, WP Consent API, chat widgets.
- English admin source strings with a complete Vietnamese translation.

**Requirements**: WordPress 6.0+, PHP 7.4+.

**Install**: download `ds-cookie-consent.zip` from [Releases](https://github.com/D-SOLUTIONS-TECHNOLOGY-MEDIA-CO-LTD/ds-cookie-consent/releases), then Plugins > Add New > Upload Plugin > Activate. Updates arrive through the normal WordPress update screen (repository can be changed with the `DSCC_UPDATE_REPO` constant; no token is needed for the public repository).

**Quick start**: choose the layout on Appearance, set the Cookie Policy page on Content, tick your platforms on Platforms, keep blocking on (use Observe only for the first week on busy sites), run *Scan now* on Cookies, then place `[dscc_cookie_table]` on the policy page.

**Documentation** (Vietnamese): [docs/README.md](docs/README.md). Developer hooks: [docs/developer-hooks.md](docs/developer-hooks.md). The plugin is a technical tool and does not make a site legally compliant by itself.

**Development**: `composer install && npm install`, `npm run build`, `composer test:unit`, `npm run env:start`, `npm run test:php:integration`, `npm run test:e2e`. Releases are built by `tools/release/build-zip.sh` and published by the `release.yml` workflow on a `vX.Y.Z` tag.

**License**: GPL-2.0-or-later. **Contact**: D-Solutions Technology Media Co., Ltd, https://d-solutions.vn/, issues on GitHub.
