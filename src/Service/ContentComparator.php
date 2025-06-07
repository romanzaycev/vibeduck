<?php declare(strict_types=1);

namespace Romanzaycev\Vibe\Service;

class ContentComparator
{
    /**
     * Получает разницу между содержимым файла и новым содержимым
     * с объединением последовательных изменений
     */
    public function getDiff(string $filePath, string $newContent): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $oldContent = file_get_contents($filePath);

        // Используем explode вместо preg_split для лучшей производительности
        $oldLines = explode("\n", str_replace("\r\n", "\n", $oldContent));
        $newLines = explode("\n", str_replace("\r\n", "\n", $newContent));

        // Освобождаем память
        unset($oldContent, $newContent);

        // Используем более эффективный алгоритм Myers diff
        return $this->computeDiff($oldLines, $newLines);
    }

    /**
     * Вычисляет разницу между двумя массивами строк, используя алгоритм Myers
     */
    private function computeDiff(array $oldLines, array $newLines): array
    {
        $oldCount = count($oldLines);
        $newCount = count($newLines);

        // Быстрая проверка идентичности
        if ($oldCount === 0 && $newCount === 0) {
            return [];
        }

        // Оптимизация: поиск общего префикса и суффикса
        $start = 0;
        while ($start < $oldCount && $start < $newCount && $oldLines[$start] === $newLines[$start]) {
            $start++;
        }

        $end = 0;
        while ($start + $end < $oldCount && $start + $end < $newCount &&
            $oldLines[$oldCount - 1 - $end] === $newLines[$newCount - 1 - $end]) {
            $end++;
        }

        // Обрезаем массивы до фактических различий
        if ($start > 0 || $end > 0) {
            $oldTrimmed = array_slice($oldLines, $start, $oldCount - $start - $end);
            $newTrimmed = array_slice($newLines, $start, $newCount - $start - $end);
            $oldCount = count($oldTrimmed);
            $newCount = count($newTrimmed);
        } else {
            $oldTrimmed = $oldLines;
            $newTrimmed = $newLines;
        }

        // Вычисляем операции редактирования
        $operations = $this->getEditOperations($oldTrimmed, $newTrimmed);

        // Строим результат с группировкой последовательных изменений
        return $this->buildGroupedDiff($oldLines, $newLines, $operations, $start);
    }

    /**
     * Получает операции редактирования с использованием алгоритма Myers
     */
    private function getEditOperations(array $oldLines, array $newLines): array
    {
        $oldCount = count($oldLines);
        $newCount = count($newLines);

        // Оптимизация для пустых массивов
        if ($oldCount === 0) {
            return array_fill(0, $newCount, ['add']);
        }
        if ($newCount === 0) {
            return array_fill(0, $oldCount, ['remove']);
        }

        // Алгоритм Myers для нахождения кратчайшего пути редактирования
        $max = $oldCount + $newCount;
        $v = [-1 => 0];
        $trace = [];

        for ($d = 0; $d <= $max; $d++) {
            $trace[] = $v;

            for ($k = -$d; $k <= $d; $k += 2) {
                if ($k === -$d || ($k !== $d && $v[$k-1] < $v[$k+1])) {
                    $x = $v[$k+1];
                } else {
                    $x = $v[$k-1] + 1;
                }

                $y = $x - $k;

                while ($x < $oldCount && $y < $newCount && ($oldLines[$x] ?? null) === ($newLines[$y] ?? null)) {
                    $x++;
                    $y++;
                }

                $v[$k] = $x;

                if ($x >= $oldCount && $y >= $newCount) {
                    // Нашли путь, восстанавливаем операции редактирования
                    return $this->backtrackPath($trace, $oldLines, $newLines);
                }
            }
        }

        // Если мы здесь, что-то пошло не так (не должно происходить)
        return [];
    }

    /**
     * Восстанавливает путь редактирования из трассировки алгоритма Myers
     */
    private function backtrackPath(array $trace, array $oldLines, array $newLines): array
    {
        $operations = [];
        $x = count($oldLines);
        $y = count($newLines);

        for ($d = count($trace) - 1; $d >= 0; $d--) {
            $v = $trace[$d];
            $k = $x - $y;

            if ($k === -$d || ($k !== $d && $v[$k-1] < $v[$k+1])) {
                $prevK = $k + 1;
            } else {
                $prevK = $k - 1;
            }

            $prevX = $v[$prevK] ?? null;

            if ($prevX !== null) {
                $prevY = $prevX - $prevK;

                while ($x > $prevX && $y > $prevY) {
                    array_unshift($operations, ['equal']);
                    $x--;
                    $y--;
                }

                if ($d > 0) {
                    if ($x === $prevX) {
                        array_unshift($operations, ['add']);
                    } else {
                        array_unshift($operations, ['remove']);
                    }
                }

                $x = $prevX;
                $y = $prevY;
            }
        }

        return $operations;
    }

    /**
     * Строит окончательный результат с группировкой последовательных изменений
     */
    private function buildGroupedDiff(array $oldLines, array $newLines, array $operations, int $startOffset): array
    {
        $diffs = [];
        $oldPos = $startOffset;
        $newPos = $startOffset;
        $currentGroup = null;
        $lineNumber = $startOffset + 1;

        foreach ($operations as $op) {
            switch ($op[0]) {
                case 'equal':
                    // Если есть текущая группа изменений, добавляем ее в результат
                    if ($currentGroup !== null) {
                        $diffs[] = $currentGroup;
                        $currentGroup = null;
                    }

                    $oldPos++;
                    $newPos++;
                    $lineNumber++;
                    break;

                case 'remove':
                    if ($currentGroup === null) {
                        $currentGroup = [
                            '-' => $oldLines[$oldPos],
                            '+' => '',
                            'l' => $lineNumber
                        ];
                    } else {
                        $currentGroup['-'] .= "\n" . $oldLines[$oldPos];
                    }

                    $oldPos++;
                    $lineNumber++;
                    break;

                case 'add':
                    if ($currentGroup === null) {
                        $currentGroup = [
                            '-' => '',
                            '+' => $newLines[$newPos],
                            'l' => $lineNumber
                        ];
                    } else {
                        $currentGroup['+'] .= "\n" . $newLines[$newPos];
                    }

                    $newPos++;
                    $lineNumber++;
                    break;
            }
        }

        // Добавляем последнюю группу, если она есть
        if ($currentGroup !== null) {
            $diffs[] = $currentGroup;
        }

        return $diffs;
    }
}
