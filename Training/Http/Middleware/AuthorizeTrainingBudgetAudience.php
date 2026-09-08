<?php

namespace App\Domains\People\Training\Http\Middleware;

use App\Core\User\Models\User;
use App\Domains\People\Training\Services\TrainingBudgetStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizeTrainingBudgetAudience
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User
            || ! app(TrainingBudgetStore::class)->mayView($user, (int) $user->company_id)) {
            abort(403);
        }

        return $next($request);
    }
}
