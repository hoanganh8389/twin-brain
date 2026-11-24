# Canon chung và xương sống xuyên app — áp cho twin-brain (Open Brain)

> Tệp này nằm trong `docs/developer/` vì repo chỉ công bố một cửa tài liệu cho dev (Q103-8); mọi `docs/` khác không theo dõi.

<!-- [2026-11-24 06:05 PM Johnny Chu - Chu Hoàng Anh] SPINE-2 — chỗ trỏ tới canon chung (open-hub) và chuẩn bizcity_spine@1 (bizcity-platform, chủ duyệt 2026-11-24); khai trục ở bizcity.app.json theo Q-SPINE-4 (manifest ở gốc mọi repo, kể cả plugin WordPress). Tài liệu thuần, không đổi mã. -->

| Tài liệu | Ở đâu |
|---|---|
| **BIZCITY OPEN CANON** | [open-hub/docs/canon](https://github.com/hoanganh8389/open-hub/blob/main/docs/canon/README.md) — §5 Twin Brain là lớp kiểm chứng và điều phối agent; R-CANON-2: **não, không là chủ dữ liệu**; R-CANON-12: mọi hành động agent có bằng chứng |
| **`bizcity_spine@1`** — xương sống xuyên app | [bizcity-platform/docs/standard/BIZCITY-SPINE-v1.md](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/standard/BIZCITY-SPINE-v1.md) |
| Chuẩn MCP gốc của hệ | `docs/contracts/` trong repo này (`bizcity-mcp-tool@1`, `bizcity-mcp-bridge@1`); hồ sơ phía kế toán: open-ketoan `docs/contracts/BIZCITY-MCP-v1.md` |
| Luật kỹ thuật của repo | [CONTRIBUTING.md](../../CONTRIBUTING.md) (R-DCL, R-DDV, R-CH-NS, R-GW-8…), sàn PHP 7.4 |

## Open Brain trong xương sống (`bizcity.app.json` ở gốc repo)

| | |
|---|---|
| Làm chủ | **không trục dữ liệu nào** của tám trục. Thứ của riêng Open Brain (phiên kênh, kịch bản, skill, trí nhớ) không phải trục chung và không app nào khác giữ |
| Dùng | `tenant`, `user` (`sub = wp:<id>` — WordPress bizcity là nguồn danh tính), `contact` (tra ở open-cake bằng `ContactRef {identity}` có bằng chứng, **không bịa `contact_id`**), `item` / `partner` / `document` (đọc và đề xuất qua MCP của app chủ) |
| Ghi vào app khác | chỉ qua **MCP**: công cụ sinh từ action của app chủ, ghi = nháp / chờ duyệt với `confirm_token`; actor `{kind: 'ai', via: 'mcp:<client>'}` vào audit của app chủ |
| Tri thức | công cụ đọc cho phân tích (context bank) sẽ theo `bizcity_knowledge@1` khi có: chỉ đọc, `as_of`, thông tin nhạy cảm đã che, dẫn nguồn bằng `doc_ref` / `contact_id` / `item_id` |

## Ba điều không làm

- Không giữ bảng khoá theo `contact_id`, `item_id` của app khác như nguồn sự thật; không đồng bộ hai chiều danh mục.
- Không gọi thẳng CSDL hay route ghi nội bộ của app khác; không gửi số tài khoản, thuế suất, giá vốn (P4 của canon).
- Không thêm trục mới ở repo này; đề xuất ở bizcity-platform (chuẩn §7).

## Kiểm

```bash
node ../bizcity-platform/tools/spine-check.mjs --manifest bizcity.app.json   # SC-SPINE-1, phải ĐẠT
```
