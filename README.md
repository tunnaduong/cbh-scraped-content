# cbh-scraped-content

Scrape các bài viết công khai của trang Facebook [Đoàn trường THPT chuyên Biên Hòa](https://www.facebook.com/profile.php?id=100063785460548) ra JSON, kèm toàn bộ ảnh/video của từng bài.

PHP thuần + cURL, không cần đăng nhập, không cần cookie, không cần trình duyệt.

## Chạy

```bash
composer install
php scrape.php
```

| Tuỳ chọn | Ý nghĩa |
| --- | --- |
| `--max-posts=N` | Chỉ xử lý N bài trong lần chạy này |
| `--delay=GIÂY` | Khoảng nghỉ giữa các request (mặc định 1.5) |
| `--out=THƯ_MỤC` | Thư mục xuất (mặc định `./data`) |
| `--update` | Quét lại từ bài mới nhất để lấy bài mới đăng |
| `--no-media` | Chỉ lấy JSON, không tải ảnh/video |

Bị ngắt giữa chừng thì chạy lại `php scrape.php`, scraper tự tiếp tục từ bài cũ nhất đã lưu. Sau khi đã quét tới cuối trang, các lần chạy sau tự chuyển sang chế độ `--update`.

## Kết quả

```
data/
├── posts.json                 # tất cả bài viết, mới nhất trước
├── state.json
└── posts/
    └── <post_id>/
        ├── post.json          # một bài viết
        └── media/             # ảnh/video của bài đó
            ├── 01_<media_id>.jpg
            └── 02_<media_id>.mp4
```

Mỗi bài viết:

```json
{
    "post_id": "1741035718032594",
    "url": "https://www.facebook.com/reel/1016436011408845/",
    "creation_time": 1790595003,
    "created_at": "2026-09-28T18:30:03+07:00",
    "author": { "id": "…", "name": "…", "url": "…" },
    "text": "Nội dung bài viết",
    "reaction_count": 129,
    "comment_count": 2,
    "share_count": 2,
    "media_count": 1,
    "media": [
        {
            "id": "1016436011408845",
            "type": "video",
            "url": "link xem trên Facebook",
            "source_url": "link CDN lúc scrape (sẽ hết hạn)",
            "width": 1920,
            "height": 1080,
            "alt": null,
            "thumbnail_url": "…",
            "file": "media/01_1016436011408845.mp4"
        }
    ],
    "link": null,
    "shared_post": null,
    "media_complete": true,
    "scraped_at": "2026-10-06T11:18:14+07:00"
}
```

`file` trong `post.json` tương đối so với thư mục bài viết; trong `posts.json` tương đối so với `data/`. `media_complete: false` nghĩa là còn media chưa tải được, lần chạy sau sẽ thử lại bài đó.

## Cách hoạt động

1. Mở trang ở chế độ khách để lấy cookie và token `LSD`.
2. Gọi GraphQL `ProfileCometTimelineFeedRefetchQuery` (3 bài mỗi request), phân trang bằng cursor.
3. Feed chỉ trả 5 ảnh đầu của album và ảnh thu nhỏ với bài một ảnh, nên phần còn lại được lấy bằng cách đi lần lượt qua trang `/photo/?fbid=…&set=pcb.<post_id>`.
4. Tải media về ngay vì link CDN có hạn dùng.

## Khi scraper ngừng hoạt động

Facebook đổi `doc_id` và danh sách biến `__relay_internal__pv__…` vài tháng một lần. Dấu hiệu: lỗi `missing_required_variable_value` hoặc `Feed rỗng`. Cập nhật `feed_query.doc_id` và `relay_providers` trong [config.php](config.php) theo bundle JS hiện tại của Facebook (tìm `ProfileCometTimelineFeedRefetchQuery_facebookRelayOperation` và các module `*.relayprovider`).

Chỉ lấy được nội dung trang để công khai. Không lấy nội dung bình luận.

## Nạp vào diễn đàn CBH Youth Online

`import_topics.php` chạy trên máy chủ chứa API (dùng cấu hình DB của Laravel): copy media vào `storage/app/public/images|videos`, tạo dòng `cyo_cdn_user_content` và `cyo_topics` (tác giả `DoanTruongCBH`, subforum "Tin tức Đoàn", giữ ngày đăng gốc).

```bash
php import_topics.php /www/wwwroot/api.chuyenbienhoa.com --dry-run
```

Bài đã nạp được ghi trong `data/imported.json` nên chạy lại không tạo trùng; bài trùng tiêu đề với bài đã có của tài khoản đó cũng bị bỏ qua. `--prune-media` xoá bản media trong `data/` của các bài đã nạp.

## Tự động lấy bài mới (cron)

Bản chạy thật nằm ở `/www/cbh-scraped-content` trên Pi. `sync.sh` chạy `scrape.php --update` rồi `import_topics.php --prune-media`, tự chuyển sang user `www`, và bỏ qua nếu lần chạy trước chưa xong.

Trong aaPanel: **Cron → Add Task → Shell Script**, chu kỳ 10 phút, nội dung:

```bash
bash /www/cbh-scraped-content/sync.sh
```

Đặt `NTFY_URL=https://…/<topic>` trước lệnh để nhận thông báo khi lỗi. Bài bị sửa hoặc xoá trên Facebook sau khi đã nạp sẽ không được cập nhật theo.
