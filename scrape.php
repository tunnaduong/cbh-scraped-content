<?php

use CbhScraper\FacebookClient;
use CbhScraper\PostParser;
use CbhScraper\Scraper;

require __DIR__ . '/vendor/autoload.php';

$options = getopt('', ['max-posts:', 'delay:', 'out:', 'update', 'no-media', 'help']);
if (isset($options['help'])) {
    echo <<<TXT
    Cách dùng: php scrape.php [tuỳ chọn]

      --max-posts=N   Chỉ xử lý N bài trong lần chạy này
      --delay=GIÂY    Khoảng nghỉ giữa các request tới Facebook (mặc định 1.5)
      --out=THƯ_MỤC   Thư mục xuất dữ liệu (mặc định ./data)
      --update        Quét lại từ bài mới nhất để lấy bài mới đăng
      --no-media      Chỉ lấy JSON, không tải ảnh/video

    Chạy lại lệnh sẽ tự tiếp tục từ chỗ dừng lần trước.

    TXT;
    exit(0);
}

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone']);

$scraper = new Scraper(
    new FacebookClient($config, (float) ($options['delay'] ?? 1.5)),
    new PostParser($config['timezone']),
    rtrim($options['out'] ?? __DIR__ . '/data', '/'),
    !isset($options['no-media']),
);

try {
    $scraper->run(isset($options['max-posts']) ? (int) $options['max-posts'] : null, isset($options['update']));
} catch (RuntimeException $e) {
    fwrite(STDERR, "Lỗi: {$e->getMessage()}\nDữ liệu đã lấy vẫn được giữ, chạy lại để tiếp tục.\n");
    exit(1);
}
