<?php

declare(strict_types=1);

namespace SQLCraft\Query;

use InvalidArgumentException;
use SQLCraft\Contracts\Execution\StatementSplitterInterface;
use SQLCraft\Contracts\Execution\StatementBatch;

final readonly class StatementSplitter implements StatementSplitterInterface
{
    #[\Override]
    public function split(string $sql, string $delimiter = ';'): StatementBatch
    {
        if ($delimiter === '') {
            throw new InvalidArgumentException('Statement delimiter cannot be empty.');
        }

        $activeDelimiter = $delimiter;
        $normalized = $this->removeDelimiterDirectives($sql);
        $statements = [];
        $buffer = '';
        $quote = null;
        $lineComment = false;
        $blockComment = false;
        $length = strlen($normalized);

        for ($index = 0; $index < $length; $index++) {
            $character = $normalized[$index];
            $next = $index + 1 < $length ? $normalized[$index + 1] : null;

            if ($character === "\x1D") {
                $end = strpos($normalized, "\x1E", $index + 1);
                if ($end === false) {
                    throw new InvalidArgumentException('Malformed DELIMITER directive.');
                }
                $activeDelimiter = substr($normalized, $index + 1, $end - $index - 1);
                $index = $end;
                continue;
            }

            if ($lineComment) {
                $buffer .= $character;
                if ($character === "\n") {
                    $lineComment = false;
                }
                continue;
            }

            if ($blockComment) {
                $buffer .= $character;
                if ($character === '*' && $next === '/') {
                    $buffer .= '/';
                    $index++;
                    $blockComment = false;
                }
                continue;
            }

            if ($quote !== null) {
                $buffer .= $character;
                if ($character === '\\' && $next !== null) {
                    $buffer .= $next;
                    $index++;
                    continue;
                }
                if ($character === $quote) {
                    if ($next === $quote) {
                        $buffer .= $next;
                        $index++;
                        continue;
                    }
                    $quote = null;
                }
                continue;
            }

            if (($character === '-' && $next === '-' && ($index + 2 >= $length || ctype_space($normalized[$index + 2]))) || $character === '#') {
                $buffer .= $character;
                if ($character === '-') {
                    $buffer .= '-';
                    $index++;
                }
                $lineComment = true;
                continue;
            }

            if ($character === '/' && $next === '*') {
                $buffer .= '/*';
                $index++;
                $blockComment = true;
                continue;
            }

            if (in_array($character, ["'", '"', '`'], true)) {
                $quote = $character;
                $buffer .= $character;
                continue;
            }

            if (substr($normalized, $index, strlen($activeDelimiter)) === $activeDelimiter) {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                $index += strlen($activeDelimiter) - 1;
                continue;
            }

            $buffer .= $character;
        }

        $statement = trim($buffer);
        if ($statement !== '') {
            $statements[] = $statement;
        }

        return new StatementBatch($statements);
    }

    private function removeDelimiterDirectives(string $sql): string
    {
        $lines = preg_split('/(?<=\n)/', $sql);
        if ($lines === false) {
            return '';
        }
        $body = '';
        foreach ($lines as $line) {
            if (preg_match('/^\s*DELIMITER\s+(\S+)\s*(?:\r?\n)?$/i', $line, $matches) === 1) {
                $body .= "\x1D" . $matches[1] . "\x1E";
                continue;
            }
            $body .= $line;
        }

        return $body;
    }
}
