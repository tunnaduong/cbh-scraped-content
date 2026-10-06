<?php

namespace CbhScraper;

use RuntimeException;

class Scraper
{
    // Ở chế độ cập nhật, gặp liên tiếp từng này bài đã có thì coi như đã bắt kịp
    private const KNOWN_STREAK_LIMIT = 6;

    private string $postsDir;
    private string $stateFile;
    /** @var array<string, array> */
    private array $posts = [];

    public function __construct(
        private FacebookClient $client,
        private PostParser $parser,
        private string $dataDir,
        private bool $withMedia = true,
    ) {
        $this->postsDir = "{$dataDir}/posts";
        $this->stateFile = "{$dataDir}/state.json";
        if (!is_dir($this->postsDir)) {
            mkdir($this->postsDir, 0777, true);
        }
        foreach (glob("{$this->postsDir}/*/post.json") as $file) {
            $post = json_decode(file_get_contents($file), true);
            $this->posts[$post['post_id']] = $post;
        }
    }

    public function run(?int $maxPosts, bool $update): void
    {
        $state = is_file($this->stateFile) ? json_decode(file_get_contents($this->stateFile), true) : [];
        $update = $update || !empty($state['reached_end']);

        // Chạy tiếp lần trước: lọc theo thời gian bài cũ nhất đã có thay vì cursor (cursor hết hạn nhanh)
        $beforeTime = null;
        if (!$update && $this->posts) {
            $beforeTime = min(array_column($this->posts, 'creation_time'));
            $this->log('Tiếp tục từ bài cũ nhất đã lưu (' . date('Y-m-d', $beforeTime) . '), đã có ' . count($this->posts) . ' bài');
        }

        $cursor = null;
        $handled = 0;
        $knownStreak = 0;
        do {
            $page = $this->client->feedPage($cursor, $beforeTime);
            foreach ($page['stories'] as $story) {
                if (empty($story['post_id'])) {
                    continue;
                }
                $known = $this->posts[$story['post_id']] ?? null;
                if ($known && ($known['media_complete'] || !$this->withMedia)) {
                    $knownStreak++;
                    continue;
                }
                $knownStreak = 0;

                $post = $this->parser->parse($story);
                $this->log(sprintf('[%d] %s %s — %d media — %s', count($this->posts) + ($known ? 0 : 1), substr((string) $post['created_at'], 0, 10), $post['post_id'], $post['media_count'], self::excerpt($post['text'])));
                $this->save($post);
                $handled++;
            }
            $this->writeIndex();

            $cursor = $page['cursor'];
            $reachedEnd = !$page['has_next'] || $cursor === null;
            if ($update && $knownStreak >= self::KNOWN_STREAK_LIMIT) {
                $this->log('Đã bắt kịp các bài đã lưu.');
                break;
            }
            if ($maxPosts !== null && $handled >= $maxPosts) {
                break;
            }
        } while (!$reachedEnd);

        if (!empty($reachedEnd)) {
            $state['reached_end'] = true;
            $this->log('Đã tới bài viết cũ nhất của trang.');
        }
        $state['updated_at'] = date(DATE_ATOM);
        file_put_contents($this->stateFile, self::json($state));
        $this->log('Tổng cộng ' . count($this->posts) . " bài trong {$this->dataDir}/posts.json");
    }

    private function save(array $post): void
    {
        $dir = "{$this->postsDir}/{$post['post_id']}";
        if (!is_dir("{$dir}/media")) {
            mkdir("{$dir}/media", 0777, true);
        }

        $post['media_complete'] = false;
        if ($this->withMedia) {
            $post['media_complete'] = $this->fetchMedia($post, "{$dir}/media");
        }
        $post['scraped_at'] = date(DATE_ATOM);

        file_put_contents("{$dir}/post.json", self::json($post));
        $this->posts[$post['post_id']] = $post;
    }

    /**
     * Bổ sung media còn thiếu (feed chỉ trả 5 ảnh đầu của album) rồi tải tất cả về.
     */
    private function fetchMedia(array &$post, string $mediaDir): bool
    {
        $complete = true;
        try {
            $this->walkAlbum($post);
        } catch (RuntimeException $e) {
            $this->log("  ! Không lấy đủ album: {$e->getMessage()}");
            $complete = false;
        }

        foreach ($post['media'] as $i => &$media) {
            try {
                if ($media['source_url'] === null) {
                    $media['source_url'] = $media['type'] === 'video'
                        ? $this->client->videoSource($media['id'])
                        : $this->client->photoPage($media['id'], $post['post_id'])['uri'];
                }
                if ($media['source_url'] === null) {
                    throw new RuntimeException('không tìm thấy link tải');
                }

                $extension = pathinfo(parse_url($media['source_url'], PHP_URL_PATH), PATHINFO_EXTENSION)
                    ?: ($media['type'] === 'video' ? 'mp4' : 'jpg');
                $name = sprintf('%02d_%s.%s', $i + 1, $media['id'], $extension);
                if (!is_file("{$mediaDir}/{$name}")) {
                    $this->client->download($media['source_url'], "{$mediaDir}/{$name}");
                }
                $media['file'] = "media/{$name}";
            } catch (RuntimeException $e) {
                $this->log("  ! Media {$media['id']}: {$e->getMessage()}");
                $complete = false;
            }
        }
        unset($media);

        return $complete;
    }

    private function walkAlbum(array &$post): void
    {
        if (!$post['media'] || count($post['media']) >= $post['media_count']) {
            return;
        }

        $seen = array_column($post['media'], 'id');
        $next = $this->client->photoPage(end($seen), $post['post_id']);
        while (count($post['media']) < $post['media_count'] && $next['next_id'] && !in_array($next['next_id'], $seen, true)) {
            $id = $next['next_id'];
            $isVideo = $next['next_type'] === 'Video';
            $seen[] = $id;

            $next = $this->client->photoPage($id, $post['post_id']);
            $post['media'][] = [
                'id' => $id,
                'type' => $isVideo ? 'video' : 'photo',
                'url' => $isVideo ? "https://www.facebook.com/watch/?v={$id}" : "https://www.facebook.com/photo/?fbid={$id}&set=pcb.{$post['post_id']}",
                'source_url' => $isVideo ? null : $next['uri'],
                'width' => $isVideo ? null : $next['width'],
                'height' => $isVideo ? null : $next['height'],
                'alt' => null,
                'thumbnail_url' => null,
                'file' => null,
            ];
        }
    }

    private function writeIndex(): void
    {
        $posts = array_values($this->posts);
        usort($posts, fn ($a, $b) => $b['creation_time'] <=> $a['creation_time']);
        foreach ($posts as &$post) {
            foreach ($post['media'] as &$media) {
                if ($media['file']) {
                    $media['file'] = "posts/{$post['post_id']}/{$media['file']}";
                }
            }
        }
        file_put_contents("{$this->dataDir}/posts.json", self::json($posts));
    }

    private static function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }

    private static function excerpt(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text);

        return mb_strlen($text) > 60 ? mb_substr($text, 0, 60) . '…' : $text;
    }

    private function log(string $message): void
    {
        echo $message, "\n";
    }
}
