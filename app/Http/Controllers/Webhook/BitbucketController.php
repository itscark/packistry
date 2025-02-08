<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Webhook\Traits\AuthorizeHubSignatureEvent;
use App\Sources\Bitbucket\Event\PushEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BitbucketController extends WebhookController
{
    use AuthorizeHubSignatureEvent;

    public function __invoke(Request $request): JsonResponse
    {
        $this->authorizeWebhook($request);

        return match ($request->header('X-Event-Key')) {
            'repo:push' => $this->pushOrDelete(PushEvent::from($request)),
            default => response()->json([
                'event' => ['unknown event type'],
            ], 422)
        };
    }

    private function pushOrDelete(PushEvent $event): JsonResponse
    {
        if ($event->isDelete()) {
            return $this->delete($event);
        }

        return $this->push($event);
    }
}
