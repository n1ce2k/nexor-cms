<?php

namespace Nexor\Cms\Support\Backups;

use Closure;
use RuntimeException;

/**
 * Распаковывает tar.gz по шагам.
 *
 * Копия NEXOR — это склеенные gzip-блоки, поэтому распаковку можно прервать на
 * границе блока и продолжить в следующем запросе: zlib сообщает, где блок
 * кончился, а состояние разбора tar (текущий файл, сколько из него записано)
 * сохраняется рядом. Архив другой программы обычно одним блоком — он тоже
 * распакуется, только за один долгий шаг.
 *
 * Куда писать каждую запись, решает `$target`: путь на диске или null —
 * пропустить.
 */
class ArchiveExtractor
{
    protected const BLOCK = 512;

    /** Сколько сжатых байт подаётся в zlib за раз. */
    protected const READ = 256 * 1024;

    /** @var array<string, mixed> */
    protected array $state;

    /**
     * @param  Closure(string $path, string $type): (string|null)  $target
     * @param  array<string, mixed>  $state
     */
    public function __construct(protected VolumeReader $reader, protected Closure $target, array $state = [])
    {
        $this->state = $state + [
            'offset' => 0,
            'pending' => '',
            'entry' => null,
            'pax_path' => null,
            'finished' => false,
            'written' => 0,
            'skipped' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->state;
    }

    public function finished(): bool
    {
        return $this->state['finished'];
    }

    /**
     * Распаковывает блок за блоком, пока не выйдет время.
     */
    public function run(?float $deadline): void
    {
        $this->resumeFile();

        while (! $this->state['finished']) {
            $this->member();

            if ($deadline !== null && microtime(true) >= $deadline) {
                return;
            }
        }
    }

    /**
     * Один gzip-блок целиком. Сохраняется состояние только на границе блоков:
     * если процесс оборвётся посреди блока, следующий шаг прочтёт его заново.
     */
    protected function member(): void
    {
        $start = $this->state['offset'];
        $position = $start;
        $context = inflate_init(ZLIB_ENCODING_GZIP);
        $fed = false;

        while (true) {
            $chunk = $this->reader->read($position, self::READ);

            if ($chunk === '') {
                if (! $fed) {
                    // Блоков больше нет — архив кончился.
                    $this->state['finished'] = true;

                    return;
                }

                throw new RuntimeException('Архив оборвался: не хватает тома или файл скачан не до конца.');
            }

            $fed = true;
            $output = @inflate_add($context, $chunk, ZLIB_SYNC_FLUSH);

            if ($output === false) {
                throw new RuntimeException('Архив повреждён: не удалось распаковать данные с позиции '.$start.'.');
            }

            $this->feed($output);

            if (inflate_get_status($context) === ZLIB_STREAM_END) {
                $this->state['offset'] = $start + inflate_get_read_len($context);

                return;
            }

            $position += strlen($chunk);
        }
    }

    /**
     * Разбор tar: заголовки, содержимое, выравнивание.
     */
    protected function feed(string $data): void
    {
        while ($data !== '' && ! $this->state['finished']) {
            $entry = &$this->state['entry'];

            if ($entry === null) {
                $need = self::BLOCK - strlen($this->state['pending']);
                $this->state['pending'] .= substr($data, 0, $need);
                $data = (string) substr($data, $need);

                if (strlen($this->state['pending']) < self::BLOCK) {
                    return;
                }

                $header = $this->state['pending'];
                $this->state['pending'] = '';
                $this->header($header);

                continue;
            }

            if ($entry['remaining'] > 0) {
                $take = (string) substr($data, 0, $entry['remaining']);
                $data = (string) substr($data, strlen($take));
                $entry['remaining'] -= strlen($take);
                $this->content($take);
            } elseif ($entry['padding'] > 0) {
                $take = min($entry['padding'], strlen($data));
                $data = (string) substr($data, $take);
                $entry['padding'] -= $take;
            }

            if ($entry['remaining'] === 0 && $entry['padding'] === 0) {
                $this->close();
            }
        }
    }

    protected function header(string $header): void
    {
        // Пустой блок — конец архива; второй за ним уже не важен.
        if (trim($header, "\0") === '') {
            $this->state['finished'] = true;

            return;
        }

        $stored = octdec(trim(substr($header, 148, 8), "\0 "));
        $actual = array_sum(array_map('ord', str_split(substr_replace($header, str_repeat(' ', 8), 148, 8))));

        if ($stored !== $actual) {
            throw new RuntimeException('Архив повреждён: неверная контрольная сумма записи tar.');
        }

        $name = rtrim(substr($header, 0, 100), "\0");
        $prefix = substr($header, 257, 5) === 'ustar' ? rtrim(substr($header, 345, 155), "\0") : '';
        $size = $this->size(substr($header, 124, 12));
        $type = $header[156] === "\0" ? '0' : $header[156];

        $path = $this->state['pax_path'] ?? ($prefix === '' ? $name : $prefix.'/'.$name);
        $this->state['pax_path'] = null;

        $kind = match ($type) {
            'x' => 'pax',
            '0', '7' => 'file',
            '5' => 'dir',
            default => 'skip',
        };

        $target = in_array($kind, ['file', 'dir'], true) ? ($this->target)($path, $kind) : null;

        if ($kind === 'dir' && $target !== null && ! is_dir($target)) {
            @mkdir($target, 0755, true);
        }

        if ($kind === 'file') {
            if ($target === null) {
                $this->state['skipped']++;
            } else {
                $this->open($target);
            }
        }

        $this->state['entry'] = [
            'kind' => $kind,
            'path' => $path,
            'target' => $kind === 'file' ? $target : null,
            'mtime' => (int) octdec(trim(substr($header, 136, 12), "\0 ")),
            'remaining' => $kind === 'dir' ? 0 : $size,
            'padding' => $kind === 'dir' ? 0 : (self::BLOCK - $size % self::BLOCK) % self::BLOCK,
            'done' => 0,
            'pax' => '',
        ];

        if ($this->state['entry']['remaining'] === 0 && $this->state['entry']['padding'] === 0) {
            $this->close();
        }
    }

    protected function content(string $bytes): void
    {
        $entry = &$this->state['entry'];

        if ($entry['kind'] === 'pax') {
            $entry['pax'] .= $bytes;
        } elseif ($entry['kind'] === 'file' && $entry['target'] !== null) {
            if (@file_put_contents($entry['target'], $bytes, FILE_APPEND) === false) {
                throw new RuntimeException('Не удалось записать '.$entry['target'].self::reason());
            }
        }

        $entry['done'] += strlen($bytes);
    }

    protected function close(): void
    {
        $entry = $this->state['entry'];
        $this->state['entry'] = null;

        if ($entry['kind'] === 'pax' && preg_match('/(?:^|\n)\d+ path=([^\n]*)\n/', $entry['pax'], $match)) {
            $this->state['pax_path'] = $match[1];
        }

        if ($entry['kind'] === 'file' && $entry['target'] !== null) {
            @touch($entry['target'], $entry['mtime'] ?: time());
            $this->state['written']++;
        }
    }

    /**
     * Новый файл начинается с пустого: старое содержимое заменяется целиком.
     */
    protected function open(string $target): void
    {
        $directory = dirname($target);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Не удалось создать папку '.$directory.self::reason());
        }

        if (@file_put_contents($target, '') === false) {
            throw new RuntimeException('Не удалось записать '.$target.self::reason());
        }
    }

