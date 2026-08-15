<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Domain\Customers\Notes\CustomerNotes;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-17's write door (customers.notes.add; the read rides the panel section
 * on customers.notes.view). Notes are structurally undeliverable (N-04) —
 * this controller knows nothing of conversations, adapters, or sends.
 */
final class NotesController
{
    public function add(Request $request, string $customerId, CustomerNotes $notes): JsonResponse
    {
        Customer::query()->findOrFail($customerId); // C-10

        /** @var array{body: string, pinned?: bool} $input */
        $input = $request->validate([
            'body' => ['required', 'string', 'max:8000'],
            'pinned' => ['sometimes', 'boolean'],
        ]);

        /** @var User $author */
        $author = $request->user();

        $noteId = $notes->add($customerId, $input['body'], $author->identifier(), $input['pinned'] ?? false);

        return new JsonResponse(['id' => $noteId], 201);
    }
}
