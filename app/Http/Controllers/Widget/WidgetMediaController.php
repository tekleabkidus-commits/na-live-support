<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Domain\Auth\Widget\WidgetVisitor;
use App\Domain\Webchat\Media\WidgetIntake;
use App\Domain\Webchat\Visitor\VisitorConversation;
use App\Http\Controllers\Controller;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Doc 13 FR-16 / T-03 amendment — `POST media` (the intake) and `GET media/{message}/{index}`
 * (the same-origin read of a CLEAN item). Both visitor-authenticated; the cap is
 * refused BEFORE any byte is stored (EC-08); a foreign or unscanned item is a 404.
 */
final class WidgetMediaController extends Controller
{
    public function __construct(
        private readonly WidgetIntake $intake,
        private readonly VisitorConversation $conversations,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $visitor = $this->visitor($request);
        $widget = $this->conversations->widgetFor($request);
        $file = $request->file('file');
        if (! $file instanceof \Illuminate\Http\UploadedFile || ! $file->isValid()) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'widget.form.invalid_file');
        }
        $cap = $this->intake->capBytes();
        if ((int) $file->getSize() > $cap) {
            // EC-08: the friendly limit, stated in MB, before a byte moves
            return ProblemDetails::response(ErrorCode::ValidationFailed, 413, 'widget.upload.too_large', ['max_mb' => [(string) intdiv($cap, 1024 * 1024)]]);
        }
        try {
            return new JsonResponse($this->intake->store($widget, $visitor, $file), 201);
        } catch (InvalidArgumentException $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, $e->getMessage() === 'too_large' ? 413 : 422, 'widget.upload.'.$e->getMessage());
        }
    }

    public function show(Request $request, string $messageId, int $index): Response
    {
        $visitor = $this->visitor($request);
        $widget = $this->conversations->widgetFor($request);
        $opened = $this->intake->open($widget, $visitor, $messageId, $index);
        if ($opened === null) {
            throw new NotFoundHttpException;
        }
        $stream = $opened['stream'];

        return new StreamedResponse(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, array_filter([
            'Content-Type' => $opened['mime'],
            'Content-Length' => $opened['bytes'] !== null ? (string) $opened['bytes'] : null,
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }

    private function visitor(Request $request): WidgetVisitor
    {
        $principal = $request->user('widget');
        if (! $principal instanceof WidgetVisitor) {
            throw new NotFoundHttpException;
        }

        return $principal;
    }
}
