<?php

namespace Nexor\Cms\Support\Backups;

use RuntimeException;

/**
 * Читает тома архива как один поток: смещение считается от начала первого тома.
 */
class VolumeReader
{
    /** @var array<int, array{path: string, size: int}> */
    protected array $volumes = [];

    /**
     * @param  array<int, string>  $paths  тома по порядку
     */
    public function __construct(array $paths)
    {
        foreach ($paths as $path) {
            if (! is_file($path)) {
                throw new RuntimeException('Не найден том архива '.basename($path).'.');
            }

            $this->volumes[] = ['path' => $path, 'size' => (int) filesize($path)];
        }
    }

    public function size(): int
    {
        return (int) array_sum(array_column($this->volumes, 'size'));
    }

    /**
     * До `$length` байт с позиции `$offset`; пустая строка — поток кончился.
     */
    public function read(int $offset, int $length): string
    {
        $data = '';

        foreach ($this->volumes as $volume) {
            if ($offset >= $volume['size']) {
                $offset -= $volume['size'];

                continue;
            }

            $handle = fopen($volume['path'], 'rb');
            fseek($handle, $offset);
            $data .= (string) fread($handle, $length - strlen($data));
            fclose($handle);

            $offset = 0;

            if (strlen($data) >= $length) {
                break;
            }
        }

        return $data;
    }
}
