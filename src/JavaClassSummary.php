<?php

namespace PanelMcp;

/**
 * A `javap -p`-style outline of a compiled Java class, read straight from the bytes.
 *
 * It does not decompile method bodies. It gives the class, its parents, fields, method
 * signatures and the string constants, which is usually enough to see what a plugin does
 * (commands, permissions, messages, URLs) without running any Java on the panel host.
 */
class JavaClassSummary
{
    private const ACCESS = [0x0001 => 'public', 0x0002 => 'private', 0x0004 => 'protected', 0x0008 => 'static', 0x0010 => 'final', 0x0400 => 'abstract'];

    private string $bytes;

    private int $pos = 0;

    /** @var array<int, array> */
    private array $pool = [];

    public static function describe(string $bytes): string
    {
        return (new self())->parse($bytes);
    }

    private function parse(string $bytes): string
    {
        $this->bytes = $bytes;
        if (strlen($bytes) < 10 || $this->u4() !== 0xCAFEBABE) {
            throw new ToolException('That is not a Java class file.');
        }

        $minor = $this->u2();
        $major = $this->u2();
        $count = $this->u2();
        for ($i = 1; $i < $count; ++$i) {
            $tag = $this->u1();
            switch ($tag) {
                case 1: $len = $this->u2(); $this->pool[$i] = ['utf8', $this->take($len)]; break;
                case 3: case 4: $this->pool[$i] = ['num', $this->take(4)]; break;
                case 5: case 6: $this->pool[$i] = ['num', $this->take(8)]; ++$i; break;
                case 7: $this->pool[$i] = ['class', $this->u2()]; break;
                case 8: $this->pool[$i] = ['string', $this->u2()]; break;
                case 9: case 10: case 11: case 12: case 17: case 18: $this->pool[$i] = ['ref', $this->take(4)]; break;
                case 15: $this->pool[$i] = ['ref', $this->take(3)]; break;
                case 16: case 19: case 20: $this->pool[$i] = ['ref', $this->take(2)]; break;
                default: throw new ToolException("Unsupported constant type {$tag}; the class may be corrupt.");
            }
        }

        $flags = $this->u2();
        $name = $this->className($this->u2());
        $superIndex = $this->u2();
        $super = $superIndex ? $this->className($superIndex) : null;
        $interfaces = [];
        for ($n = $this->u2(); $n > 0; --$n) {
            $interfaces[] = $this->className($this->u2());
        }

        $fields = $this->members(false);
        $methods = $this->members(true);

        $kind = $flags & 0x2000 ? '@interface' : ($flags & 0x0200 ? 'interface' : ($flags & 0x4000 ? 'enum' : 'class'));
        $out = "// Java class file version {$major}.{$minor} (Java " . max(1, $major - 44) . ")\n"
            // Only public, final and abstract mean anything on a top-level class; obfuscators set other bits.
            . trim($this->modifiers($flags & ($kind === 'class' ? 0x0411 : 0x0001)) . " {$kind} {$name}")
            . ($super && $super !== 'Object' ? " extends {$super}" : '')
            . ($interfaces ? ' implements ' . implode(', ', $interfaces) : '') . " {\n";
        foreach ($fields as $f) {
            $out .= "  {$f};\n";
        }
        if ($fields && $methods) {
            $out .= "\n";
        }
        $simple = substr(strrchr('.' . $name, '.'), 1);
        foreach ($methods as $m) {
            $out .= '  ' . preg_replace('/(^|\s)<init>\(/', '$1' . $simple . '(', $m) . ";\n";
        }
        $out .= "}\n";

        $strings = [];
        foreach ($this->pool as $entry) {
            if ($entry[0] === 'string') {
                $text = $this->utf8($entry[1]);
                if ($text !== '' && count($strings) < 400) {
                    $strings[$text] = true;
                }
            }
        }
        if ($strings) {
            $out .= "\n// String constants used by this class:\n";
            foreach (array_keys($strings) as $s) {
                $out .= '//   ' . json_encode((string) $s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
            }
        }

        return $out;
    }

    /**
     * @return string[]
     */
    private function members(bool $methods): array
    {
        $out = [];
        for ($n = $this->u2(); $n > 0; --$n) {
            $flags = $this->u2();
            $name = $this->utf8($this->u2());
            $descriptor = $this->utf8($this->u2());
            for ($a = $this->u2(); $a > 0; --$a) {
                $this->u2();
                $this->take($this->u4());
            }
            if ($flags & 0x1000) {
                continue; // compiler-generated
            }

            if ($methods) {
                [$params, $return] = $this->methodTypes($descriptor);
                $modifiers = $this->modifiers($flags & ~0x0020 & ~0x0040 & ~0x0080 & ~0x0100 & ~0x0800);
                $out[] = match ($name) {
                    '<clinit>' => 'static {}',
                    // Constructors have no return type; the class's simple name is filled in later.
                    '<init>' => trim("{$modifiers} <init>(" . implode(', ', $params) . ')'),
                    default => trim("{$modifiers} {$return} {$name}(" . implode(', ', $params) . ')'),
                };
            } else {
                $offset = 0;
                $out[] = trim($this->modifiers($flags) . ' ' . $this->type($descriptor, $offset) . " {$name}");
            }
        }

        return $out;
    }

    private function methodTypes(string $descriptor): array
    {
        $params = [];
        $i = 1;
        while ($i < strlen($descriptor) && $descriptor[$i] !== ')') {
            $params[] = $this->type($descriptor, $i);
        }
        ++$i;

        return [$params, $this->type($descriptor, $i)];
    }

    private function type(string $d, int &$i): string
    {
        $dims = '';
        while (($d[$i] ?? '') === '[') {
            $dims .= '[]';
            ++$i;
        }
        $c = $d[$i] ?? 'V';
        ++$i;
        if ($c === 'L') {
            $end = strpos($d, ';', $i);
            $end = $end === false ? strlen($d) : $end;
            $name = str_replace('/', '.', substr($d, $i, $end - $i));
            $i = $end + 1;

            return preg_replace('/^java\.lang\.(?=[A-Z]\w*$)/', '', $name) . $dims;
        }

        return match ($c) {
            'B' => 'byte', 'C' => 'char', 'D' => 'double', 'F' => 'float', 'I' => 'int',
            'J' => 'long', 'S' => 'short', 'Z' => 'boolean', default => 'void',
        } . $dims;
    }

    private function modifiers(int $flags): string
    {
        return implode(' ', array_filter(self::ACCESS, fn ($bit) => ($flags & $bit) !== 0, ARRAY_FILTER_USE_KEY));
    }

    private function className(int $index): string
    {
        $entry = $this->pool[$index] ?? null;

        $name = $entry && $entry[0] === 'class' ? str_replace('/', '.', $this->utf8($entry[1])) : '?';

        return preg_replace('/^java\.lang\.(?=[A-Z]\w*$)/', '', $name);
    }

    private function utf8(int $index): string
    {
        $entry = $this->pool[$index] ?? null;

        return $entry && $entry[0] === 'utf8' ? $entry[1] : '';
    }

    private function take(int $n): string
    {
        if ($this->pos + $n > strlen($this->bytes)) {
            throw new ToolException('The class file ends early; it may be truncated or obfuscated.');
        }
        $s = substr($this->bytes, $this->pos, $n);
        $this->pos += $n;

        return $s;
    }

    private function u1(): int
    {
        return ord($this->take(1));
    }

    private function u2(): int
    {
        return unpack('n', $this->take(2))[1];
    }

    private function u4(): int
    {
        return unpack('N', $this->take(4))[1];
    }
}
