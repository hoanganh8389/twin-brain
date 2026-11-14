# Twin Brain

<!-- [2026-11-15 Johnny Chu - Chu Hoàng Anh] PHASE-0.149 — README viết lại: Zalo thành trợ lý, WordPress thành bộ não thứ hai; bỏ định vị CRM. -->

**Biến Zalo thành trợ lý cá nhân, biến WordPress thành bộ não thứ hai của bạn.**

Bạn chỉ cần nhắn Zalo cho trợ lý như nhắn cho một người thư ký. Trợ lý lưu mọi tài liệu bạn gửi vào kho tri thức trên website của chính bạn, nhớ những gì bạn dặn, nhắc việc đúng giờ, và thay bạn làm việc với các phần mềm đang dùng: kế toán, bán hàng, CRM, Fanpage, các nhóm Zalo.

[![Version](https://img.shields.io/badge/version-1.4.0-orange)](bizcity-twin-ai.php)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-2ea44f)](LICENSE)

[Dùng thử](https://libedemo.bizcity.vn/twin/) · [Lịch sử phát hành](CHANGELOG.md) · [Bảo mật](SECURITY.md) · [Đóng góp](CONTRIBUTING.md)

---

## 1. Twin Brain là gì

Một plugin WordPress có hai nửa:

- **Zalo là trợ lý.** Bạn nhắn cho số Zalo của mình (hoặc số trợ lý) bằng lời bình thường: "lưu hợp đồng này", "hôm qua anh lưu gì", "soạn báo giá từ file vừa gửi", "nhắc anh 3 giờ chiều gọi khách". Trợ lý hiểu, làm, và trả lời ngay trên Zalo.
- **WordPress là bộ não thứ hai.** Mọi thứ trợ lý nhận được — tài liệu, ảnh, ghi âm, ghi chú — đều vào **Kho tri thức** trên website của bạn: có bản gốc, có bản đọc được, có mã để gọi lại, và tìm được bất cứ lúc nào. Dữ liệu ở trên máy chủ của bạn, không nằm rải rác trong điện thoại.

## 2. Làm được gì cho bạn

| Việc hằng ngày | Bạn nói trên Zalo | Trợ lý làm |
|---|---|---|
| **Lưu tài liệu** | gửi file Word, PDF, Excel, ảnh, ghi âm | lưu vào Kho tri thức, đọc nội dung, gắn mã `TL-…`, báo lại một dòng |
| **Ghi nhớ** | "ghi nhớ: khách A thích giao buổi sáng" | ghi thành một ghi chú trong thư mục *05 Ghi chú*, lần sau tự nhắc lại đúng nội dung |
| **Tìm và gửi lại** | "tìm file bản vẽ tuần trước", "gửi lại anh file đó" | tìm trong kho, gửi đúng tệp gốc |
| **Soạn và phân tích** | "tóm tắt hợp đồng này", "làm báo giá từ file vừa gửi" | soạn từ tài liệu trong kho, gửi file mới về Zalo, file mới cũng vào kho |
| **Nhắc việc** | "mai 9 giờ nhắc anh họp" | đặt lịch, nhắc đúng giờ, báo kết quả |
| **Phần mềm kế toán, bán hàng, CRM** | "doanh thu tuần này", "tạo đơn cho khách B", "khách C đang ở bước nào" | tra và thao tác qua kết nối với phần mềm bạn đang dùng, chỉ trong quyền của bạn |
| **Fanpage, trực page** | "đăng bài này lên page", "page hôm nay có ai hỏi gì" | soạn nháp để bạn duyệt rồi đăng; trực page và trả lời khách theo kiến thức của bạn |
| **Trả lời khách** | (khách nhắn vào số Zalo / page) | trả lời bằng tài liệu và giọng của bạn, việc nào cần bạn quyết thì hỏi bạn |
| **Chăm sóc nhóm Zalo** | "tóm tắt nhóm dự án hôm nay", "gửi thông báo này vào 20 nhóm" | đọc, tóm tắt, giữ tài liệu của nhóm, gửi thông báo theo lịch |

Mọi việc trợ lý làm đều có dấu vết để bạn xem lại, và việc gửi ra ngoài hay liên quan tiền luôn chờ bạn duyệt.

## 3. Giúp ai

- **Người bận rộn** — chủ shop, chủ doanh nghiệp nhỏ, người làm nghề tự do: muốn có thư ký mà không phải học phần mềm mới. Zalo đã có sẵn trong túi.
- **Người có nhiều tài liệu** — hợp đồng, báo giá, bản vẽ, ghi chú cuộc họp: gửi qua Zalo là có kho tra cứu.
- **Người vận hành nhiều kênh** — Fanpage, nhiều nhóm Zalo, khách hỏi ở nhiều nơi: một trợ lý trực thay.

## 4. Cài trong 4 bước

Kích hoạt plugin, mở `/gpt/` trên website của bạn:

1. **Kết nối tài khoản BizCity** — dán khoá API, hệ thống tự kiểm tra.
2. **Kết nối máy chủ Zalo** — tự kiểm tra, không cần chọn gì.
3. **Quét QR bằng Zalo** — số của bạn được gắn với tài khoản WordPress đang đăng nhập.
4. **Chọn trợ lý (Agent Guru)** — có sẵn trợ lý mặc định; bấm "Bắt đầu" là nhắn được ngay.

<p align="center"><a href="https://libedemo.bizcity.vn/gpt/"><img src="https://media.bizcity.vn/uploads/sites/1258/2026/05/Screenshot-2026-05-06-003857-scaled.png" alt="Twin Brain — trợ lý trên /gpt/" width="820"></a></p>

## 5. Các màn hình trên website

| Đường dẫn | Tên | Việc |
|---|---|---|
| `/gpt/` | Trợ lý | Cài đặt 4 bước, rồi trò chuyện với trợ lý ngay trên web |
| `/twin/` | Không gian làm việc | **Kho tri thức** (thư mục, tài liệu, bản đọc, ghi chú, ghi âm), nhật ký lưu file, các ứng dụng mở rộng |
| `/gateway/` | Cấu hình | Số Zalo, trợ lý, kết nối Fanpage và phần mềm, quyền của từng người |
| `/scheduler/` | Lịch và việc | Việc trợ lý nhận, lịch nhắc, kết quả xong hay lỗi |

## 6. Mở rộng: thêm plugin, không sửa lõi

Plugin mở rộng chính thức:

| Plugin | Thêm gì |
|---|---|
| **Notebook** (`bizcity-twin-notebook`) | Học sâu: biến tài liệu trong kho thành sổ nghiên cứu, hỏi đáp có trích dẫn, đồ thị tri thức |
| **Automation** (`bizcity-automation`) | Kịch bản cho trợ lý: đăng web, đăng Fanpage, chăm khách theo quy trình |

Phần mềm bên ngoài (kế toán, bán hàng, CRM, Fanpage…) được nối vào trợ lý qua **công cụ MCP**: plugin của phần mềm đó khai báo công cụ, trợ lý gọi khi bạn nhờ, trong quyền của bạn.

Viết plugin mở rộng theo hợp đồng **`zalo-brain-extension@1`**:

1. Header `Zalo Brain Addon: <phần>` và `Network: false`; **không** dùng `Requires Plugins` (tên thư mục plugin chính có thể khác). Nhận diện plugin chính bằng hằng `BIZCITY_TWIN_AI_VERSION`, thiếu thì từ chối kích hoạt.
2. File `zalo-brain.json` ở gốc plugin:

```json
{
  "contract": "zalo-brain-extension@1",
  "id": "my-plugin",
  "name": "My Plugin",
  "version": "1.0.0",
  "requires": { "zalo-brain": ">=1.4.0" },
  "features": [ "my_feature" ]
}
```

3. Đăng ký trong `plugins_loaded`:

```php
add_action( 'plugins_loaded', static function () {
	if ( ! class_exists( 'BizCity_Zalo_Brain' ) ) {
		return; // Plugin chính chưa kích hoạt: không nạp gì.
	}
	$manifest = json_decode( (string) file_get_contents( __DIR__ . '/zalo-brain.json' ), true );
	BizCity_Zalo_Brain::register_extension( $manifest );
}, 6 );
```

4. Lõi hỏi `BizCity_Zalo_Brain::has( 'my_feature' )` trước khi dùng tính năng của bạn; khi vắng, lõi trả lỗi `feature_unavailable` có đủ `message`, `hint`, `help_code`.
5. Góp công cụ cho trợ lý qua hook `bizcity_mcp_register_tools`. Các hook được công bố: `zalo_brain_register`, `zalo_brain_loaded`, `zalo_brain_surfaces`, `zalo_brain_boot_dto`, `bizcity_channel_normalized`, `bizcity_channel_outbound_logged`, `bizcity_mcp_register_tools`. Mọi hook khác là nội bộ và có thể đổi.

Hướng dẫn cho lập trình viên: [docs/developer/README.md](docs/developer/README.md). Xem trạng thái của site: `GET /wp-json/bizcity/v1/zalo-brain`.

## 7. Kiến trúc trong một hình

```
   Bạn nhắn Zalo ──┐        Khách nhắn Zalo / Fanpage ──┐
                   ▼                                     ▼
            Channel Gateway (mọi kênh vào một cửa)
                   │
                   ▼
            Trợ lý (cell, chạy ở máy chủ BizCity): hiểu · trả lời · chọn công cụ
                   │ MCP
                   ▼
   WordPress của bạn: Kho tri thức + Nhật ký · lịch nhắc · công cụ phần mềm (kế toán, bán hàng, CRM, Fanpage)
```

Ba nguyên tắc: **một trợ lý** cho mọi kênh, **một kho** trên website của bạn, **một cửa** — trợ lý chỉ chạm vào website qua công cụ MCP, trong quyền của người đang nói chuyện.

## 8. Giấy phép và tác giả

Phát hành theo [GPL-2.0-or-later](LICENSE). Thương hiệu: xem [TRADEMARK.md](TRADEMARK.md).

**Tác giả:** Biz Central Brain — Johnny Chu (Chu Hoàng Anh). *Bizcity Central Brain — Hệ thống Não Chúa Đa-site Phân tán*, Giấy chứng nhận đăng ký quyền tác giả số 8877/2026/QTG.

- Cộng đồng Zalo: [zalo.me/g/0r4gp7hf4213svceflmw](https://zalo.me/g/0r4gp7hf4213svceflmw)
- Website: [bizcity.vn](https://bizcity.vn)
- Liên hệ: `hoanganh.itm@gmail.com`
