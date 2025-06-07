<?php

declare(strict_types=1);

namespace Romanzaycev\Vibe\Service;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;

class MarkdownConsoleRenderer
{
    private MarkdownParser $parser;

    private array $decodeEntitiesMap = [
        '&lt;' => '<',
        '&gt;' => '>',
        '&amp;' => '&', // & должен идти последним при замене, или использоваться более сложный подход
        '&quot;' => '"',
        '&#039;' => "'", // апостроф
        '&#x60;' => '`',
    ];

    public function __construct()
    {
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());
        // Можно будет добавить расширения CommonMark по мере необходимости (таблицы, сноски и т.д.)
        // $environment->addExtension(new GithubFlavoredMarkdownExtension()); // для табличек GFM

        $this->parser = new MarkdownParser($environment);
    }

    public function render(string $markdown): string
    {
        if (empty(mb_trim($markdown))) {
            return '';
        }
        $documentNode = $this->parser->parse($markdown);

        return mb_trim($this->finalizeOutput($this->renderNode($documentNode)));
    }

    private function finalizeOutput(string $content): string
    {
        // 1. Декодируем HTML сущности, которые мы хотим видеть как символы в консоли
        // Важно: &amp; должен быть декодирован первым, если другие сущности содержат &
        // Либо использовать str_replace с массивами, где порядок имеет значение.
        // Наиболее безопасный способ - htmlspecialchars_decode, но он декодирует всё.
        // Если мы хотим только наш ограниченный набор:
        $content = str_replace(array_keys($this->decodeEntitiesMap), array_values($this->decodeEntitiesMap), $content);

        // 2. Нормализация переносов строк: не более двух подряд
        // Заменяем 3 и более переносов на 2
        return preg_replace("/\n{3,}/", "\n\n", $content);
    }

    private function renderNode(Node $node, string $listItemMarker = ''): string
    {
        $rendered = '';
        $children = $node->children();

        if ($node instanceof Heading) {
            $level = $node->getLevel();
            $prefix = str_repeat('#', $level) . ' ';
            // Для консоли сделаем все заголовки одного стиля, но можно и менять в зависимости от level
            $rendered .= "<fg=yellow;options=bold>" . $prefix;
            foreach ($children as $child) $rendered .= $this->renderNode($child);
            $rendered .= "</>\n\n";
        } elseif ($node instanceof Paragraph) {
            foreach ($children as $child) $rendered .= $this->renderNode($child);
            $rendered .= "\n\n"; // Два переноса после параграфа
        } elseif ($node instanceof ThematicBreak) {
            $rendered .= "<fg=gray>" . str_repeat('-', 50) . "</>\n\n";
        } elseif ($node instanceof FencedCode) {
            $language = $node->getInfo(); // язык после ```
            $code = $node->getLiteral();
            $rendered .= $this->renderFencedCode($code, $language) . "\n"; // Перенос после блока кода
        } elseif ($node instanceof ListItem) {
            // CommonMark обрабатывает вложенность сам, нам нужно только отрендерить детей
            $rendered .= $listItemMarker; // маркер от родительского узла списка (UL/OL)
            foreach ($children as $child) {
                // Важно: если дочерний элемент это Paragraph, то он добавит свои \n\n.
                // Нам нужно это учесть, чтобы не было лишних отступов внутри элемента списка.
                // Пока упрощенно, потом можно доработать node walker
                $childContent = $this->renderNode($child);
                // Убираем лишние переносы строк в конце параграфов внутри списков
                if ($child instanceof Paragraph) {
                    $childContent = mb_rtrim($childContent, "\n") . "\n"; // Оставляем один перенос
                }
                $rendered .= $childContent;
            }
            // $rendered .= "\n"; // Перенос после каждого элемента списка
        } elseif ($node instanceof \League\CommonMark\Extension\CommonMark\Node\Block\ListBlock) { // UL или OL
            $listType = $node->getListData()->type; // 'bullet' или 'ordered'
            $startNumber = $node->getListData()->start ?? 1;
            $itemNumber = $startNumber;
            foreach ($children as $child) { // Дети это ListItem
                $marker = ($listType === 'bullet') ? ' * ' : " {$itemNumber}. ";
                $rendered .= $this->renderNode($child, $marker); // Передаем маркер
                if ($listType === 'ordered') $itemNumber++;
            }
            $rendered .= "\n"; // Один перенос после всего списка
        } elseif ($node instanceof Strong) {
            $rendered .= "<options=bold>";
            foreach ($children as $child) $rendered .= $this->renderNode($child);
            $rendered .= "</>";
        } elseif ($node instanceof Emphasis) {
            // Symfony Console не имеет прямого курсива. Используем другой цвет или стиль.
            $rendered .= "<fg=#80a0ff>"; // Пример цвета для курсива
            foreach ($children as $child) $rendered .= $this->renderNode($child);
            $rendered .= "</>";
        } elseif ($node instanceof Link) {
            $url = htmlspecialchars($node->getUrl()); // Symfony Console теги не должны содержать HTML сущности
            $textRendered = '';
            foreach ($children as $child) $textRendered .= $this->renderNode($child);
            $rendered .= "<href={$url}>{$textRendered}</>";
        } elseif ($node instanceof \League\CommonMark\Node\StringContainerInterface) {
            $rendered .= htmlspecialchars($node->getLiteral()); // Symfony Console теги не должны содержать HTML сущности
        } else {
            // Для других узлов (DocumentNode, etc.) просто рендерим их детей
            foreach ($children as $child) {
                $rendered .= $this->renderNode($child, $listItemMarker);
            }
        }
        return $rendered;
    }

    private function renderFencedCode(string $code, ?string $language): string
    {
        $code = mb_rtrim($code, "\n"); // Убираем последний перенос строки, если он есть, для консистентности

        if ($language) {
            try {
                return "<bg=black;fg=white>\n" . $code . "\n</>"; // Добавляем фон и отступы
            } catch (\Exception $e) {
                // Язык не поддерживается или ошибка подсветки
                // error_log("Highlighting error for lang '{$language}': " . $e->getMessage());
            }
        }
        // Если нет языка или подсветка не удалась, выводим как есть, но в тегах comment
        // и с htmlspecialchars для безопасности, если код содержит что-то похожее на теги
        return "<bg=black;fg=white>\n" . htmlspecialchars($code) . "\n</>";
    }
}
