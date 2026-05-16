<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\LicenseVerifier;
use Symfony\Component\HttpFoundation\Response;

class CheckLicense
{
    public function handle(Request $request, Closure $next): Response
    {
        $verifier = new LicenseVerifier();
        $result = $verifier->verify();

        if (!$result['valid']) {
            return response()->view('errors.license', [
                'error' => $result['message'],
            ], 403);
        }

        return $next($request);
    }
}
