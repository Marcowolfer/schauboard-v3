<?php
require_once dirname(__DIR__) . '/core/bootstrap.php';

schauboard_ensure_data_files();

$settings = schauboard_read_dataset('settings');
$slides = schauboard_read_dataset('slides');
$playlists = schauboard_read_dataset('playlists');
$displays = schauboard_read_dataset('displays');
$schedules = schauboard_read_dataset('schedules');

$requestedDisplayId = schauboard_sanitize_text($_GET['display'] ?? 'default');
if ($requestedDisplayId === '') {
    $requestedDisplayId = 'default';
}

$display = schauboard_find_by_id($displays, $requestedDisplayId) ?? $displays[0] ?? null;
$displayId = $display['id'] ?? 'default';
$playlistId = $display['default_playlist_id'] ?? 'playlist_default';

$now = new DateTime('now', new DateTimeZone($settings['system']['timezone'] ?? 'Europe/Zurich'));
$dayMap = [
    'Mon' => 'mon',
    'Tue' => 'tue',
    'Wed' => 'wed',
    'Thu' => 'thu',
    'Fri' => 'fri',
    'Sat' => 'sat',
    'Sun' => 'sun',
];
$dayKey = $dayMap[$now->format('D')] ?? 'mon';
$currentTime = $now->format('H:i');

foreach ($schedules as $candidate) {
    if (($candidate['display_id'] ?? '') !== $displayId) {
        continue;
    }

    $days = $candidate['days'] ?? [];
    if ($days !== [] && !in_array($dayKey, $days, true)) {
        continue;
    }

    $from = (string) ($candidate['from'] ?? '00:00');
    $to = (string) ($candidate['to'] ?? '23:59');
    if ($currentTime >= $from && $currentTime <= $to) {
        $playlistId = $candidate['playlist_id'] ?? $playlistId;
        break;
    }
}

$playlist = schauboard_find_by_id($playlists, $playlistId) ?? $playlists[0] ?? null;
$activeSlideIds = $playlist['slide_ids'] ?? [];
$activeSlides = [];
foreach ($activeSlideIds as $slideId) {
    $slide = schauboard_find_by_id($slides, (string) $slideId);
    if ($slide !== null) {
        $activeSlides[] = $slide;
    }
}

$currentSlide = $activeSlides[0] ?? null;
$currentBlocks = is_array($currentSlide['blocks'] ?? null) ? $currentSlide['blocks'] : [];

