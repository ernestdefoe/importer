<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use ErnestDefoe\Importer\Importers\Dst;
use ErnestDefoe\Importer\Importers\Src;
use ErnestDefoe\Importer\Importers\Vbulletin5Importer;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\Event\PasswordChanged;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * Signing in with the password from the old board, through Flarum's own
 * login, and what happens to the old hash afterwards.
 *
 * vBulletin 5 is the case from ernestdefoe/importer#4 (thanks, Rob2dev): its
 * token is bcrypt of md5(password), so the md5 itself must never work.
 */
class LegacyPasswordLoginTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const PW = 'Corr3ct horse!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('ernestdefoe-importer');
        $random = password_hash('not-the-password', PASSWORD_BCRYPT);
        $this->prepareDatabase([
            'users' => [
                ['id' => 3, 'username' => 'ada', 'email' => 'ada@forum.example', 'is_email_confirmed' => 1, 'password' => $random],
                ['id' => 4, 'username' => 'bob', 'email' => 'bob@forum.example', 'is_email_confirmed' => 1, 'password' => $random],
            ],
            'importer_passwords' => [
                ['user_id' => 3, 'scheme' => 'vb5', 'hash' => password_hash(md5(self::PW), PASSWORD_BCRYPT), 'salt' => null],
                ['user_id' => 4, 'scheme' => 'phpass', 'hash' => '$P$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0', 'salt' => null],
            ],
        ]);
    }

    private function login(string $who, string $password): int
    {
        return $this->send($this->request('POST', '/api/token', ['json' => ['identification' => $who, 'password' => $password]]))->getStatusCode();
    }

    #[Test]
    public function the_old_password_signs_in_once_and_is_then_flarums()
    {
        $this->assertSame(401, $this->login('ada', 'wrong'), 'A wrong password is refused');
        $this->assertSame(401, $this->login('ada', md5(self::PW)), 'The md5 of the password is not the password');
        $this->assertSame(1, $this->database()->table('importer_passwords')->where('user_id', 3)->count(), 'A failed sign-in keeps the old hash');

        $this->assertSame(200, $this->login('ada', self::PW));
        $this->assertSame(0, $this->database()->table('importer_passwords')->where('user_id', 3)->count(), 'The old hash is gone');
        $this->assertTrue(password_verify(self::PW, (string) $this->database()->table('users')->where('id', 3)->value('password')), 'The password is now bcrypt, Flarum\'s own');
        $this->assertSame(200, $this->login('ada', self::PW), 'And it keeps working, with no old hash left');

        $this->assertSame(200, $this->login('bob@forum.example', 'test12345'), 'phpBB 3.0 / WordPress phpass');
    }

    #[Test]
    public function a_new_password_set_on_the_forum_retires_the_old_one()
    {
        $this->app();
        $user = User::find(3);
        $this->app()->getContainer()->make(Dispatcher::class)->dispatch(new PasswordChanged($user));

        $this->assertSame(0, $this->database()->table('importer_passwords')->where('user_id', 3)->count());
        $this->assertSame(401, $this->login('ada', self::PW));
    }

    #[Test]
    public function an_import_keeps_old_hashes_apart_and_copies_bcrypt()
    {
        $this->app();
        Dst::reset();
        $at = Carbon::now();
        $vb = Dst::user('cy', 'cy@board.example', null, $at, null, Vbulletin5Importer::legacy((object) ['scheme' => 'blowfish:10', 'token' => password_hash(md5(self::PW), PASSWORD_BCRYPT)]));
        $bcrypt = password_hash(self::PW, PASSWORD_BCRYPT);
        $phpbb = Dst::user('dee', 'dee@board.example', $bcrypt, $at, null, Src::legacy($bcrypt));
        $phpass = Dst::user('eve', 'eve@board.example', '$H$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0', $at, null, Src::legacy('$H$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0'));
        $existing = Dst::user('ada2', 'ada@forum.example', null, $at, null, ['scheme' => 'md5', 'hash' => md5('x')]);
        $db = $this->database();

        $this->assertSame('vb5', $db->table('importer_passwords')->where('user_id', $vb)->value('scheme'));
        $this->assertFalse(password_verify(md5(self::PW), (string) $db->table('users')->where('id', $vb)->value('password')), 'users.password never holds the old hash');
        $this->assertSame($bcrypt, $db->table('users')->where('id', $phpbb)->value('password'), 'bcrypt of the password itself is copied as it is');
        $this->assertSame(0, $db->table('importer_passwords')->where('user_id', $phpbb)->count());
        $this->assertSame('phpass', $db->table('importer_passwords')->where('user_id', $phpass)->value('scheme'));
        $this->assertSame(3, $existing, 'An account matched by e-mail is the existing one…');
        $this->assertSame(0, $db->table('importer_passwords')->where('user_id', 3)->where('scheme', 'md5')->count(), '…and keeps its own password');

        $this->assertSame(200, $this->login('cy', self::PW));
        $this->assertSame(200, $this->login('dee', self::PW));
        $this->assertSame(200, $this->login('eve', 'test12345'));
    }
}
