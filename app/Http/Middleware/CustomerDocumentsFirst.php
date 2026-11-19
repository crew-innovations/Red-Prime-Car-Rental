<?php

namespace App\Http\Middleware;

use App\Filament\Account\Pages\Documents;
use App\Support\CustomerArea;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 19 Nov: a customer whose required documents are missing or not approved yet is sent to
 * "My documents" first. Their profile and logging out keep working.
 */
class CustomerDocumentsFirst
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->isCustomerAccount() && $request->isMethod('GET')
            && ! $request->routeIs('filament.account.pages.documents', 'filament.account.auth.*')
            && ! CustomerArea::unlocked()) {
            return redirect()->to(Documents::getUrl(panel: 'account'));
        }

        return $next($request);
    }
}
