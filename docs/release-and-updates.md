# Phát hành và cập nhật

Plugin được phát hành qua **GitHub Releases** của repo công khai `D-SOLUTIONS-TECHNOLOGY-MEDIA-CO-LTD/ds-cookie-consent`, và mỗi site tự cập nhật từ đó bằng bộ cập nhật đi kèm. Nguồn: [`src/Update/`](../src/Update/), [`tools/release/`](../tools/release/), [`.github/workflows/`](../.github/workflows/).

## Bộ cập nhật trên site

Bộ cập nhật (`GitHubUpdater`, `ReleaseFeed`, `PackageDownloader`, `ReleaseParser`) đưa plugin vào cơ chế cập nhật chuẩn của WordPress, nên bản mới hiện ở trang Plugin và hoạt động với cập nhật tự động, WP-CLI và công cụ quản lý từ xa (MainWP, ManageWP...).

**Cách hoạt động**

- Đọc `https://api.github.com/repos/<repo>/releases/latest`. Câu trả lời được cache trong site transient `dscc_gh_release`: **12 giờ** cho một bản phát hành (hoặc "chưa có bản nào"), **1 giờ** cho mọi lỗi (lỗi mạng, chuyển hướng, mã khác, nội dung không dùng được).
- Chỉ nhận bản phát hành đã công bố: **bản nháp và bản tiền phát hành bị bỏ qua**. Tag phải có dạng số phiên bản (ví dụ `v1.0.0`); bản phát hành phải có asset đúng tên `ds-cookie-consent.zip`.
- Bản mới = phiên bản của tag lớn hơn `DSCC_VERSION` đang cài.
- Yêu cầu tới GitHub không theo chuyển hướng (chuyển hướng được coi là lỗi). Nút **Kiểm tra lại** của WordPress (`force-check`) bỏ qua cache, chỉ với người có quyền cập nhật plugin.
- Gói được tải về qua `upgrader_pre_download`; nếu GitHub công bố mã SHA-256 (`digest`) của asset thì file tải về được kiểm và **bị huỷ nếu không khớp**. Khi bản phát hành chưa có mã nào, quản trị viên thấy thông báo rằng gói không kiểm tra được trước khi cài.
- Thư mục giải nén được đổi tên cho khớp thư mục plugin đang cài.
- Sau khi cập nhật xong, cache bản phát hành bị xoá và trang chủ được gọi một lần (với query ngẫu nhiên để vượt cache trang) để phần nâng cấp cơ sở dữ liệu chạy ngay. Nhờ vậy việc nâng cấp không phụ thuộc vào việc có quản trị viên mở trang admin.

**Hằng số trong `wp-config.php`**

| Hằng | Mặc định | Ý nghĩa |
|---|---|---|
| `DSCC_UPDATE_REPO` | `D-SOLUTIONS-TECHNOLOGY-MEDIA-CO-LTD/ds-cookie-consent` | Repo dạng `chủ/tên` (chữ, số, `_ . -`). Sai định dạng thì bộ cập nhật tắt |
| `DSCC_GITHUB_TOKEN` | không có | Tuỳ chọn. Repo công khai không cần; chỉ dùng để tăng giới hạn gọi API hoặc cho một bản fork riêng tư. Token chỉ gửi trong header tới `api.github.com`, không nằm trong URL và không chuyển tiếp |

