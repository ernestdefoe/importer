<?php

namespace ErnestDefoe\Importer\Auth;

/**
 * Checks a password against the hash an old board stored, in that board's
 * own way. Pure PHP, no Flarum: `php tests/passwords.php` runs it.
 *
 *   phpass      phpBB 3.0 `$H$`, Vanilla and WordPress `$P$`
 *   native      anything password_verify() knows that Flarum's bcrypt check
 *               does not: phpBB 3.3's argon2, Vanilla's bcrypt variants
 *   md5         phpBB 2 (and phpBB's `$CP$` converted hashes)
 *   vb          vBulletin 3/4 and vBulletin 5 "legacy": md5(md5(pw) . salt)
 *   vb5         vBulletin 5 blowfish/argon2id: the token hashes md5(pw), so
 *               ONLY md5(input) is tried — never the input itself, or the md5
 *               of somebody's password would sign in on its own
 *   mybb        md5(md5(salt) . md5(pw))
 *   smf         SMF 2.0 sha1(lowercase name . pw); SMF 2.1 bcrypt of the same
 *   ips         Invision: bcrypt (IPS 4) or md5(md5(salt) . md5(pw)) (IPB 3),
 *               with the password as IPS escaped it as well as raw
 *   xf1         XenForo 1: hashFunc(hashFunc(pw) . salt), sha256 or sha1
 *   discourse   PBKDF2-HMAC, the algorithm and rounds from its `$pbkdf2-…$`
 *   django      Vanilla's imported Django hashes: pbkdf2_sha256$iter$salt$hash
 *   nodebb      NodeBB's "shaWrapped": bcrypt of sha512(pw)
 */
