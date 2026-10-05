<?php

/*
 * Which addresses from the old board's data may the importer download?
 *
 * Avatars and tag covers come from columns the old board's MEMBERS filled in,
 * so each one is checked before this server requests it. No Flarum, no
 * PHPUnit, no network for the literal-address rows:
 *
 *     php tests/safe_fetch.php
 */

declare(strict_types=1);

require __DIR__ . '/../src/Importers/SafeFetch.php';

use ErnestDefoe\Importer\Importers\SafeFetch;

/** [url, trusted old-board host, may it be fetched?] */
$cases = [
    ['http://127.0.0.1/a.png', '', false],
    ['http://127.0.0.1:6379/', '', false],
    ['http://0177.0.0.1/a.png', '', false],      // octal: curl dials 127.0.0.1
    ['http://012.0.0.1/a.png', '', false],       // octal: 10.0.0.1
    ['http://2130706433/a.png', '', false],      // one integer: 127.0.0.1
    ['http://0x7f.0.0.1/a.png', '', false],
    ['http://127.1/a.png', '', false],
    ['http://10.1.2.3/a.png', '', false],
    ['http://172.16.0.1/a.png', '', false],
    ['http://192.168.1.1/a.png', '', false],
    ['http://169.254.169.254/latest/meta-data/', '', false],
    ['http://100.64.0.1/a.png', '', false],
    ['http://0.0.0.0/a.png', '', false],
    ['http://[::1]/a.png', '', false],
    ['http://[::ffff:127.0.0.1]/a.png', '', false],
    ['http://[fd00::1]/a.png', '', false],
    ['http://[fe80::1]/a.png', '', false],
    ['file:///etc/passwd', '', false],
    ['ftp://8.8.8.8/a.png', '', false],
    ['gopher://8.8.8.8/', '', false],
    ['http://user:pass@8.8.8.8/a.png', '', false],
    ['http://8.8.8.8/a.png', '', true],
    ['https://1.1.1.1/a.png', '', true],
    ['http://[2606:4700:4700::1111]/a.png', '', true],
    // The operator's --assets-base host may be private during a migration...
    ['http://10.0.0.5/uploads/a.png', '10.0.0.5', true],
    ['http://old.lan/uploads/a.png', 'old.lan', true],
    // ...but only that host.
    ['http://10.0.0.6/uploads/a.png', '10.0.0.5', false],
];

$failed = 0;
foreach ($cases as [$url, $trusted, $expected]) {
    $actual = SafeFetch::vet($url, $trusted) !== null;
    if ($actual !== $expected) {
        $failed++;
        echo "FAIL  {$url}" . ($trusted !== '' ? " (trusting {$trusted})" : '') . ': expected '
            . ($expected ? 'allowed' : 'refused') . ', got ' . ($actual ? 'allowed' : 'refused') . "\n";
    }
}

echo $failed === 0 ? 'ok — ' . count($cases) . " addresses\n" : "{$failed} failed\n";
exit($failed === 0 ? 0 : 1);
