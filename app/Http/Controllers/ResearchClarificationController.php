<?php

namespace App\Http\Controllers;

use App\Models\ResearchRun;
use App\Services\ResearchCoordinator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ResearchClarificationController extends Controller
{
    public function answer(Request $request, ResearchRun $run, string $clarification, ResearchCoordinator $research): JsonResponse|RedirectResponse
    {
        $research->authorize($request->user()->fresh(), $run->session);
        abort_unless($run->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'answers' => 'required|array|list|min:1|max:3', 'answers.*' => 'required|array:id,answer',
            'answers.*.id' => 'required|string|in:0,1,2|distinct:strict',
            'answers.*.answer' => ['bail', 'required', 'string', 'max:8192', fn ($attribute, $value, $fail) => trim($value) === '' ? $fail('Provide a nonblank answer.') : null],
        ]);
        if (array_diff(array_keys($request->all()), ['answers']) !== [] || strlen(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 65536) {
            throw ValidationException::withMessages(['answers' => 'Provide only answers within the transport limit.']);
        }
        try {
            $receipt = $research->answer($request->user(), $run, $clarification, $data['answers']);
        } catch (RequestException $error) {
            $status = $error->response->status();
            abort(in_array($status, [404, 409, 422], true) ? $status : 503, 'Clarification was not confirmed. Retry the same answers or refresh the run.');
        } catch (ConnectionException|\RuntimeException $error) {
            if ($error instanceof HttpExceptionInterface) {
                throw $error;
            }
            abort(503, 'Clarification was not confirmed. Retry the same answers or refresh the run.');
        }

        return $request->expectsJson() ? response()->json($receipt) : back()->with('status', 'Clarification response received.');
    }
}
