<?php

namespace ErnestDefoe\Importer\Auth;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Signs in a member with the password they had on the old board.
 *
 * Registered with `Extend\Auth::addPasswordChecker`. Flarum's own bcrypt check
 * runs as well and fails (an imported member's `users.password` is random
 * until now); this one looks for the member's old hash, checks it the old
 * board's way, and on success saves the password as Flarum saves any password
 * — so it is bcrypt from here on and the old hash is deleted.
 *
 * Returns null, never false, when it does not apply: another checker (an SSO
 * extension, say) may still accept the password.
 *
 * Credit: Rob2dev's work on vBulletin 5 hashes (ernestdefoe/importer#4).
 */
class LegacyPasswordChecker
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function __invoke(User $user, #[\SensitiveParameter] string $password): ?bool
    {
        if (! $user->exists || $password === '') {
            return null;
        }
        try {
            $row = $this->db->table('importer_passwords')->where('user_id', $user->id)->first();
        } catch (\Throwable) {
            return null; // not migrated yet
        }
        if (! $row || ! LegacyPassword::check((string) $row->scheme, (string) $row->hash, $row->salt, $password)) {
            return null;
        }

        // From now on Flarum checks it itself.
        $user->changePassword($password);
        $user->save();
        $this->db->table('importer_passwords')->where('user_id', $user->id)->delete();

        return true;
    }
}
