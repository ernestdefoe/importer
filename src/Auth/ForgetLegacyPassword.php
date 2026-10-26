<?php

namespace ErnestDefoe\Importer\Auth;

use Flarum\User\Event\PasswordChanged;
use Illuminate\Database\ConnectionInterface;

/**
 * A member who sets a new password (a reset e-mail, the settings page) must
 * not still be able to sign in with the old board's one.
 */
class ForgetLegacyPassword
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function handle(PasswordChanged $event): void
    {
        try {
            $this->db->table('importer_passwords')->where('user_id', $event->user->id)->delete();
        } catch (\Throwable) {
        }
    }
}
