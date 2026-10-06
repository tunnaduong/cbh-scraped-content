<?php

/**
 * Nạp các bài đã scrape (data/posts) vào diễn đàn CBH Youth Online: copy media vào
 * storage của API, tạo dòng cyo_cdn_user_content và cyo_topics tương ứng.
 *
 * Chạy ngay trên máy chủ chứa API, dùng cấu hình DB sẵn có của Laravel:
 *   php import_topics.php <thư mục API> [--data=./data] [--username=DoanTruongCBH] [--subforum=32] [--dry-run] [--prune-media]
 *
 * Chạy lại an toàn: bài đã nạp được ghi trong <data>/imported.json và bị bỏ qua.
 */

use App\Models\Topic;
use App\Services\HashtagService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Extension\Autolink\AutolinkExtension;

$appDir = rtrim($argv[1] ?? '', '/');
$options = [];
if (!is_file("{$appDir}/artisan")) {
    fwrite(STDERR, "Cách dùng: php import_topics.php <thư mục API> [--data=./data] [--username=DoanTruongCBH] [--subforum=32] [--dry-run]\n");
    exit(1);
}
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([\w-]+)(?:=(.*))?$/', $arg, $m)) {
        $options[$m[1]] = $m[2] ?? false;
    }
}
$dataDir = rtrim($options['data'] ?? __DIR__ . '/data', '/');
$dryRun = isset($options['dry-run']);

require "{$appDir}/vendor/autoload.php";
$app = require "{$appDir}/bootstrap/app.php";
$app->make(Kernel::class)->bootstrap();

$userId = DB::table('cyo_auth_accounts')->where('username', $options['username'] ?? 'DoanTruongCBH')->value('id');
$subforumId = (int) ($options['subforum'] ?? config('forum.news_subforum_id', 32));
if (!$userId || !DB::table('cyo_forum_subforums')->where('id', $subforumId)->exists()) {
    fwrite(STDERR, "Không tìm thấy tài khoản tác giả hoặc subforum {$subforumId}\n");
    exit(1);
}
$hasModeration = Schema::hasColumn('cyo_topics', 'moderation_status');
$imageIdLimit = 255; // cdn_image_id là varchar(255)

$mapFile = "{$dataDir}/imported.json";
$imported = is_file($mapFile) ? json_decode(file_get_contents($mapFile), true) : [];

$converter = new CommonMarkConverter([
    'html_input' => 'strip',
    'allow_unsafe_links' => false,
    'renderer' => ['soft_break' => "<br>\n"],
]);
$converter->getEnvironment()->addExtension(new AutolinkExtension());

$posts = [];
foreach (glob("{$dataDir}/posts/*/post.json") as $file) {
    $posts[] = json_decode(file_get_contents($file), true);
}
usort($posts, fn ($a, $b) => $a['creation_time'] <=> $b['creation_time']);

