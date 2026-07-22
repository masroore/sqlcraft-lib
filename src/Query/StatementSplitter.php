<?php

declare(strict_types=1);

namespace SQLCraft\Query;

use InvalidArgumentException;
use SQLCraft\Contracts\Execution\StatementBatch;
use SQLCraft\Contracts\Execution\StatementSplitterInterface;
use SQLCraft\Contracts\Execution\StreamingStatementSplitterInterface;

final readonly class StatementSplitter implements StatementSplitterInterface, StreamingStatementSplitterInterface
{
    #[\Override]
    public function split(string $sql, string $delimiter = ';'): StatementBatch
    {
        if ($delimiter === '') {
            throw new InvalidArgumentException('Statement delimiter cannot be empty.');
        }

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new InvalidArgumentException('Unable to create a temporary statement stream.');
        }
        fwrite($stream, $sql);
        rewind($stream);
        $statements = iterator_to_array($this->stream($stream, $delimiter), false);
        fclose($stream);

        /** @var list<string> $statements */
        return new StatementBatch($statements);
    }

    /**
     * @param  resource  $stream
     * @return \Generator<int, string, void, void>
     */
    #[\Override]
    public function splitStream($stream, string $delimiter = ';'): \Generator
    {
        if ($delimiter === '') {
            throw new InvalidArgumentException('Statement delimiter cannot be empty.');
        }

        yield from $this->stream($stream, $delimiter);
    }

    /**
     * @param  resource  $stream
     * @return \Generator<int, string, void, void>
     */
    private function stream($stream, string $initialDelimiter): \Generator
    {
        $delimiter = $initialDelimiter;
        $buffer = '';
        $quote = null;
        $lineComment = false;
        $blockComment = false;

        while (($line = fgets($stream)) !== false) {
            if (! $lineComment && ! $blockComment && $quote === null
                && preg_match('/^\s*DELIMITER\s+(\S+)\s*(?:\r?\n)?$/i', $line, $matches) === 1
            ) {
                yield from $this->flush($buffer);
                $buffer = '';
                $delimiter = $matches[1];

                continue;
            }

            $length = strlen($line);
            for ($index = 0; $index < $length; $index++) {
                $character = $line[$index];
                $next = $index + 1 < $length ? $line[$index + 1] : null;

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

                if (($character === '-' && $next === '-' && ($index + 2 >= $length || ctype_space($line[$index + 2]))) || $character === '#') {
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

                if ($delimiter !== '' && substr($line, $index, strlen($delimiter)) === $delimiter) {
                    yield from $this->flush($buffer);
                    $buffer = '';
                    $index += strlen($delimiter) - 1;

                    continue;
                }

                $buffer .= $character;
            }
        }

        yield from $this->flush($buffer);
    }

    /** @return \Generator<int, string, void, void> */
    private function flush(string $buffer): \Generator
    {
        $statement = trim($buffer);
        if ($statement === '' || preg_match('/^(?:\s*(?:--[^\r\n]*|#[^\r\n]*|\/\*.*?\*\/)(?:\s*))*$/s', $statement) === 1) {
            return;
        }

        yield $statement;
    }
}
