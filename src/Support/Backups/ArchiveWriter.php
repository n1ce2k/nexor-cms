<?php

namespace Nexor\Cms\Support\Backups;

use RuntimeException;

/**
 * Пишет tar.gz, который можно дописывать с перерывами и резать на тома.
 *
 * Хостинг даёт запросу секунды, поэтому архив пишется кусками: каждый кусок —
 * отдельный gzip-блок, дописанный в конец файла. Склеенные gzip-блоки — это
 * обычный gzip, его открывает любой распаковщик. Тома режутся только между
 * блоками: `.tar.gz.001`, `.002`… склеиваются обратно простой конкатенацией
 * (`cat`, `copy /b`, а 7-Zip открывает `.001` и собирает остальные сам).
 *
 * Состояние между шагами — массив `state()`: номер тома и его размер. Буфер
 * между шагами не живёт — `flush()` в конце каждого шага обязателен.
 */
class ArchiveWriter
{
    protected const BLOCK = 512;

    /** Столько несжатых байт копится перед gzip-блоком. */
    protected const CHUNK = 2 * 1024 * 1024;

    protected string $buffer = '';

    /**
     * @param  string  $directory  папка с копиями
     * @param  string  $name  имя архива без расширения
     * @param  int|null  $partBytes  предел тома; null — одним файлом
     * @param  array{part?: int, part_size?: int, parts?: array<int, array{name: string, size: int}>}  $state
     */
    public function __construct(
        protected string $directory,
        protected string $name,
        protected ?int $partBytes = null,
        protected array $state = [],
    ) {
        $this->state += ['part' => 1, 'part_size' => 0, 'parts' => []];
    }

    /**
     * @return array{part: int, part_size: int, parts: array<int, array{name: string, size: int}>}
     */
    public function state(): array
    {
        return $this->state;
    }

    /**
     * Имя тома: одним файлом — `name.tar.gz`, частями — `name.tar.gz.001`.
     */
    public function partName(int $part): string
    {
        return $this->name.'.tar.gz'.($this->partBytes === null ? '' : '.'.str_pad((string) $part, 3, '0', STR_PAD_LEFT));
    }

    public function addDirectory(string $path, int $mtime): void
    {
        $this->header(rtrim($path, '/').'/', 0, $mtime, 0755, '5');
    }

    /**
     * Заголовок файла. Содержимое — `write()`, ровно `$size` байт, затем `pad()`.
     */
    public function beginFile(string $path, int $size, int $mtime): void
    {
        $this->header($path, $size, $mtime, 0644, '0');
    }

    public function write(string $bytes): void
    {
        $this->buffer .= $bytes;
        $chunk = $this->chunk();

        // Большой кусок режется на блоки по `chunk()`: один блок больше тома
        // не влез бы ни в какой том.
        while (strlen($this->buffer) >= $chunk) {
            $rest = substr($this->buffer, $chunk);
            $this->buffer = substr($this->buffer, 0, $chunk);
            $this->flush();
            $this->buffer = $rest;
        }
    }

    /**
     * Добивает содержимое файла нулями до границы блока tar.
     */
    public function pad(int $size): void
    {
        $rest = $size % self::BLOCK;

        if ($rest !== 0) {
            $this->write(str_repeat("\0", self::BLOCK - $rest));
        }
    }

    /**
     * Конец архива: два пустых блока.
     */
    public function finish(): void
    {
        $this->write(str_repeat("\0", self::BLOCK * 2));
        $this->flush();
    }

