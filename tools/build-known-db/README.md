# build-known-db

Công cụ dựng hai thứ cho plugin DS Cookie Consent:

- `data/known-cookies.json` (kèm `data/known-cookies.json.sha256`): **cơ sở dữ liệu cookie đã biết**, nguồn của bộ chọn nền tảng và của luật nhận diện/chặn script. Xem cách plugin dùng ở [`docs/platforms-and-cookies.md`](../../docs/platforms-and-cookies.md).
- `presets/d-solutions-standard.json`: **bộ mẫu** "D-Solutions — Mẫu tiêu chuẩn", sinh từ cơ sở dữ liệu trên và `preset-definition.json`.

Yêu cầu Node 20 trở lên; không có gói phụ thuộc nào cần cài.

## Nguồn dữ liệu

- `overlay-services.json`: nguồn chính, do D-Solutions tự duy trì (nền tảng, cookie, mục đích và thời hạn tiếng Việt/Anh, `script_patterns`).
- [Open Cookie Database](https://github.com/jkwakman/Open-Cookie-Database) (Apache-2.0), ghim theo commit trong `ocd-source.json`: **chỉ dùng để đối chiếu** tên cookie và thời hạn. Không có dữ liệu nào bị tự chép vào file kết quả. Mất mạng thì bước đối chiếu bị bỏ qua và file vẫn sinh đúng từ overlay.
- Giấy phép và ghi nguồn: `data/LICENSE-open-cookie-database.txt`, `data/NOTICE`.

## Dựng lại

```bash
cd tools/build-known-db
npm run build                        # sinh data/known-cookies.json và .sha256
npm run build:offline                # bỏ qua bước đối chiếu Open Cookie Database
node build-known-db.mjs --check      # báo lỗi nếu file trong data/ chưa cập nhật
node build-preset.mjs                # sinh lại presets/d-solutions-standard.json
node build-preset.mjs --check        # báo lỗi nếu bộ mẫu chưa cập nhật
npm test
```

Chạy `build-preset.mjs` sau mỗi lần đổi cơ sở dữ liệu cookie hoặc `preset-definition.json`. CI chạy `npm test` và `node tools/build-known-db/build-preset.mjs --check`, nên bộ mẫu đã commit phải đúng với định nghĩa của nó.

Kết quả có thứ tự cố định (nền tảng theo `slug`, cookie theo tên): chạy lại với cùng overlay cho ra file giống từng byte. Khi sửa overlay, tăng `meta.version` và `meta.generated_at`. Tuỳ chọn khác của `build-known-db.mjs`: `--out <thư mục>`, `--overlay <file>`, `--now <iso>`.

Gói phát hành kiểm `data/known-cookies.json` khớp với file `.sha256` và với trường `sha256` bên trong; xem [`docs/release-and-updates.md`](../../docs/release-and-updates.md).

## Quy ước dữ liệu

- Tên cookie có dấu `*` là mẫu (`is_pattern = true`). Khi hiển thị cho khách, `*` đứng sau `_` hoặc `-` được viết thành `<mã>` (`_ga_*` thành `_ga_<mã>`, `_gat_UA-*` thành `_gat_UA-<mã>`); các mẫu khác giữ nguyên (`ttcsid*`, `_sp_id.*`).
- Thời hạn có khoảng (ví dụ "365–400 ngày") lưu `duration_seconds` theo giá trị lớn nhất; cookie phiên hoặc không xác định là `null`.
- `storage` là một trong `cookie`, `localStorage`, `sessionStorage`, `indexedDB`, `pixel`.
- `script_patterns` mô tả cách nhận diện và chặn nền tảng:
  - `hosts`: khớp host của `src`/`href` (bằng hoặc là subdomain);
  - `src_regex`, `inline_regex`: regex không phân biệt hoa thường, **không có dấu phân cách** (PHP bọc bằng `~…~i`, nên không dùng ký tự `~`).
- Nhóm (`category`) của nền tảng quyết định nhóm của mọi cookie trong nó: `necessary`, `functional`, `analytics`, `marketing`.

## Bộ mẫu

`preset-definition.json` liệt kê nền tảng và cookie đưa vào bộ mẫu, trạng thái của từng mục, thứ tự dòng và cài đặt (hiện để trống: bộ mẫu không đổi cài đặt nào của plugin). `build-preset.mjs` ghép định nghĩa này với `data/known-cookies.json` ở định dạng xuất `dscc-export` và ghi `presets/d-solutions-standard.json`; nền tảng nào không có trong cơ sở dữ liệu phải có định nghĩa inline, nếu không bước dựng báo lỗi.

Bộ mẫu **không bao giờ tự áp dụng**: quản trị viên chọn nó ở **Cài đặt > Nhập / Xuất**, xem trước rồi bấm Áp dụng.

## Đổi commit Open Cookie Database

1. Lấy SHA mới: `gh api repos/jkwakman/Open-Cookie-Database/commits/master --jq .sha`.
2. Sửa `commit` trong `ocd-source.json`, đặt `csv_sha256` thành `null`.
3. `npm run build` (tải CSV về `.cache/`, in cảnh báo nếu thời hạn lệch); đọc phần "retention differs" và sửa overlay nếu cần.
4. Điền `csv_sha256` bằng `shasum -a 256 .cache/ocd-<sha>.csv`, cập nhật SHA trong `data/NOTICE`, chạy `npm test`.

## Xuất bản cho tính năng "Cập nhật danh mục cookie"

Plugin tải file JSON từ URL cấu hình được (tab Nâng cao hoặc bộ lọc `dscc_known_db_url`) và kiểm SHA-256 trước khi nạp. Đăng cả hai file, cùng thư mục, cùng phiên bản:

- `known-cookies.json`
- `known-cookies.json.sha256` (dạng `sha256sum`: `<64 hex>  known-cookies.json`, là SHA-256 của toàn bộ byte file JSON)

Ví dụ: đính kèm hai file vào GitHub Release, hoặc commit vào nhánh `main` của repo D-Solutions rồi dùng URL raw. Trường `sha256` bên trong JSON là SHA-256 của mảng `services` ở dạng chuẩn hoá: sắp key đệ quy, JSON gọn, không escape Unicode và dấu `/` (PHP: `ksort` đệ quy rồi `json_encode(..., JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`). Plugin từ chối file có `.sha256` không khớp, file không hợp lệ, hoặc file không có mã kiểm tra nào.