final class LegacyPassword
{
    public static function check(string $scheme, string $hash, ?string $salt, #[\SensitiveParameter] string $password): bool
    {
        $salt = (string) $salt;
        if ($hash === '') {
            return false;
        }

        return match ($scheme) {
            'phpass' => self::phpassCheck($password, $hash),
            'native' => password_verify($password, $hash),
            'md5' => self::eq(md5($password), strtolower($hash)),
            'phpbb' => self::phpbb($password, $hash),
            'vb' => self::eq(md5(md5($password).$salt), strtolower($hash)),
            'vb5' => password_verify(md5($password), $hash),
            'mybb' => self::eq(md5(md5($salt).md5($password)), strtolower($hash)),
            'smf' => str_starts_with($hash, '$2')
                ? password_verify(mb_strtolower($salt).$password, $hash)
                : self::eq(sha1(mb_strtolower($salt).$password), strtolower($hash)),
            'ips' => self::ips($password, $hash, $salt),
            'xf1' => self::xf1($password, $hash, $salt),
            'discourse' => self::discourse($password, $hash, $salt),
            'django' => self::django($password, $hash),
            'nodebb' => password_verify(hash('sha512', $password), $hash),
            default => false,
        };
    }

    /**
     * Which scheme a self-describing hash (phpBB, Vanilla) is in: null when
     * it is bcrypt of the password itself, which Flarum checks already, and
     * 'unknown' when it cannot be checked at all.
     */
    public static function detect(string $hash): ?string
    {
        return match (true) {
            (bool) preg_match('#^\$2[aby]\$\d\d\$#', $hash) => null,
            str_starts_with($hash, '$H$'), str_starts_with($hash, '$P$') => 'phpass',
            str_starts_with($hash, '$CP$') => 'phpbb',
            str_starts_with($hash, '$argon2') => 'native',
            (bool) preg_match('/^[a-f0-9]{32}$/i', $hash) => 'md5',
            str_starts_with($hash, 'pbkdf2_sha256$') => 'django',
            default => 'unknown',
        };
    }

    private static function eq(string $a, string $b): bool
    {
        return $b !== '' && hash_equals($b, $a);
    }

    private static function phpbb(string $pw, string $hash): bool
    {
        // phpBB's converter prefix: the old board's hash follows.
        $inner = substr($hash, 4);

        return str_starts_with($inner, '$H$') ? self::phpassCheck($pw, $inner) : self::eq(md5($pw), strtolower($inner));
    }

    private static function ips(string $pw, string $hash, string $salt): bool
    {
        // IPS escaped some characters before hashing; try what it stored as well as what was typed.
        $forms = array_unique([$pw, strtr($pw, ['&' => '&amp;', '\\' => '&#092;', '!' => '&#33;', '$' => '&#036;', '"' => '&quot;', '<' => '&lt;', '>' => '&gt;', "'" => '&#39;'])]);
        foreach ($forms as $p) {
            if (str_starts_with($hash, '$2') ? password_verify($p, $hash) : self::eq(md5(md5($salt).md5($p)), strtolower($hash))) {
                return true;
            }
        }

        return false;
    }

    private static function xf1(string $pw, string $hash, string $salt): bool
    {
        $func = strlen($hash) === 40 ? 'sha1' : 'sha256';

        return self::eq(hash($func, hash($func, $pw).$salt), strtolower($hash));
    }

    /** `$pbkdf2-sha256$i=600000,l=32$` in salt's place carries the algorithm; old rows had none (64,000 rounds of sha256). */
    private static function discourse(string $pw, string $hash, string $salt): bool
    {
        [$salt, $algo] = array_pad(explode('|', $salt, 2), 2, '');
        $func = 'sha256';
        $rounds = 64000;
        $len = 32;
        if (preg_match('/^\$pbkdf2-(\w+)\$i=(\d+),l=(\d+)\$$/', $algo, $m)) {
            [$func, $rounds, $len] = [$m[1], (int) $m[2], (int) $m[3]];
        }
        if (! in_array($func, hash_hmac_algos(), true) || $rounds < 1 || $rounds > 5_000_000 || $len < 16 || $len > 64) {
            return false;
        }

        return self::eq(hash_pbkdf2($func, $pw, $salt, $rounds, $len * 2), strtolower($hash));
    }

    private static function django(string $pw, string $hash): bool
    {
        $parts = explode('$', $hash);
        if (count($parts) !== 4 || $parts[0] !== 'pbkdf2_sha256' || (int) $parts[1] < 1 || (int) $parts[1] > 5_000_000) {
            return false;
        }

        return self::eq(base64_encode(hash_pbkdf2('sha256', $pw, $parts[2], (int) $parts[1], 32, true)), $parts[3]);
    }

    /* ── phpass (portable hashes) ───────────────────────────────────────── */

    private const ITOA = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    private static function phpassCheck(string $pw, string $stored): bool
    {
        $id = substr($stored, 0, 3);
        if (($id !== '$P$' && $id !== '$H$') || strlen($stored) !== 34) {
            return false;
        }
        $log2 = strpos(self::ITOA, $stored[3]);
        if ($log2 === false || $log2 < 7 || $log2 > 30) {
            return false;
        }
        $salt = substr($stored, 4, 8);
        $hash = md5($salt.$pw, true);
        $count = 1 << $log2;
        do {
            $hash = md5($hash.$pw, true);
        } while (--$count);

        return hash_equals($stored, substr($stored, 0, 12).self::encode64($hash, 16));
    }

    private static function encode64(string $input, int $count): string
    {
        $out = '';
        $i = 0;
        do {
            $value = ord($input[$i++]);
            $out .= self::ITOA[$value & 0x3F];
            if ($i < $count) {
                $value |= ord($input[$i]) << 8;
            }
            $out .= self::ITOA[($value >> 6) & 0x3F];
            if ($i++ >= $count) {
                break;
            }
            if ($i < $count) {
                $value |= ord($input[$i]) << 16;
            }
            $out .= self::ITOA[($value >> 12) & 0x3F];
            if ($i++ >= $count) {
                break;
            }
            $out .= self::ITOA[($value >> 18) & 0x3F];
        } while ($i < $count);

        return $out;
    }
}
