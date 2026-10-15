<?php

namespace App\Http\Controllers;

use App\Services\QrLogins;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The page a customer's phone opens from the PC's QR code: confirm signing in on that PC. */
class QrLoginController extends Controller
{
    public function __construct(private QrLogins $logins) {}

    public function show(string $code): View
    {
        return view('pc-login.show', ['login' => $this->logins->findOpen($code), 'code' => $code]);
    }

    public function approve(Request $request, string $code): RedirectResponse
    {
        $login = $this->logins->findOpen($code);
        if ($login === null) {
            return redirect()->route('pc-login.show', $code);
        }

        $this->logins->approve($login, $request->user());

        return redirect()->route('pc-login.done');
    }

    public function done(): View
    {
        return view('pc-login.done');
    }
}
