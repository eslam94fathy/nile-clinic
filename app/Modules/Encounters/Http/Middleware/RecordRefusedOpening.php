<?php

namespace App\Modules\Encounters\Http\Middleware;

use App\Modules\Encounters\Services\EncounterService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records a refused attempt to open a medical record or a patient's history (FR-005, research R7). It
 * reads the status of the finished response, so one place covers the policy, the Form Request, and the
 * not-found that deliberately matches an unknown id. The response goes out untouched, whatever happens
 * here, as feature 008 requires.
 */
class RecordRefusedOpening
{
    public function __construct(
        private readonly EncounterService $encounters,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (in_array($response->getStatusCode(), [Response::HTTP_FORBIDDEN, Response::HTTP_NOT_FOUND], true)) {
            try {
                $this->record($request);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $response;
    }

    private function record(Request $request): void
    {
        $encounter = $request->route('encounter');

        if ($encounter !== null) {
            $this->encounters->recordRefusedOpening((int) $encounter);

            return;
        }

        $this->encounters->recordRefusedHistory((int) $request->route('patient'));
    }
}
