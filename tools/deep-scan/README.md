# DS Cookie Consent – Deep Scan

Công cụ quét sâu bằng trình duyệt headless (Node + Playwright), chạy ngoài WordPress. Dùng để lập danh mục cookie đầy đủ nhất và kiểm tra tuân thủ: cookie Phân tích/Tiếp thị xuất hiện trước khi khách chọn hoặc sau khi từ chối là vi phạm.

Công cụ cần plugin DS Cookie Consent đang chạy trên site (để có banner và `window.DSCC`). Kết quả gửi về site bằng `--push` và hiện ở **Cookie Consent > Tổng quan** (khối *Cảnh báo tuân thủ*). Bức tranh chung giữa các cách quét: [`docs/scanner-and-compliance.md`](../../docs/scanner-and-compliance.md).

## Bắt đầu nhanh

```bash
cd tools/deep-scan
docker build -t dscc-deep-scan .
docker run --rm --ipc=host dscc-deep-scan https://example.com > report.json   # chỉ in báo cáo ra stdout, chưa gửi đi đâu
```

Muốn gửi kết quả về site, tạo Application Password rồi thêm `--push` (xem [Gửi kết quả về site](#gửi-kết-quả-về-site-application-password)).

## Công cụ làm gì

Với mỗi site, chạy 3 giai đoạn:

1. `before_choice`: context sạch, mở trang chủ, chờ 8 giây (đổi bằng `--wait`), ghi nhận trạng thái khi CHƯA chọn.
2. `after_reject`: bấm nút Từ chối (`[data-dscc-action="reject"]`), tải lại 3 trang (trang chủ + tối đa 2 link nội bộ cùng host, hoặc danh sách `--urls`), ghi nhận.
3. `after_accept`: context mới hoàn toàn, bấm Đồng ý (`[data-dscc-action="accept"]`), tải lại 3 trang, ghi nhận.

Mỗi giai đoạn ghi: danh sách trang đã tải, cookie của mọi domain (name, domain, path, httpOnly, secure, sameSite, expires; `expires = -1` là cookie phiên), KEY của localStorage/sessionStorage theo origin, host của mọi request, và tên cookie trong header `Set-Cookie` theo host.

**Không bao giờ ghi giá trị cookie hay giá trị storage.**

Nếu không thấy nút trên banner, báo cáo có `banner_found: false`, tool vẫn quét và ghi dữ liệu 3 giai đoạn (không click). Lỗi từng trang nằm trong `errors[]`.

Lưu ý: dữ liệu `after_reject` là trạng thái cookie tại thời điểm đó (cookie đã tồn tại từ giai đoạn 1 mà plugin chưa xoá vẫn hiện ra), đúng với mục đích kiểm tra tuân thủ.

## Chạy trực tiếp

Yêu cầu Node 20+.

```bash
cd tools/deep-scan
npm install
npx playwright install chromium

node deep-scan.mjs https://example.com --report report.json
```

Tuỳ chọn:

| Tuỳ chọn | Ý nghĩa |
|---|---|
| `--urls a,b,c` | Danh sách trang cần quét (tương đối hoặc tuyệt đối), thay cho tự tìm link |
| `--pages 3` | Số trang mỗi giai đoạn khi tự tìm link |
| `--wait 8000` | Thời gian chờ (ms) của giai đoạn `before_choice` |
| `--report out.json` | Ghi báo cáo ra file (mặc định in ra stdout, log ra stderr) |
| `--push` | Gửi báo cáo về `<site>/wp-json/dscc/v1/scan-result` (chỉ https, hoặc loopback; xem mục bảo mật bên dưới) |
| `--user U` | Tài khoản WordPress (hoặc env `DSCC_USER`) |
| `--app-password-file F` | File chứa Application Password (hoặc env `DSCC_APP_PASSWORD`). **Không có** cờ `--app-password`: mật khẩu trên dòng lệnh lộ qua `ps` và log; truyền cờ này sẽ báo lỗi |
| `--site-url URL` | Địa chỉ công khai của site: dùng làm `site` trong báo cáo và làm đích gửi. Đối số đầu tiên chỉ là nơi trình duyệt vào (ví dụ tên nội bộ trong Docker) |
| `--resolve host=ip` | Ép trình duyệt phân giải `host` thành `ip` (Chromium `--host-resolver-rules`), lặp lại được |
| `--allow-insecure` | Cho phép `--push` qua `http://` tới host không phải loopback (mật khẩu đi dạng rõ) |
| `--reject-selector` / `--accept-selector` | Selector nút; mặc định theo hợp đồng DOM của plugin |

Mã thoát: `0` thành công, `1` lỗi (kể cả push thất bại), `2` sai tham số.

Chạy test: `npm test`.

## Docker

Image dựa trên `mcr.microsoft.com/playwright:v1.49.1-noble` (khớp phiên bản playwright trong `package.json`), chạy bằng user không phải root.

```bash
cd tools/deep-scan
docker build -t dscc-deep-scan .

# In báo cáo ra stdout
docker run --rm --ipc=host dscc-deep-scan https://example.com > report.json

# Ghi file báo cáo ra thư mục hiện tại (uid 1000 của pwuser cần quyền ghi)
docker run --rm --ipc=host -v "$PWD/out:/out" dscc-deep-scan https://example.com --report /out/report.json
```

`--ipc=host` giúp Chromium không hết bộ nhớ chia sẻ.

> Việc dựng image đã được kiểm chứng: từ thư mục gốc repo, `npm run check:deep-scan-image` chạy `docker build` rồi `docker run --rm <image> --help`; job CI "Deep scan Docker image" chạy đúng hai lệnh đó trên mỗi lần đẩy code.

### Chạy cứng hoá (khuyến nghị)

Tool không cần quyền gì ngoài mạng và `/tmp`:

```bash
docker run --rm --ipc=host --cap-drop=ALL --security-opt no-new-privileges --read-only --tmpfs /tmp \
  --env-file /etc/dscc/deep-scan.env dscc-deep-scan https://example.com --push
```

(Ghi báo cáo ra file thì mount thêm một thư mục `-v "$PWD/out:/out"`; với `--read-only` chỉ thư mục đó và `/tmp` ghi được.)

### Site chỉ truy cập được bằng tên nội bộ

Server kiểm `site` trong báo cáo phải là host của chính site (422 nếu khác). Khi container phải vào site bằng tên/IP nội bộ, giữ `site` là địa chỉ công khai:

```bash
# tên công khai phân giải về máy chủ Docker (host-gateway) ngay trong container
docker run --rm --ipc=host --add-host=shop.example.com:host-gateway \
  --env-file /etc/dscc/deep-scan.env dscc-deep-scan https://shop.example.com --push

# hoặc vào bằng địa chỉ nội bộ nhưng báo cáo/gửi theo địa chỉ công khai
docker run --rm --ipc=host --env-file /etc/dscc/deep-scan.env dscc-deep-scan http://web.internal \
  --site-url https://shop.example.com --resolve shop.example.com=10.0.0.5 --push
```

Muốn server chấp nhận thêm host (ví dụ tên nội bộ dùng trong `site`), thêm filter phía plugin: `add_filter( 'dscc_scan_own_hosts', fn( $hosts ) => array_merge( $hosts, array( 'web.internal' ) ) );`.

## Gửi kết quả về site (Application Password)

1. Trong WordPress: Users → Profile của một user có quyền `manage_options` → mục Application Passwords → tạo mật khẩu mới (ví dụ tên `deep-scan`).
2. Lưu mật khẩu vào file chỉ chủ sở hữu đọc được, không đưa lên dòng lệnh:

```bash
sudo install -d -m 700 /etc/dscc
sudo sh -c 'umask 077; printf "DSCC_USER=admin-user\nDSCC_APP_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx\n" > /etc/dscc/deep-scan.env'
sudo chmod 600 /etc/dscc/deep-scan.env
```

Với `--env-file` của Docker, giá trị không cần ngoặc kép. Chạy trực tiếp (không Docker) có thể dùng `--user` + `--app-password-file /etc/dscc/app-password` (một dòng chứa mật khẩu, `chmod 600`).

3. Chạy với `--push`:

```bash
docker run --rm --ipc=host --env-file /etc/dscc/deep-scan.env dscc-deep-scan https://example.com --push
```

Tool in `HTTP <status>` của phản hồi; thoát với mã khác 0 nếu không phải 2xx.

### Bảo mật khi gửi

- Mật khẩu đi trong header Basic, nên tool **từ chối** `--push` tới `http://` trừ khi host là loopback (`localhost`, `127.0.0.1`, `::1`) hoặc có `--allow-insecure`. WordPress cũng chỉ bật Application Password trên HTTPS (hoặc môi trường `local`).
- Tool không theo chuyển hướng (`redirect: manual`) vì header xác thực không được gửi sang địa chỉ khác. Khi gặp 301/302/401 tool in gợi ý:
  - dùng địa chỉ cuối cùng (https) làm `--site-url`;
  - sau proxy/CDN, chuyển tiếp `X-Forwarded-Proto: https` để WordPress biết đây là HTTPS;
  - header `Authorization` phải tới được PHP. Apache (CGI/FastCGI) hay làm rơi header này; thêm vào `.htaccess` trước khối WordPress: `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`. Nginx: `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`.
- Site dùng liên kết tĩnh dạng "Plain" (không có `/wp-json/`): tool tự thử lại bằng `/?rest_route=`.

Không đưa mật khẩu vào file workflow hay lệnh lưu trong lịch sử; dùng env-file, file mật khẩu hoặc credential store của n8n.

## Lên lịch bằng n8n hoặc cron

### n8n

Import `n8n-workflow-example.json` (Workflows → Import from File). Luồng: Schedule Trigger (hằng tuần) → Execute Command (chạy container, `--push`) → nếu lệnh lỗi (nhánh error) → HTTP Request tuỳ chọn (gọi webhook báo lỗi). Điều chỉnh:

- Node Execute Command cần chạy trên host có Docker (n8n self-host, không phải n8n Cloud). Node này bị tắt mặc định ở n8n 2.x: bật bằng `NODES_EXCLUDE=[]`.
- Thay `https://example.com` bằng site thật; `DSCC_USER` và `DSCC_APP_PASSWORD` nằm trong `/etc/dscc/deep-scan.env` (chmod 600) trên máy chạy n8n, không đưa vào workflow.
- Đổi URL webhook ở node cảnh báo, hoặc xoá node đó nếu không cần.

### cron

```cron
# 03:00 sáng thứ Hai hằng tuần. Mật khẩu nằm trong /etc/dscc/deep-scan.env (chmod 600), không nằm trong crontab.
0 3 * * 1 docker run --rm --ipc=host --cap-drop=ALL --security-opt no-new-privileges --read-only --tmpfs /tmp --env-file /etc/dscc/deep-scan.env dscc-deep-scan https://example.com --push >> /var/log/dscc-deep-scan.log 2>&1
```

## Cấu trúc báo cáo (`schema: 1`)

```json
{
  "schema": 1,
  "site": "https://example.com",
  "scanned_at": "2026-11-07T03:00:00.000Z",
  "tool_version": "1.0.0",
  "plugin_detected": true,
  "banner_found": true,
  "phases": {
    "before_choice": {
      "pages": ["https://example.com/"],
      "cookies": [{"name": "_fbp", "domain": ".example.com", "path": "/", "httpOnly": false, "secure": true, "sameSite": "Lax", "expires": 1799000000}],
      "storage": {"local": [{"origin": "https://example.com", "key": "k"}], "session": []},
      "hosts": ["example.com", "www.googletagmanager.com"],
      "set_cookies": [{"host": "example.com", "names": ["_fbp"]}]
    },
    "after_reject": {},
    "after_accept": {}
  },
  "errors": []
}
```

(`after_reject` và `after_accept` cùng cấu trúc với `before_choice`.) `plugin_detected` là `true` khi trang có đối tượng `window.DSCC`.

## Phía plugin: nhận và hiển thị kết quả

`POST /wp-json/dscc/v1/scan-result` (plugin DS Cookie Consent):

- Xác thực bằng Application Password của user có `manage_options`: không đăng nhập trả 401, user thiếu quyền trả 403.
- Nội dung tối đa 2 MB (413), phải là JSON đúng `schema: 1` (400 nếu sai kiểu, thừa khoá, hoặc có `value` của cookie). Địa chỉ `site` trong báo cáo phải là host của chính site nhận (422 nếu khác): hãy quét bằng địa chỉ công khai của site.
- Cookie của chính host (kể cả domain cha/con) có tên trong header `Set-Cookie` của host đó được lưu là cookie do server ghi; còn lại là do script ghi hoặc bên thứ ba. Chỉ cookie do server ghi và chưa phân loại mới có thể bị chặn khi chế độ cookie chưa phân loại là "Chặn".
- `scanned_at` phải theo ISO-8601, không quá 10 phút trong tương lai và không cũ quá 30 ngày; giai đoạn `before_choice` phải tải được ít nhất một trang (`pages` không rỗng), nếu không trả 400.
- Lần quét "kết luận được" khi đã tải trang, thấy nút trên banner và trang có `window.DSCC`. Nếu không, thẻ "Quét sâu gần nhất" hiện cảnh báo "Lần quét chưa kết luận được: ..." thay vì dòng xanh "không có vi phạm", và cảnh báo quét sâu của lần trước được giữ nguyên.
- Phản hồi 200 có `platforms_created` (nền tảng mới do lần quét tạo); thẻ quét sâu liệt kê chúng kèm liên kết tới tab Nền tảng. Host của nhà cung cấp được so khớp theo `script_patterns.hosts` của danh mục (host hoặc subdomain), nên chỉ cần liên hệ tới host (ví dụ `connect.facebook.net`) cũng là vi phạm nếu nhà cung cấp thuộc nhóm Phân tích/Tiếp thị.
- Vi phạm = cookie / khoá storage / host dịch vụ nhóm Phân tích hoặc Tiếp thị xuất hiện ở `before_choice` hoặc `after_reject`. Danh sách nằm ở khối "Cảnh báo tuân thủ" (Tổng quan và tab Cookie); khi bộ chặn đang bật đúng thì danh sách trống.

Dùng lập trình (kiểm thử): `runScan({ ...opts, prepareContext: async (ctx) => {...} })` cho phép chặn/giả lập request của host bên thứ ba.