    /**
     * Настоящая причина от PHP: «Permission denied», «Read-only file system»,
     * «open_basedir restriction» — по ней и видно, что чинить.
     */
    public static function reason(): string
    {
        $message = error_get_last()['message'] ?? '';
        $message = trim((string) preg_replace('/^.*?\):\s*/', '', $message));

        return $message === '' ? '.' : ': '.$message.'.';
    }

    /**
     * Шаг прервался посреди файла — после границы блока могло дописаться
     * лишнее. Обрезаем файл до того, что записано к началу блока.
     */
    protected function resumeFile(): void
    {
        $entry = $this->state['entry'];

        if ($entry === null || $entry['kind'] !== 'file' || $entry['target'] === null || ! is_file($entry['target'])) {
            return;
        }

        $handle = fopen($entry['target'], 'r+');
        ftruncate($handle, $entry['done']);
        fclose($handle);
    }

    protected function size(string $field): int
    {
        // base-256: старший бит первого байта — размер больше 8 ГБ.
        if ((ord($field[0]) & 0x80) !== 0) {
            $size = 0;

            for ($i = 1; $i < 12; $i++) {
                $size = ($size << 8) | ord($field[$i]);
            }

            return $size;
        }

        return (int) octdec(trim($field, "\0 "));
    }
}
