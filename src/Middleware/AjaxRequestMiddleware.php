<?php

namespace Buki\AutoRoute\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;

class AjaxRequestMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if (!($request->ajax() || $request->pjax())) {
            throw new MethodNotAllowedException(
                ['XMLHttpRequest'],
                "You cannot use this route without XMLHttpRequest."
            );
        }

        return $next($request);
    }
}
