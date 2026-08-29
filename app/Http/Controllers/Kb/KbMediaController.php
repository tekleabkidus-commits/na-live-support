<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kb;

use App\Domain\Kb\Media\ArticleMedia;
use App\Domain\Kb\Media\MediaRefused;
use App\Http\Controllers\Controller;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Doc 10 FR-05 / SEC-02 (S10 step 11) — the STAFF upload door: scan → store → a signed
 * editing URL back (10-min TTL; the customer-facing long-lived path exists only through
 * the widget media door's public+published check). An infected file is refused with the
 * scan verdict and NOTHING was stored (SEC-02's order is physics in ArticleMedia).
 */
final class KbMediaController extends Controller
{
    public function __construct(private readonly ArticleMedia $media, private readonly \App\Support\Audit\AuditLogger $audit) {}

    public function store(Request $request, string $variantId): JsonResponse
    {
        $articleId = DB::table('kb_article_variants')->where('id', $variantId)->value('article_id');
        if (! is_string($articleId)) {
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found');
        }
        $file = $request->file('image');
        if (! $file instanceof \Illuminate\Http\UploadedFile || ! $file->isValid()) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'an image file is required (field: image)');
        }
        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'unreadable upload');
        }
        try {
            $stored = $this->media->upload($articleId, $file->getClientOriginalExtension(), $stream);
        } catch (MediaRefused $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        /** @var \App\Models\User $user */
        $user = $request->user();
        $this->audit->record('kb.article.media_uploaded', 'kb_article_variant', $variantId, \App\Support\Audit\AuditActor::Staff, ['file' => $stored['file']], actorId: $user->identifier());

        return new JsonResponse($stored + ['alt_required' => true], 201); // AC-10: the block will not publish publicly without alt text
    }
}
