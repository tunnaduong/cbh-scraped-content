#!/bin/bash
# Lấy bài mới của trang Facebook Đoàn trường rồi nạp vào diễn đàn. Dành cho cron:
#   bash /www/cbh-scraped-content/sync.sh
#
# Tuỳ chỉnh qua biến môi trường:
#   API_DIR   thư mục Laravel API (mặc định /www/wwwroot/api.chuyenbienhoa.com)
#   RUN_AS    user sở hữu storage của API (mặc định www)
#   NTFY_URL  nếu đặt, gửi thông báo tới URL ntfy này khi có lỗi
set -uo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"
API_DIR="${API_DIR:-/www/wwwroot/api.chuyenbienhoa.com}"
RUN_AS="${RUN_AS:-www}"

# File media phải thuộc user của web server thì API mới đọc/xoá được
if [ "$(id -un)" != "$RUN_AS" ]; then
    exec sudo -u "$RUN_AS" API_DIR="$API_DIR" RUN_AS="$RUN_AS" NTFY_URL="${NTFY_URL:-}" bash "$0" "$@"
fi

cd "$DIR"
mkdir -p data
exec 9>data/sync.lock
if ! flock -n 9; then
    echo "$(date '+%F %T') Lần chạy trước chưa xong, bỏ qua."
    exit 0
fi

fail() {
    echo "$(date '+%F %T') LỖI: $1"
    if [ -n "${NTFY_URL:-}" ]; then
        curl -s -m 10 -H "Title: cbh-scraped-content" -d "$1" "$NTFY_URL" >/dev/null || true
    fi
    exit 1
}

echo "$(date '+%F %T') Bắt đầu"
php scrape.php --update || fail "Scrape Facebook thất bại (xem README mục 'Khi scraper ngừng hoạt động')"
php import_topics.php "$API_DIR" --prune-media || fail "Nạp bài vào diễn đàn thất bại"
echo "$(date '+%F %T') Xong"
