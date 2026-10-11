<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CaptureCollectionBatch;
use App\Actions\ReviewCollectionBatch;
use App\Http\Requests\ReviewCollectionBatchRequest;
use App\Http\Requests\StoreCollectionBatchRequest;
use App\Models\CollectionBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CollectionBatchController extends Controller
{
    public function store(StoreCollectionBatchRequest $request, CaptureCollectionBatch $capture): JsonResponse
    {
        $batch = $capture->handle($request->user(), $request->validated());

        return response()->json(['data' => $batch->evidence()]);
    }

    public function show(Request $request, CollectionBatch $collectionBatch): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $collectionBatch);

        return response()->json(['data' => $collectionBatch->evidence()]);
    }

    public function review(ReviewCollectionBatchRequest $request, CollectionBatch $collectionBatch, ReviewCollectionBatch $review): JsonResponse
    {
        $data = $request->validated();
        $result = $review->handle($request->user(), $collectionBatch, $data['expected_digest'], $data['decision'], $data['verification_reference'], $data['reason']);

        return response()->json(['data' => ['id' => $result->id, 'content' => $result->content(), 'review_digest' => $result->review_digest,
            'arc_funding_verified' => false, 'can_execute' => false]]);
    }

    public function document(Request $request, CollectionBatch $collectionBatch): StreamedResponse
    {
        Gate::forUser($request->user())->authorize('view', $collectionBatch);
        abort_unless($collectionBatch->hasValidSnapshot(), 409, 'Collection evidence changed; download blocked.');
        $document = $collectionBatch->snapshot['source_document'] ?? null;
        abort_unless(is_array($document), 404, 'No uploaded document retained for this collection.');
        $disk = Storage::disk('local');
        abort_unless($disk->exists($document['path']), 404, 'Receipt file missing; ask finance staff to investigate.');
        $contents = $disk->get($document['path']);
        abort_unless(strlen($contents) === $document['size_bytes'] && hash_equals($collectionBatch->source_document_digest, hash('sha256', $contents)),
            409, 'Receipt file changed; download blocked.');

        return response()->streamDownload(static function () use ($contents): void {
            echo $contents;
        }, $document['name'], ['Content-Type' => $document['mime_type'],
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