    /**
     * Сжимает накопленное в gzip-блок и дописывает в текущий том.
     */
    public function flush(): void
    {
        if ($this->buffer === '') {
            return;
        }

        $compressed = gzencode($this->buffer, 6);
        $this->buffer = '';

        if ($compressed === false) {
            throw new RuntimeException('Не удалось сжать данные архива.');
        }

        if ($this->partBytes !== null
            && $this->state['part_size'] > 0
            && $this->partBytes < $this->state['part_size'] + strlen($compressed)) {
            $this->state['part']++;
            $this->state['part_size'] = 0;
        }

        $name = $this->partName($this->state['part']);
        $path = $this->directory.DIRECTORY_SEPARATOR.$name;

        if (file_put_contents($path, $compressed, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Не удалось записать '.$name.' — проверьте место на диске и права на папку.');
        }

        $this->state['part_size'] += strlen($compressed);
        $this->state['parts'][$this->state['part'] - 1] = ['name' => $name, 'size' => $this->state['part_size']];
    }

    /**
     * Блок gzip меньше тома: иначе один блок не влез бы ни в какой том.
     */
    protected function chunk(): int
    {
        return $this->partBytes === null ? self::CHUNK : max(self::BLOCK, min(self::CHUNK, intdiv($this->partBytes, 4)));
    }

    /**
     * Заголовок ustar. Длинный путь делится на prefix и name, а не влезающий
     * и так — уходит в расширенный заголовок PAX (POSIX), его понимают tar,
     * bsdtar и 7-Zip.
     */
    protected function header(string $path, int $size, int $mtime, int $mode, string $type): void
    {
        [$prefix, $name] = $this->splitPath($path);

        if ($name === null) {
            $record = $this->paxRecord('path', $path);

            $this->write($this->block('PaxHeader/'.substr(basename($path), 0, 80), strlen($record), $mtime, 0644, 'x', ''));
            $this->write($record);
            $this->pad(strlen($record));

            [$prefix, $name] = ['', substr($path, 0, 100)];
        }

        $this->write($this->block($name, $size, $mtime, $mode, $type, $prefix));
    }

    /**
     * Запись PAX «длина ключ=значение\n», где длина считает и саму себя.
     */
    protected function paxRecord(string $key, string $value): string
    {
        $body = ' '.$key.'='.$value."\n";
        $length = strlen($body) + strlen((string) strlen($body));

        if (strlen((string) $length) !== strlen((string) strlen($body))) {
            $length++;
        }

        return $length.$body;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    protected function splitPath(string $path): array
    {
        if (strlen($path) <= 100) {
            return ['', $path];
        }

        // Режем по «/» так, чтобы хвост влез в name (100), а голова — в prefix (155).
        $cut = strrpos(substr($path, 0, 156), '/');

        while ($cut !== false && $cut > 0) {
            if (strlen($path) - $cut - 1 <= 100) {
                return [substr($path, 0, $cut), substr($path, $cut + 1)];
            }

            $cut = strrpos(substr($path, 0, $cut), '/');
        }

        return ['', null];
    }

    protected function block(string $name, int $size, int $mtime, int $mode, string $type, string $prefix): string
    {
        $header = str_pad($name, 100, "\0")
            .$this->octal($mode, 8)
            .$this->octal(0, 8)
            .$this->octal(0, 8)
            .$this->size($size)
            .$this->octal(max(0, $mtime), 12)
            .str_repeat(' ', 8)
            .$type
            .str_repeat("\0", 100)
            ."ustar\0".'00'
            .str_pad('', 32, "\0")
            .str_pad('', 32, "\0")
            .$this->octal(0, 8)
            .$this->octal(0, 8)
            .str_pad($prefix, 155, "\0")
            .str_repeat("\0", 12);

        $checksum = array_sum(array_map('ord', str_split($header)));

        return substr_replace($header, sprintf('%06o', $checksum)."\0 ", 148, 8);
    }

    protected function octal(int $value, int $length): string
    {
        return str_pad(decoct($value), $length - 1, '0', STR_PAD_LEFT)."\0";
    }

    /**
     * Размер больше 8 ГБ в восьмеричные 11 знаков не влезает — тогда base-256.
     */
    protected function size(int $size): string
    {
        if ($size < 8 ** 11) {
            return $this->octal($size, 12);
        }

        $bytes = '';

        for ($i = 0; $i < 11; $i++) {
            $bytes = chr($size & 0xFF).$bytes;
            $size >>= 8;
        }

        return chr(0x80).$bytes;
    }
}
