<?php

declare(strict_types=1);

namespace App\Kernel\Http;

use App\Kernel\Approval\MakerCheckerViolation;
use App\Kernel\StateMachine\IllegalTransition;
use Closure;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;

/**
 * Turns business-rule refusals (posting rejected, illegal transition, maker-checker, not an approver)
 * into a readable message on the same page instead of an error screen (NFR-09). Route/policy
 * authorization still produces a real 403 because it runs before this.
 */
trait HandlesDomainErrors
{
    /** @param  Closure(): mixed  $action  may return a redirect; anything else means "back" */
    protected function attempt(Closure $action, string $success): RedirectResponse
    {
        try {
            $result = $action();

            return ($result instanceof RedirectResponse ? $result : back())->with('success', $success);
        } catch (MakerCheckerViolation $e) {
            return back()->with('error', $e->getMessage())->withErrors(['general' => $e->getMessage()]);
        } catch (DomainException|IllegalTransition|AuthorizationException $e) {
            return back()->with('error', $e->getMessage())->withErrors(['general' => $e->getMessage()])->withInput();
        }
    }
}
