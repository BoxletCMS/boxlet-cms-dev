<?php

namespace App\Modules\Auth;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Support\Url;
use RuntimeException;

final class AuthController
{
    // A real bcrypt hash of a random string. Verifying against it when the email is
    // unknown takes as long as a real check, so timing does not reveal accounts.
    private const DUMMY_HASH = '$2y$10$Y3W.HoWn5OHOFjxrPf82I.OHOkckff7XWzw8kEMStpMZ2DHXxyRW2';

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array<string, string> $params
     */
    public function showLogin(Request $request, string $locale, array $params): Response
    {
        if ($this->session()->get('admin_id') !== null) {
            return Response::redirect(Url::admin());
        }

        return $this->form('', null, 200);
    }

    /**
     * The failure message is identical for a wrong password, an unknown email and a
     * locked account, so the form never reveals whether an account exists.
     *
     * @param array<string, string> $params
     */
    public function login(Request $request, string $locale, array $params): Response
    {
        $email = mb_strtolower(trim($request->input('email')));
        $key = (string) $this->container->get('config')->get('app.key');
        if ($key === '') {
            throw new RuntimeException('APP_KEY is not set. The installer writes it to .env.');
        }
        $ipHash = hash_hmac('sha256', $request->ip, $key);
        $emailHash = hash_hmac('sha256', $email, $key);

        $db = $this->container->get('db');
        $throttle = new LoginThrottle($db);
        $now = time();
        if ($throttle->isLocked($ipHash, $emailHash, $now)) {
            $minutes = intdiv(LoginThrottle::WINDOW_SECONDS, 60);

            return $this->form($email, t('auth.throttled', ['minutes' => $minutes]), 429);
        }

        $admin = $db->one('SELECT id, password_hash FROM admin WHERE email = ?', [$email]);
        $hash = $admin === null ? self::DUMMY_HASH : (string) $admin['password_hash'];
        $password = $request->input('password');
        // Always verify, even for an unknown email, so both cases take the same time.
        $passwordMatches = password_verify($password, $hash);
        $valid = $admin !== null && $passwordMatches;
        $throttle->record($ipHash, $emailHash, $valid, $now);

        if ($admin === null || !$passwordMatches) {
            return $this->form($email, t('auth.failed'), 422);
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $db->query('UPDATE admin SET password_hash = ? WHERE id = ?', [
                password_hash($password, PASSWORD_DEFAULT),
                $admin['id'],
            ]);
        }

        $session = $this->session();
        $session->regenerate();
        $session->set('admin_id', (int) $admin['id']);

        return Response::redirect(Url::admin());
    }

    /**
     * @param array<string, string> $params
     */
    public function logout(Request $request, string $locale, array $params): Response
    {
        $this->session()->destroy();

        return Response::redirect(Url::admin('login'));
    }

    private function form(string $email, ?string $error, int $status): Response
    {
        $html = (new View(__DIR__ . '/views'))->render('login', 'en', [
            'title' => t('auth.title'),
            'email' => $email,
            'error' => $error,
            'csrf' => $this->session()->csrfToken(),
        ]);

        return Response::admin($html, $status);
    }

    private function session(): Session
    {
        return $this->container->get('session');
    }
}
