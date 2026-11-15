<?php

namespace Tests\Support;

/** Small adversarial tar fixtures; never extracts or writes outside the test workspace. */
final class TarFixture
{
    public static function write(string $path, array $members): void
    {
        $stream = gzopen($path, 'wb');
        foreach ($members as $member) {
            $bytes = $member['bytes'] ?? '';
            $header = str_pad($member['name'], 100, "\0").sprintf('%07o', 0600)."\0"
                .sprintf('%07o', 0)."\0".sprintf('%07o', 0)."\0"
                .sprintf('%011o', strlen($bytes))."\0".sprintf('%011o', 1)."\0"
                .str_repeat(' ', 8).($member['type'] ?? '0').str_pad($member['link'] ?? '', 100, "\0")
                ."ustar\0".'00'.str_repeat("\0", 32 + 32 + 8 + 8 + 155 + 12);
            $checksum = array_sum(unpack('C*', $header));
            $header = substr_replace($header, sprintf('%06o', $checksum)."\0 ", 148, 8);
            gzwrite($stream, $header.$bytes.str_repeat("\0", (512 - strlen($bytes) % 512) % 512));
        }
        gzwrite($stream, str_repeat("\0", 1024));
        gzclose($stream);
    }
}
