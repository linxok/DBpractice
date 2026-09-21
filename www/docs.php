<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

$directory = '/var/www/docs';
$files = [];
foreach (glob($directory . '/*.md') ?: [] as $path) {
    $files[basename($path)] = $path;
}
ksort($files);

$selected = (string) ($_GET['file'] ?? '');
if ($selected === '' || !isset($files[$selected])) {
    $selected = (string) (array_key_first($files) ?? '');
}

function inline_markdown(string $text): string
{
    $text = e($text);
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text);
    $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $text);
    return (string) $text;
}

function markdown_to_html(string $markdown): string
{
    $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
    $html = '';
    $paragraph = [];
    $code = [];
    $inCode = false;
    $listType = null;
    $tableRows = [];

    $flushParagraph = static function () use (&$paragraph, &$html): void {
        if ($paragraph !== []) {
            $html .= '<p>' . inline_markdown(implode(' ', $paragraph)) . '</p>';
            $paragraph = [];
        }
    };

    $flushList = static function () use (&$listType, &$html): void {
        if ($listType !== null) {
            $html .= '</' . $listType . '>';
            $listType = null;
        }
    };

    $flushTable = static function () use (&$tableRows, &$html): void {
        if ($tableRows === []) {
            return;
        }
        $html .= '<table>';
        $header = array_shift($tableRows);
        if (isset($tableRows[0]) && is_array($tableRows[0]) && preg_match('/^[\s|:-]+$/', implode('|', $tableRows[0]))) {
            array_shift($tableRows);
        }
        $html .= '<thead><tr>';
        foreach ($header as $cell) {
            $html .= '<th>' . inline_markdown(trim($cell)) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($tableRows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . inline_markdown(trim($cell)) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        $tableRows = [];
    };

    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '```')) {
            if ($inCode) {
                $html .= '<pre><code>' . e(implode("\n", $code)) . '</code></pre>';
                $code = [];
                $inCode = false;
            } else {
                $flushParagraph();
                $flushList();
                $flushTable();
                $inCode = true;
            }
            continue;
        }

        if ($inCode) {
            $code[] = $line;
            continue;
        }

        if (preg_match('/^\|(.+)\|$/', trim($line))) {
            $flushParagraph();
            $flushList();
            $cells = explode('|', trim($line, " \t|"));
            $tableRows[] = $cells;
            continue;
        }
        $flushTable();

        if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $match)) {
            $flushParagraph();
            $flushList();
            $level = strlen($match[1]);
            $html .= '<h' . $level . '>' . inline_markdown($match[2]) . '</h' . $level . '>';
            continue;
        }

        if (preg_match('/^\s*[-*]\s+(.*)$/', $line, $match)) {
            $flushParagraph();
            if ($listType !== 'ul') {
                $flushList();
                $html .= '<ul>';
                $listType = 'ul';
            }
            $html .= '<li>' . inline_markdown($match[1]) . '</li>';
            continue;
        }

        if (preg_match('/^\s*\d+\.\s+(.*)$/', $line, $match)) {
            $flushParagraph();
            if ($listType !== 'ol') {
                $flushList();
                $html .= '<ol>';
                $listType = 'ol';
            }
            $html .= '<li>' . inline_markdown($match[1]) . '</li>';
            continue;
        }

        if (preg_match('/^>\s?(.*)$/', $line, $match)) {
            $flushParagraph();
            $flushList();
            $html .= '<blockquote>' . inline_markdown($match[1]) . '</blockquote>';
            continue;
        }

        if (preg_match('/^\s*---+\s*$/', $line)) {
            $flushParagraph();
            $flushList();
            $html .= '<hr>';
            continue;
        }

        if (trim($line) === '') {
            $flushParagraph();
            $flushList();
            continue;
        }

        $paragraph[] = trim($line);
    }

    if ($inCode) {
        $html .= '<pre><code>' . e(implode("\n", $code)) . '</code></pre>';
    }
    $flushParagraph();
    $flushList();
    $flushTable();

    return $html;
}

page_header('Конспекти');
?>
<h1>Конспекти</h1>
<?php if ($files === []): ?>
    <p class="hint">Конспекти ще не створені. Вони з'являться у каталозі <code>docs/</code>.</p>
<?php else: ?>
    <div class="grid">
        <div class="card">
            <h3>Теми</h3>
            <ul>
                <?php foreach ($files as $name => $path): ?>
                    <li>
                        <?php if ($name === $selected): ?>
                            <strong><?= e(str_replace('.md', '', $name)) ?></strong>
                        <?php else: ?>
                            <a href="/docs.php?file=<?= urlencode($name) ?>"><?= e(str_replace('.md', '', $name)) ?></a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="card" style="grid-column: span 2;">
            <?= markdown_to_html((string) file_get_contents($files[$selected])) ?>
        </div>
    </div>
<?php endif; ?>
<?php page_footer(); ?>
