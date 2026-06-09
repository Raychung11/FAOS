<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\Auth;
use App\Core\Audit;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class AuthController extends Controller
{
    public function showLogin(Request $req): void
    {
        if (Auth::check()) {
            Response::redirect(base_url('/'));
        }
        $this->view('auth.login', [
            'title' => 'Sign in',
            'error' => Session::flash('login_error'),
        ], 'blank');
    }

    public function login(Request $req): void
    {
        $data = $this->validate($req, [
            'username' => 'required|string|max:64',
            'password' => 'required|string',
        ]);
        $isPin = (bool) $req->input('pin_mode', false);
        $user = Auth::attempt($data['username'], $data['password'], $isPin);

        if (!$user) {
            Audit::log('login_failed', 'users', $data['username']);
            Session::flash('login_error', 'Invalid credentials or account locked.');
            Response::redirect(base_url('/login'));
        }

        Auth::login($user);
        Audit::log('login', 'users', (string) $user['id']);
        Response::redirect(base_url('/'));
    }

    public function logout(Request $req): void
    {
        Audit::log('logout', 'users', (string) (Auth::id() ?? ''));
        Auth::logout();
        Response::redirect(base_url('/login'));
    }
}
