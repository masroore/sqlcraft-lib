<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Platform;

interface TypeMapperInterface
{
    public function mapPhpTypeToDb(string $phpType): string;

    /** @return list<string> */
    public function getSupportedTypes(): array;

    /** @return list<string> */
    public function getUnsignedTypes(): array;

    /** @return list<string> */
    public function getCollatableTypes(): array;
}
