<?php

/*
 * Old boards' password hashes, checked the old boards' way. No Flarum:
 *
 *     php tests/passwords.php
 *
 * The fixed vectors were made independently of this code: phpass from
 * Openwall's own test vector, the rest with Python's hashlib following each
 * board's documented formula. bcrypt and argon2 are made here with PHP's
 * password_hash(), which is what the boards use.
 */

declare(strict_types=1);

require __DIR__.'/../src/Auth/LegacyPassword.php';

use ErnestDefoe\Importer\Auth\LegacyPassword as P;

$fail = 0;
$check = function (string $name, bool $ok) use (&$fail) {
    echo ($ok ? '  ok  ' : 'FAIL  ').$name."\n";
    $fail += $ok ? 0 : 1;
};
$pw = 'Corr3ct horse!';
$wrong = 'Corr3ct horse?';
$both = function (string $name, string $scheme, string $hash, ?string $salt = null, ?string $password = null) use ($check, $pw, $wrong) {
    $check("$name: right password", P::check($scheme, $hash, $salt, $password ?? $pw));
    $check("$name: wrong password", ! P::check($scheme, $hash, $salt, $wrong));
};

echo "Fixed vectors\n";
$check('phpass (Openwall test vector)', P::check('phpass', '$P$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0', null, 'test12345'));
$check('phpass: one character off', ! P::check('phpass', '$P$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0', null, 'test12346'));
$check('phpBB 3.0 $H$ is phpass too', P::check('phpass', '$H$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0', null, 'test12345'));
$both('vBulletin 3/4 md5(md5(pw).salt)', 'vb', '65a48b5118569568ffb3f137d235de32', 'x9#');
$both('MyBB', 'mybb', '324e930864160016dce3c3631e5468b6', 'Ab3dEf7h');
$both('SMF 2.0 (name is lowercased)', 'smf', 'f34c0ca43717dc272e98a8aa8771cde6b4a4223a', 'Ada Lovelace');
$both('IPB 3, password as IPS escaped it', 'ips', 'dbe47e4d4753b341878e46c408dd414f', 'a1b2c');
$both('IPB 3, password raw', 'ips', '6b34b81f62ce7021aa73dbe590429770', 'a1b2c');
$both('XenForo 1 sha256', 'xf1', '14687da78c5b532c6acc227b23ca277ca99d236d396d4be267514f2eb11e4374', 'saltsalt');
$both('XenForo 1 sha1', 'xf1', 'ac4da6d09d772e3504795f93928069562aebc42b', 'saltsalt');
$both('Discourse, old rows (64,000 rounds)', 'discourse', '6a08f81764dd02144799cc175b60358b0d15497526d31e6df1a701649d2114a1', '0123456789abcdef0123456789abcdef|');
$both('Discourse, $pbkdf2-sha256$i=…', 'discourse', '4591814092b733434d135fb21bfe3d9e6560ec149f05cfd76afdb53bb77c41df', 'f00dbabe|$pbkdf2-sha256$i=1000,l=32$');
$both('Django (Vanilla imports)', 'django', 'pbkdf2_sha256$1000$NaCl$PtwnCzhaEFpyUjSNswsktzpt+oo64ObFONMOTQS9Cyc=');
$both('phpBB 2 md5', 'md5', 'f01da93dd0ef45d4498d94a2d843efda');
$both('phpBB $CP$ converted md5', 'phpbb', '$CP$f01da93dd0ef45d4498d94a2d843efda');

echo "bcrypt and argon2\n";
$both('vBulletin 5 blowfish = bcrypt(md5(pw))', 'vb5', password_hash(md5($pw), PASSWORD_BCRYPT));
if (defined('PASSWORD_ARGON2ID')) {
    $both('vBulletin 5 argon2id = argon2id(md5(pw))', 'vb5', password_hash(md5($pw), PASSWORD_ARGON2ID));
    $both('phpBB 3.3 argon2id', 'native', password_hash($pw, PASSWORD_ARGON2ID));
}
$both('SMF 2.1 bcrypt(lowercase name . pw)', 'smf', password_hash('ada lovelace'.$pw, PASSWORD_BCRYPT), 'Ada Lovelace');
$both('IPS 4 bcrypt', 'ips', password_hash($pw, PASSWORD_BCRYPT), 'unused');
$both('NodeBB shaWrapped bcrypt(sha512(pw))', 'nodebb', password_hash(hash('sha512', $pw), PASSWORD_BCRYPT));

echo "An old hash on its own never signs anybody in\n";
$token = password_hash(md5($pw), PASSWORD_BCRYPT);
$check('vBulletin 5: the md5 of the password is not the password', ! P::check('vb5', $token, null, md5($pw)));
$check('vBulletin 3/4: the stored hash is not the password', ! P::check('vb', '65a48b5118569568ffb3f137d235de32', 'x9#', '65a48b5118569568ffb3f137d235de32'));
$check('md5: the stored hash is not the password', ! P::check('md5', 'f01da93dd0ef45d4498d94a2d843efda', null, 'f01da93dd0ef45d4498d94a2d843efda'));
$check('NodeBB: the sha512 is not the password', ! P::check('nodebb', password_hash(hash('sha512', $pw), PASSWORD_BCRYPT), null, hash('sha512', $pw)));
$check('an empty hash matches nothing', ! P::check('vb', '', 'x', ''));
$check('an unknown scheme matches nothing', ! P::check('who-knows', 'abc', null, 'abc'));
$check('Discourse refuses absurd rounds', ! P::check('discourse', str_repeat('a', 64), 'x|$pbkdf2-sha256$i=99999999,l=32$', 'x'));

echo "Detecting a self-describing hash\n";
$check('bcrypt is Flarum\'s own: copied, not legacy', P::detect('$2y$10$'.str_repeat('a', 53)) === null);
$check('$H$ → phpass', P::detect('$H$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0') === 'phpass');
$check('$P$ → phpass', P::detect('$P$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0') === 'phpass');
$check('$argon2id$ → native', P::detect('$argon2id$v=19$m=65536,t=4,p=1$abc$def') === 'native');
$check('32 hex → md5', P::detect('f01da93dd0ef45d4498d94a2d843efda') === 'md5');
$check('$CP$ → phpbb', P::detect('$CP$f01da93dd0ef45d4498d94a2d843efda') === 'phpbb');
$check('anything else → unknown (member resets)', P::detect('sha1$abc$def') === 'unknown');

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