$stageStyle = 'background:#121b2d;';
if (is_array($currentSlide)) {
    $bgColor = (string) ($currentSlide['bg_color'] ?? '#1a1a2e');
    $bgImage = (string) ($currentSlide['bg_image'] ?? '');
    $stageStyle = 'background:' . htmlspecialchars($bgColor, ENT_QUOTES) . ';';
    if ($bgImage !== '') {
        $stageStyle = 'background:' . htmlspecialchars($bgColor, ENT_QUOTES) . ' url(' . htmlspecialchars($bgImage, ENT_QUOTES) . ') center/cover no-repeat;';
    }
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars((string) ($currentSlide['name'] ?? 'Schauboard Display')) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:100%;height:100%;overflow:hidden;background:#02060d;font-family:"Segoe UI",Arial,sans-serif}
.display-shell{position:fixed;inset:0;display:grid;place-items:center;background:#02060d}
.stage{position:relative;width:min(100vw, calc(100vh * 16 / 9));height:min(100vh, calc(100vw * 9 / 16));overflow:hidden}
.block{position:absolute;overflow:hidden}
.block.text{display:flex;align-items:flex-start;white-space:pre-wrap;line-height:1.12;font-weight:700;text-shadow:0 4px 18px rgba(0,0,0,.24);min-width:0;overflow-wrap:anywhere;word-break:break-word}
.block.clock{display:flex;align-items:center;justify-content:center;font-weight:800;letter-spacing:-.03em;text-shadow:0 4px 18px rgba(0,0,0,.24);min-width:0}
.block-inner{width:100%;height:100%;transform-origin:top left}
.block.clock .block-inner{display:flex;align-items:center;justify-content:center;padding:8px 18px;line-height:1}
.block.image img{width:100%;height:100%;object-fit:cover}
.empty{width:100%;height:100%;display:grid;place-items:center;color:rgba(255,255,255,.68);font-size:28px;letter-spacing:-.03em;background:#101828}
</style>
</head>
<body>
<div class="display-shell">
  <main class="stage" style="<?= $stageStyle ?>">
    <?php if ($currentSlide === null): ?>
      <div class="empty">Keine aktive Folie</div>
    <?php else: ?>
      <?php foreach ($currentBlocks as $block): ?>
        <?php
        $x = (float) (($block['x'] ?? 0) / 1920 * 100);
        $y = (float) (($block['y'] ?? 0) / 1080 * 100);
        $w = (float) (($block['w'] ?? 0) / 1920 * 100);
        $h = (float) (($block['h'] ?? 0) / 1080 * 100);
        $type = (string) ($block['type'] ?? 'text');
        $baseFontSize = max(18, (int) ($block['font_size'] ?? ($type === 'clock' ? 64 : 42)));
        $baseWidth = max(80.0, (float) ($block['base_w'] ?? ($type === 'clock' ? 320 : ($type === 'image' ? ($block['w'] ?? 520) : 420))));
        $baseHeight = max(48.0, (float) ($block['base_h'] ?? ($type === 'clock' ? 120 : ($type === 'image' ? ($block['h'] ?? 320) : 140))));
        $scale = ($type === 'image' || $type === 'clock')
            ? 1.0
            : max(0.3, min(
                max(0.01, (float) ($block['w'] ?? 0) / $baseWidth),
                max(0.01, (float) ($block['h'] ?? 0) / $baseHeight)
            ));
        $clockScaledFont = max(16, (int) round($baseFontSize * min(
            max(0.01, (float) ($block['w'] ?? 320) / 320),
            max(0.01, (float) ($block['h'] ?? 120) / 120)
        )));
        $clockFontSize = max(16, min(
            $clockScaledFont,
            (int) round(((float) ($block['h'] ?? 120)) * 0.72),
            (int) round(((float) ($block['w'] ?? 320)) / 3.1)
        ));
        $align = $block['align'] ?? 'left';
        $style = sprintf(
            'left:%.4f%%;top:%.4f%%;width:%.4f%%;height:%.4f%%;color:%s;text-align:%s;',
            $x,
            $y,
            $w,
            $h,
            htmlspecialchars((string) ($block['color'] ?? '#ffffff'), ENT_QUOTES),
            htmlspecialchars((string) $align, ENT_QUOTES)
        );
        ?>
        <?php if ($type === 'image' && !empty($block['src'])): ?>
          <div class="block image" style="<?= $style ?>">
            <img src="<?= htmlspecialchars((string) $block['src']) ?>" alt="">
          </div>
        <?php elseif ($type === 'clock'): ?>
          <div class="block clock" style="<?= $style ?>">
            <div class="block-inner js-clock" style="width:100%;height:100%;transform:none;font-size:<?= $clockFontSize ?>px;text-align:<?= htmlspecialchars((string) $align, ENT_QUOTES) ?>;">00:00</div>
          </div>
        <?php else: ?>
          <div class="block text" style="<?= $style ?>">
            <div class="block-inner" style="width:<?= htmlspecialchars((string) (100 / $scale)) ?>%;height:<?= htmlspecialchars((string) (100 / $scale)) ?>%;transform:scale(<?= htmlspecialchars((string) $scale) ?>);font-size:<?= $baseFontSize ?>px;text-align:<?= htmlspecialchars((string) $align, ENT_QUOTES) ?>;"><?= nl2br(htmlspecialchars((string) ($block['text'] ?? 'Textblock'))) ?></div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>
</div>
<script>
function updateClocks() {
  const now = new Date();
  const value = now.toLocaleTimeString('de-CH', {hour: '2-digit', minute: '2-digit'});
  document.querySelectorAll('.js-clock').forEach((node) => {
    node.textContent = value;
  });
}
updateClocks();
setInterval(updateClocks, 1000);
</script>
</body>
</html>