Cùng hai giá trị này có thể chỉnh bằng bộ lọc [`dscc_update_repo`](developer-hooks.md#dscc_update_repo) và [`dscc_github_token`](developer-hooks.md#dscc_github_token).

**Máy chủ plugin liên hệ**: `api.github.com` và `github.com` cùng các host tải (`objects.githubusercontent.com`, `release-assets.githubusercontent.com`) cho gói cập nhật, và URL cơ sở dữ liệu cookie nếu bạn cấu hình. Quét chỉ tải trang của chính site.

## Quy trình phát hành

1. **Nâng phiên bản** đồng nhất tại bốn chỗ: header `Version` và hằng `DSCC_VERSION` trong `ds-cookie-consent.php`, `Stable tag` và mục `= x.y.z =` ở phần Changelog của `readme.txt`. Thêm mục `## x.y.z` vào `CHANGELOG.md` (dùng làm ghi chú phát hành). `verify-package.php` thất bại nếu các giá trị này lệch nhau.
2. **Thử dựng gói** trên máy: `npm run release:zip` (xem bên dưới). Nếu cần kiểm tra kích hoạt thật trong wp-env: `npm run check:zip-activation`.
3. **Gắn tag** `vX.Y.Z` đúng bằng phiên bản plugin và đẩy lên GitHub. Workflow `release.yml` làm phần còn lại.
4. Kiểm tra bản phát hành có asset `ds-cookie-consent.zip` và ghi chú; thử cập nhật trên một site staging.

Tag có dấu `-` (ví dụ `v1.1.0-rc1`) được đăng như *pre-release*, và bộ cập nhật bỏ qua nó.

Trước khi giao cho khách, kiểm tra trên bản sao (staging) của site thật (cùng plugin cache, CDN, Meta Pixel, PixelYourSite, Site Kit...): môi trường phát triển không có những thứ này.

## `tools/release/build-zip.sh`

```bash
bash tools/release/build-zip.sh [thư-mục-đầu-ra]    # mặc định: thư mục gốc repo
```

Biến môi trường: `DSCC_RELEASE_TAG` (tag đang phát hành, phiên bản plugin phải khớp), `DSCC_ZIP_FAST=1` (bỏ qua composer/npm, đóng gói bản dựng đã commit, **chỉ để kiểm nhanh, không dùng để phát hành**). Cần `rsync`, `zip`, `unzip`, `php`, và `composer`, `npm` (trừ chế độ nhanh).

Các bước, mọi thứ chạy trong thư mục tạm nên cây làm việc không bị đụng tới:

1. chép mã nguồn;
2. `composer install --no-dev` và đổi tiền tố thư viện bằng Strauss (`tools/release/fetch-strauss.sh` tải Strauss và từ chối nếu SHA-256 không khớp bản ghim);
3. `npm ci` và đóng gói JS/CSS;
4. chép những gì `.distignore` cho phép vào `ds-cookie-consent/`;
5. `tools/release/verify-package.php`;
6. nén thành `ds-cookie-consent.zip` (đúng một thư mục gốc `ds-cookie-consent/`), giải nén ra kiểm lần nữa.

`verify-package.php` thất bại khi: có đường dẫn phát triển (`tests/`, `tools/`, `docs/`, `vendor/`, `node_modules/`, file cấu hình, tệp hướng dẫn cho tác tử...), thiếu file chạy cần thiết (mã, `assets/dist`, `data/`, `presets/`, `languages/`, `vendor-prefixed/`), phiên bản lệch giữa header/hằng/`readme.txt`/tag, `readme.txt` không có mục changelog cho phiên bản, cơ sở dữ liệu cookie không khớp mã SHA-256 của nó, hoặc có file chứa từ bị cấm. Gói phát hành có `data/`, `presets/`, `languages/` và `assets/dist/`, không có tài liệu `docs/` hay công cụ trong `tools/`.

Các kiểm tra phụ:

| Lệnh | Việc làm |
|---|---|
| `npm run check:zip-activation` | Cài gói vào site kiểm thử của wp-env dưới tên thư mục khác, kích hoạt, kiểm, rồi trả site về trạng thái cũ (cần wp-env đang chạy) |
| `npm run check:deep-scan-image` | `docker build` và `docker run --help` cho image quét sâu |
| `npm run lint:workflows` | Lint các workflow bằng image actionlint đã ghim |

## CI và workflow

**`ci.yml`** chạy khi đẩy lên `main`, mở pull request, và được `release.yml` gọi lại. Các job: phpcs (chuẩn WordPress); `php -l` trên PHP 7.4 và 8.3; test đơn vị trên PHP 7.4 và 8.3; bản JS/CSS đã commit phải khớp bản dựng mới và qua ngân sách dung lượng; test Node (công cụ quét sâu, công cụ dựng cơ sở dữ liệu, và kiểm bộ mẫu sinh từ định nghĩa); dựng image Docker quét sâu và chạy `--help`; danh mục bản dịch biên dịch được và không còn chuỗi chưa dịch; lint workflow; dựng gói phát hành và lưu làm artifact 7 ngày.

**`release.yml`** chạy khi đẩy tag `v*`:

1. `ci`: chạy toàn bộ CI trên commit đó.
2. `build`: dựng gói với `DSCC_RELEASE_TAG` (thất bại nếu tag khác phiên bản plugin), tách ghi chú từ mục `## x.y.z` của `CHANGELOG.md` (thất bại nếu không có), tạo file `.sha256`; chạy với quyền chỉ đọc.
3. `publish`: job riêng có quyền ghi, kiểm lại mã SHA-256 của gói rồi `gh release create` kèm `ds-cookie-consent.zip` (thêm `--prerelease` nếu tag có dấu `-`).

Mọi action của bên thứ ba được ghim bằng mã commit đầy đủ.

## Phiên bản cơ sở dữ liệu cookie

Cơ sở dữ liệu cookie đã biết (`data/known-cookies.json`) có phiên bản riêng, độc lập với phiên bản plugin; gói phát hành kiểm file này khớp với `.sha256` của nó. Xem cách dựng và xuất bản ở [`tools/build-known-db/README.md`](../tools/build-known-db/README.md).
