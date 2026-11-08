# Chặn script trước khi khách đồng ý

Tài liệu này giải thích **vì sao** bộ chặn hoạt động như vậy và **hợp đồng** thuộc tính HTML mà nó và trình duyệt dùng chung. Mã nguồn chính: [`src/Blocker/`](../src/Blocker/) (phía server) và [`assets/src/js/script-unblocker.js`](../assets/src/js/script-unblocker.js) (phía trình duyệt). Các tuỳ chọn và mặc định nằm ở [configuration.md](configuration.md#chặn-script-blocking).

## Nguyên tắc

- Bộ chặn chỉ động tới nền tảng bạn đã **tick** ở tab Nền tảng (và nền tảng chưa phân loại, nếu bật `block_unclassified`). Luật được dựng từ danh mục: host của script, regex URL, regex nội dung inline.
- Việc đổi HTML **không phụ thuộc khách**: mọi khách nhận cùng một HTML. Vì vậy cache trang toàn phần không làm sai kết quả. Trình duyệt mới là nơi quyết định bật lại.
- Lỗi của bộ chặn luôn **mở**: khi gặp sự cố, trang được trả nguyên vẹn thay vì hỏng (xem [Sự cố bộ chặn](#sự-cố-bộ-chặn)).
- Chặn là mặc định (`mode = block`).

## Chế độ chặn và chế độ quan sát

| | Chặn (`block`) | Chỉ quan sát (`observe`) |
|---|---|---|
| Đổi HTML | Có | **Không** |
| Xoá/gỡ cookie | Có (bộ lọc server và trình duyệt) | **Không** |
| Tích hợp (Meta Pixel, PixelYourSite, WP Consent API) | Hoạt động | Không chặn gì |
| Ghi nhận | Số lượt chặn | Báo cáo script sẽ bị chặn |

Ở chế độ quan sát, plugin ghi lại script mà chế độ chặn sẽ động tới (tối đa 30 nền tảng, 3 ví dụ mỗi nền tảng) và hiện thành bảng **Báo cáo chế độ quan sát** ở tab Chặn script. Báo cáo chỉ được ghi khi trang chạy qua PHP; trang lấy từ cache không chạy bộ chặn nên không được ghi. Có nút **Xoá báo cáo**.

Quy trình khuyến nghị cho site đã có lưu lượng thật:

1. Đặt *Chỉ quan sát* khoảng 7 ngày (đủ ngày thường, cuối tuần, chiến dịch).
2. Đọc báo cáo: nền tảng nào bị liệt kê có đúng là thứ cần chặn không? Cái cần chạy luôn thì thêm vào [danh sách loại trừ](#danh-sách-loại-trừ).
3. Chạy **Quét ngay** ở tab Cookie và phân loại cookie mới.
4. Chuyển sang *Chặn script trước khi có đồng ý*, rồi kiểm tra trong cửa sổ ẩn danh: trước khi bấm Đồng ý không có `_ga`, `_fbp`... trong cookie.

## Hợp đồng thuộc tính `data-dscc-*`

Khi chặn, bộ chặn viết lại thẻ như sau, và trình duyệt chỉ cần các thuộc tính này để bật lại:

| Thẻ gốc | Sau khi chặn |
|---|---|
| `<script src="URL">` | `<script type="text/plain" data-dscc-category="C" data-dscc-service="S" data-no-optimize="1" data-dscc-src="URL">` |
| `<script type="module" src="URL">` | như trên, kèm `data-dscc-type="module"` (kiểu gốc được giữ để khôi phục) |
| `<script>…inline…</script>` | `<script type="text/plain" data-dscc-category="C" data-dscc-service="S" data-no-optimize="1">…</script>` |
| `<iframe src="URL">` | `<iframe data-dscc-src="URL" data-dscc-category="C" data-dscc-service="S" hidden>` đứng sau một khung `.dscc-placeholder` có nút cho phép |
| `<img src="URL">` (pixel theo dõi) | `<img data-dscc-src="URL" data-dscc-category="C" data-dscc-service="S">` |

Giải thích:

- `data-dscc-category`: nhóm cần được phép (`analytics`, `marketing`, `functional`). Giá trị có thể gồm nhiều nhóm cách nhau bằng khoảng trắng; chỉ cần **một** nhóm được phép.
- `data-dscc-service`: slug nền tảng. Nếu nền tảng có công tắc riêng trong popup, khách phải bật cả nhóm lẫn nền tảng; không có công tắc riêng thì chỉ theo nhóm. Thuộc tính này có thể bỏ trống nếu bạn tự đánh dấu (xem dưới).
- `data-dscc-src`: địa chỉ gốc (script ngoài, iframe, pixel). Khi được phép, trình duyệt trả lại `src`.
- Script được tạo lại **theo đúng thứ tự trong trang**; một script ngoài kiểu cổ điển giữ hàng đợi tới khi tải xong (hoặc lỗi, hoặc quá 8 giây) để script inline phía sau dùng được nó. Script `async` không giữ hàng đợi. Mọi lỗi nằm trong `try/catch` nên một thẻ hỏng không chặn các thẻ khác.
- Script inline có chứa jQuery được thêm dấu `/*data-dscc*/` ở đầu để WP Rocket không bọc vào `DOMContentLoaded`.
- Ảnh và iframe nằm trong `<noscript>` có địa chỉ theo dõi bị **gỡ** (người không có JavaScript không thể đồng ý); phần `<noscript>` khác giữ nguyên.

Sau khi bật lại, trình duyệt phát sự kiện `dscc:service-enabled` (xem [shortcodes-and-js-api.md](shortcodes-and-js-api.md#sự-kiện-dom)).

### Tự đánh dấu thẻ

Lập trình viên có thể tự viết thẻ ở dạng đã chặn cho script mà danh mục không biết (script riêng của site, widget lạ):

```html
<!-- Script ngoài -->
<script type="text/plain" data-dscc-category="marketing" data-dscc-service="littled-widget"
        data-dscc-src="https://cdn.littled.example/widget.js"></script>

<!-- Script inline -->
<script type="text/plain" data-dscc-category="analytics">
  window.littledTrack && window.littledTrack('page');
</script>

<!-- Iframe -->
<iframe data-dscc-src="https://player.littled.example/embed/1" data-dscc-category="marketing" hidden></iframe>
```

Quy tắc: thẻ đã có `data-dscc-category` được bộ chặn **bỏ qua** (không viết lại hai lần) và vẫn được trình duyệt bật lại theo khoá đồng ý. Muốn chạy luôn, đừng dùng `type="text/plain"`. Nếu script gốc có `type` đặc biệt (ví dụ `module`), đặt giá trị đó vào `data-dscc-type`.

### Loại một thẻ khỏi bộ chặn

Thêm `data-dscc-ignore` vào thẻ `<script>` hoặc `<iframe>`:

```html
<script data-dscc-ignore src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED"></script>
```

Các thẻ plugin tự in (script đầu trang, script chính) đã mang thuộc tính này. Chỉ nên dùng khi bạn chắc thẻ đó thuộc nhóm Cần thiết hoặc đã tự kiểm soát việc nó chạy khi nào.

Chỉ script JavaScript bị động tới (không có `type`, `text/javascript`, `application/javascript`, `module`...). JSON-LD, template, import map... không bao giờ bị chặn.

## Danh sách loại trừ

`blocking.exclusions` (tab Chặn script): thẻ `<script>`/`<iframe>` có chứa đoạn chữ này (so khớp trong cả thẻ) hoặc khớp regex `/.../` sẽ **không bị chặn**. Dùng cho script nặng, cần chạy luôn mà nền tảng đang chặn nhầm. Mỗi dòng 3 đến 200 ký tự, tối đa 50 dòng; regex lồng lặp nguy hiểm bị từ chối.

Cách khác để giữ một thẻ khỏi bộ chặn từ mã: bộ lọc [`dscc_blocker_skip_tag`](developer-hooks.md#dscc_blocker_skip_tag).

## Google Tag Manager

Tab Chặn script có hai chế độ cho GTM (`gtm_mode`), chỉ áp dụng khi nền tảng *Google Tag Manager* đã được tick:

- **GTM + Consent Mode** (mặc định, `consent_mode`): GTM luôn tải; chỉ các thẻ Google trong container tự dừng nhờ Consent Mode v2. Thẻ bên thứ ba (Meta, TikTok...) chỉ dừng nếu bạn đặt điều kiện kích hoạt trong container.
- **Chặn cả GTM** (`block`): GTM chỉ tải khi khách đồng ý Phân tích **hoặc** Tiếp thị. Vì vậy đồng ý riêng Phân tích cũng làm chạy cả thẻ Tiếp thị trong container: vẫn phải đặt điều kiện cho từng thẻ.

Trong GTM, tạo trigger *Custom Event* với tên `dscc_consent_update` và điều kiện trên biến dataLayer `dscc_analytics` hoặc `dscc_marketing` (giá trị `true`). Sự kiện được đẩy mỗi lần khách chọn và cả khi tải trang nếu khách đã chọn từ trước. Chi tiết dữ liệu: [shortcodes-and-js-api.md](shortcodes-and-js-api.md#datalayer-và-consent-mode).

## Bộ lọc cookie phía server

Lớp thứ hai (`ServerCookieGuard`) gỡ header `Set-Cookie` của cookie nằm trong danh mục mà khách **chưa** được phép (nhóm Phân tích, Tiếp thị, và cookie chưa phân loại tuỳ chế độ). Nó chạy trong bộ đệm đầu ra, khi header chưa gửi đi.

Giới hạn quan trọng: bộ lọc **chỉ hoạt động khi yêu cầu tới PHP**. Một trang đã nằm trong cache cùng `Set-Cookie` không đi qua nó. Với plugin ghi cookie ở phía server, cách triệt để là chặn plugin đó ngay từ đầu (phần Tích hợp bên dưới).

Không bao giờ bị động tới: header **xoá** cookie (Max-Age ≤ 0 hoặc Expires trong quá khứ xa), cookie không có trong danh mục, cookie thuộc dịch vụ Cần thiết, và các cookie trong [danh sách giữ nguyên](#danh-sách-giữ-nguyên).

### Danh sách giữ nguyên

`CookieSafelist` là một danh sách dùng chung cho PHP và trình duyệt: cookie của WordPress (`wordpress_*`, `wp-*`, `wp_*`, `comment_*`), WooCommerce (`woocommerce_*`), `PHPSESSID`, khoá của chính plugin (`dscc_*`) và cookie lựa chọn, cùng một số cookie kỹ thuật của plugin khác; xem danh sách chính xác ở [`src/Blocker/CookieSafelist.php`](../src/Blocker/CookieSafelist.php). Bạn thêm được cookie của riêng site:

- Ô **Cookie luôn giữ** (`keep_cookies`): tên hoặc mẫu có `*`, ví dụ `my_session`, `shop_*`. Không bao giờ bị lọc hay xoá; khi quét được xếp vào Cần thiết. Mẫu chỉ có `*` bị từ chối.
- Bộ lọc [`dscc_cookie_safelist`](developer-hooks.md#dscc_cookie_safelist) từ mã.

### Cookie chưa phân loại

Áp dụng cho cookie lạ mà lượt quét thấy **server** gửi cho khách mới (`unclassified_cookie_mode`):

| Chế độ | Hành vi |
|---|---|
| **Chỉ báo cáo** (`report`, mặc định) | Cookie vẫn được gửi. Chỉ liệt kê ở báo cáo tuân thủ để bạn phân loại. An toàn với cookie riêng của site (đăng nhập, giỏ hàng). |
| **Chặn như Tiếp thị** (`block`) | Bộ lọc server gỡ cookie và trình duyệt xoá nó, tới khi khách đồng ý hoặc bạn phân loại là Cần thiết. Chặt hơn nhưng có thể làm hỏng cookie riêng chưa phân loại. |

Cookie đã *xác nhận* hoặc được nhận diện *tự động* luôn theo nhóm của nó, bất kể chế độ này. Khoá localStorage/sessionStorage chưa phân loại không bao giờ bị xoá. Danh sách cookie lạ đang bị chặn có trần 100 cookie (cookie mới hơn chưa bị chặn cho tới khi bạn phân loại bớt).

Phân loại nhanh ngay trong báo cáo: xem [scanner-and-compliance.md](scanner-and-compliance.md#báo-cáo-tuân-thủ-và-phân-loại-nhanh).

## Xoá cookie trong trình duyệt và rút lại đồng ý

Khi khách chưa được phép một nhóm hoặc nền tảng, `cookie-purger.js` xoá các cookie và khoá lưu trữ đã biết của nó (thử trên host hiện tại và các domain cha, đường dẫn `/` và từng thư mục của trang). Cookie `HttpOnly` hoặc do host khác đặt thì trình duyệt không xoá được từ phía này.

Khi khách **rút lại đồng ý** và đã có script chạy trong lần xem trang đó, trang được tải lại để script không ghi cookie tiếp (`reload_on_withdraw`, mặc định bật).

## Tích hợp với plugin khác

Danh sách trạng thái ở cuối tab Chặn script cho biết plugin nào đang được phát hiện. Cách mỗi tích hợp hoạt động nằm ở mã nguồn tương ứng trong [`src/Integrations/`](../src/Integrations/).

| Tích hợp | Việc chính nó làm |
|---|---|
| Meta Pixel for WordPress | Giữ plugin ở trạng thái "hold" (bộ lọc `facebook_signals_held`) ở mọi trang; trình duyệt gọi `release()` khi khách đồng ý Tiếp thị. Tắt bằng `meta_hold` nếu muốn tránh một yêu cầu admin-ajax mỗi lượt xem trang của khách đã đồng ý. Khai báo cookie trạng thái `wc_facebook_signals_state` là Cần thiết |
| PixelYourSite | Trả lời các bộ lọc GDPR của plugin theo sự đồng ý của khách (chỉ ở chế độ chặn) |
| Google Site Kit | Khi Consent Mode của Site Kit bật, thẻ của Site Kit không bị chặn; tín hiệu đồng ý đến Site Kit qua WP Consent API |
| WP Consent API | Khai báo plugin là nguồn đồng ý (opt-in) và phản chiếu lựa chọn bằng `wp_set_consent()` cho các nhóm `functional`, `preferences`, `statistics`, `statistics-anonymous`, `marketing` |
| LiteSpeed Cache, WP Rocket | Loại script của plugin và host của các luật đang chặn khỏi gộp/hoãn/trì hoãn JS, kể cả script inline có jQuery |
| Widget chat (Pancake, Zalo) | Khi nền tảng đã tick, selector của widget được thêm vào danh sách ẩn khi banner mở |

Các tích hợp được viết theo mã nguồn và tài liệu của plugin đó. Hãy kiểm tra trên bản sao (staging) của site thật trước khi đưa lên chạy chính thức.

## Sự cố bộ chặn

Tab Chặn script hiện cảnh báo khi:

- **Bộ chặn để một trang đi qua không chặn** (fail-open): một plugin đã đẩy một phần trang ra trước khi kết thúc, lỗi giới hạn PCRE trên trang quá lớn, có thẻ HTML quá lớn (hơn 1000 thuộc tính) không phân tích được, hoặc lỗi PHP. Trong thời gian đó các script theo dõi của trang ấy có thể chạy trước khi khách đồng ý. Lưu tab để xoá cảnh báo.
- **Bộ chặn xử lý chậm**: vượt ngưỡng 30 ms (đổi được bằng [`dscc_blocker_slow_ms`](developer-hooks.md#dscc_blocker_slow_ms)). Cách xử lý: thêm script nặng không cần chặn vào danh sách loại trừ hoặc tắt chặn cho nền tảng đó.

Chỉ trang HTML đầy đủ được xử lý; JSON, XML, REST, AJAX, cron, feed, embed, robots, trình dựng trang (Elementor, Divi, Bricks...) và xem trước bị bỏ qua.

## Giới hạn đã biết

- `<link rel="preload">`, `<link rel="preconnect">`, `<embed>`, `<object>` không được xử lý.
- Tracker do một script **không bị chặn** thêm vào trang (kể cả `document.write`) không nằm trong tầm của bộ chặn.
- Cookie do server ghi trên trang đã vào cache: xem phần bộ lọc cookie phía server.
- Plugin là công cụ kỹ thuật; kết quả trên site thật cần được bạn kiểm tra.