$disk = Storage::disk('public');
// Một số bài đã được đăng tay lên diễn đàn trước đó (khác giờ đăng), nhận diện theo tiêu đề
$normalize = fn (string $title) => mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $title));
$existingTitles = array_flip(DB::table('cyo_topics')->where('user_id', $userId)->whereNull('deleted_at')->pluck('title')->map($normalize)->all());
$created = 0;
foreach ($posts as $post) {
    if (isset($imported[$post['post_id']])) {
        continue;
    }

    [$title, $description] = splitText($post);
    $createdAt = date('Y-m-d H:i:s', $post['creation_time']);
    if (isset($existingTitles[$normalize($title)])) {
        echo "= {$post['post_id']} đã có sẵn trong DB, bỏ qua\n";
        continue;
    }

    $files = array_values(array_filter($post['media'], fn ($m) => $m['file'] && is_file("{$dataDir}/posts/{$post['post_id']}/{$m['file']}")));
    // Bài vừa đăng mà media tải chưa đủ: để lần chạy sau scraper tải lại rồi mới nạp
    if (empty($post['media_complete']) && time() - $post['creation_time'] < 2 * 3600) {
        echo "… {$post['post_id']} chưa đủ media, chờ lần chạy sau\n";
        continue;
    }
    if ($description === '' && !$files && trim($post['text']) === '') {
        echo "- {$post['post_id']} không có nội dung lẫn media, bỏ qua ({$post['url']})\n";
        continue;
    }
    echo ($dryRun ? '~ ' : '+ ') . "{$createdAt} {$post['post_id']} — " . count($files) . " media — {$title}\n";
    if ($dryRun) {
        continue;
    }

    $topicId = DB::transaction(function () use ($post, $files, $title, $description, $createdAt, $userId, $subforumId, $hasModeration, $imageIdLimit, $converter, $disk, $dataDir) {
        $ids = ['photo' => [], 'video' => []];
        foreach ($files as $media) {
            $source = "{$dataDir}/posts/{$post['post_id']}/{$media['file']}";
            $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
            $fileName = "{$post['creation_time']}_fb{$media['id']}.{$extension}";
            $path = ($media['type'] === 'video' ? 'videos' : 'images') . "/{$fileName}";

            if ($media['type'] === 'photo' && strlen(implode(',', [...$ids['photo'], '99999999'])) > $imageIdLimit) {
                echo "  ! Bỏ ảnh {$media['id']}: bài có quá nhiều ảnh so với cột cdn_image_id\n";
                continue;
            }
            if (!$disk->exists($path)) {
                $disk->makeDirectory(dirname($path));
                copy($source, $disk->path($path));
            }
            $ids[$media['type']][] = DB::table('cyo_cdn_user_content')->insertGetId([
                'user_id' => $userId,
                'file_name' => $fileName,
                'file_path' => $path,
                'file_type' => mime_content_type($source) ?: ($media['type'] === 'video' ? 'video/mp4' : 'image/jpeg'),
                'file_size' => filesize($source),
                'video_status' => $media['type'] === 'video' ? 'completed' : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        $html = HashtagService::linkify($converter->convert($description)->getContent());
        do {
            $topicId = random_int(100000, 999999999);
        } while (DB::table('cyo_topics')->where('id', $topicId)->exists());

        // Insert thẳng thay vì Topic::create để giữ ngày đăng gốc và không cộng điểm cho hàng loạt bài nạp sẵn
        DB::table('cyo_topics')->insert([
            'id' => $topicId,
            'subforum_id' => $subforumId,
            'user_id' => $userId,
            'title' => $title,
            'description' => $description,
            'content_html' => $html['html'],
            'cdn_image_id' => $ids['photo'] ? implode(',', $ids['photo']) : null,
            'cdn_video_id' => $ids['video'] ? implode(',', $ids['video']) : null,
            'privacy' => 'public',
            'hidden' => 0,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ] + ($hasModeration ? ['moderation_status' => 'approved'] : []));
        HashtagService::syncTopicHashtags(Topic::findOrFail($topicId), $html['tags'] ?? []);

        return $topicId;
    });

    $imported[$post['post_id']] = $topicId;
    file_put_contents($mapFile, json_encode($imported, JSON_PRETTY_PRINT) . "\n");
    $created++;
}

if ($created) {
    Cache::has('feed_version') ? Cache::increment('feed_version') : Cache::forever('feed_version', 2);
}

// Media của bài đã nạp đã nằm trong storage của API, bản trong data/ chỉ tốn đĩa
if (isset($options['prune-media']) && !$dryRun) {
    foreach (array_keys($imported) as $postId) {
        array_map('unlink', glob("{$dataDir}/posts/{$postId}/media/*") ?: []);
    }
}
echo ($dryRun ? 'Chạy thử: ' : '') . "Đã nạp {$created} bài mới, tổng " . count($imported) . " bài đã nạp.\n";

/**
 * Dòng đầu của bài Facebook thường là tiêu đề ("[ĐẠI HỘI ...]"); tách ra làm title.
 *
 * @return array{0: string, 1: string}
 */
function splitText(array $post): array
{
    $own = trim($post['text']);
    $quote = '';
    if ($shared = $post['shared_post']) {
        $quote = trim(($shared['author'] ? "Chia sẻ từ {$shared['author']}:\n" : '') . trim($shared['text']) . ($shared['url'] ? "\n{$shared['url']}" : ''));
    }
    // Bài chia sẻ không kèm lời dẫn thì lấy tiêu đề từ bài gốc
    $source = $own !== '' ? $own : trim($shared['text'] ?? '');
    if ($source === '') {
        return ['Bài đăng ngày ' . date('d/m/Y', $post['creation_time']), $quote];
    }

    [$first, $rest] = array_pad(preg_split('/\R/u', $source, 2), 2, '');
    $heading = trim(preg_replace('/^[\s\[\]|]+|[\s\[\]|]+$/u', '', $first));
    if ($heading !== '' && mb_strlen($heading) <= 200 && trim($rest) !== '') {
        return [$heading, $own !== '' ? trim(trim($rest) . "\n\n" . $quote) : $quote];
    }

    $flat = preg_replace('/\s+/u', ' ', $source);

    return [mb_strlen($flat) > 120 ? mb_substr($flat, 0, 120) . '…' : $flat, trim($own . "\n\n" . $quote)];
}
