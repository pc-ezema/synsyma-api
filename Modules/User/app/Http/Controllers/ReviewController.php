<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\User\Models\Booking;
use Modules\User\Models\Review;

/** The customer rating a finished job. PUT bookings/{id}/review creates it, or changes it within a week. */
class ReviewController extends Controller
{
    public function save(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], [
            'rating.required' => 'Choose a rating from 1 to 5 stars.',
            'rating.between' => 'Choose a rating from 1 to 5 stars.',
            'comment.max' => 'Your comment can be up to 1000 characters.',
        ]);
        $comment = trim((string) ($data['comment'] ?? '')) ?: null;

        DB::transaction(function () use ($request, $id, $data, $comment) {
            $b = Booking::lockForUpdate()->where('user_id', $request->user()->id)->findOrFail($id);

            if ($b->status !== 'completed' || ! $b->contractor_id) {
                throw ValidationException::withMessages(['rating' => ['You can rate a job once it is completed.']]);
            }

            $review = Review::where('booking_id', $b->id)->lockForUpdate()->first();

            if ($review) {
                if (! $review->canEdit()) {
                    throw ValidationException::withMessages(['rating' => ['Reviews can be changed for ' . Review::EDIT_DAYS . ' days after you send them.']]);
                }
                $review->forceFill(['rating' => $data['rating'], 'comment' => $comment, 'edited_at' => now()])->save();
                $b->log('review_edited', 'customer', null, null, 'Changed the review to ' . $data['rating'] . ' stars.');

                return;
            }

            Review::create(['booking_id' => $b->id, 'user_id' => $b->user_id, 'contractor_id' => $b->contractor_id, 'rating' => $data['rating'], 'comment' => $comment]);
            $b->log('review_added', 'customer', null, null, 'Rated the job ' . $data['rating'] . ' stars.');
        });

        $b = Booking::where('user_id', $request->user()->id)->with(['service', 'contractor', 'events', 'payments', 'review'])->findOrFail($id);

        return response()->json(['message' => 'Thank you for your feedback.', 'data' => $b->toArrayFlat(true)]);
    }
}