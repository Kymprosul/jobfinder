<?php

declare(strict_types=1);

namespace App\Storage;

interface StorageInterface
{
    public function load(string $name, array $default = []): array;

    public function save(string $name, array $data): void;

    public function append(string $name, array $record): void;
}
