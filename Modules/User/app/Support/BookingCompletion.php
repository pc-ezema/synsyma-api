<?php

namespace Modules\User\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\User\Models\Booking;
use Modules\Admin\Models\BookingPolicy;
use Modules\Admin\Support\AdminNotifier;
use Modules\Admin\Support\Ledger;

/**
 * Completing a job and reporting a problem with it, shared by the customer, the admin and the scheduler.
 * Completing uses the Admin Booking model on purpose: its hooks create the contractor's earning and the timeline notifications.
 */
class BookingCompletion
{
    /** Statuses in which a customer may report a problem. */
    public const REPORTABLE = ['assigned', 'en_route', 'in_progress'];

    /** What the customer's booking screen needs to know. */
    public static function state(Booking $b): array
    {
        return [
            'can_complete' => $b->status === 'in_progress',
            'can_report' => in_array($b->status, self::REPORTABLE, true) && ! $b->problem_reported_at,
            'problem_reported' => (bool) $b->problem_reported_at && in_array($b->status, self::REPORTABLE, true),
            'auto_complete_at' => $b->status === 'in_progress' && ! $b->problem_reported_at ? self::autoCompleteAt($b)?->toIso8601String() : null,
        ];
    }

    /** When the job would complete by itself, or null when auto-complete is off. */
    public static function autoCompleteAt(Booking $b)
    {
        $hours = (int) BookingPolicy::current()->auto_complete_hours;
        if ($hours <= 0) {
            return null;
        }
        $length = max(1, (int) ($b->included_hours ?? 1)) + (int) $b->extra_hours;

        return $b->starts_at->copy()->addHours($length + $hours);
    }

    /** in_progress -> completed. Returns false if someone else already did it. */
    public static function complete(string $bookingId, string $actor, ?string $note = null): bool
    {
        $done = DB::transaction(function () use ($bookingId, $actor, $note) {
            $b = Booking::lockForUpdate()->find($bookingId);
            if (! $b || $b->status !== 'in_progress') {
                return null;
            }
            $b->status = 'completed';
            $b->completed_at = now();
            $b->completed_by = $actor;
            $b->problem_reported_at = null;
            $b->save();
            $b->log('status', $actor, 'in_progress', 'completed', $note);

            return $b;
        });

        if (! $done) {
            return false;
        }
        try {
            Ledger::onCompleted($done->fresh()); // safe twice; covers a missing model hook
        } catch (\Throwable $e) {
            report($e);
        }
        if ($actor === 'customer') {
            self::alert('customer_completed', 'Customer marked a job completed', $done->customerName() . ' marked ' . $done->service_name . ' (' . $done->reference . ') as completed.', $done);
        }

        return true;
    }

    public static function reportProblem(Booking $b, string $text): void
    {
        if (! in_array($b->status, self::REPORTABLE, true)) {
            throw ValidationException::withMessages(['booking' => ['You can report a problem while the job is booked or in progress.']]);
        }
        if ($b->problem_reported_at) {
            throw ValidationException::withMessages(['booking' => ['You already reported a problem. Our team will be in touch.']]);
        }
        $b->forceFill(['problem_reported_at' => now(), 'problem_note' => $text])->save();
        $b->log('problem_reported', 'customer', null, null, $text);
        self::alert('problem_reported', 'Customer reported a problem', $b->customerName() . ' reported a problem with ' . $b->service_name . ' (' . $b->reference . '): ' . \Illuminate\Support\Str::limit($text, 140), $b);
    }

    public static function resolveProblem(Booking $b, ?string $note = null): void
    {
        if (! $b->problem_reported_at) {
            throw ValidationException::withMessages(['booking' => ['There is no open problem on this booking.']]);
        }
        $b->forceFill(['problem_reported_at' => null])->save();
        $b->log('problem_resolved', 'admin', null, null, $note);
    }

    private static function alert(string $type, string $title, string $body, Booking $b): void
    {
        try {
            AdminNotifier::notify($type, $title, $body, '/bookings/' . $b->id, $b->id);
        } catch (\Throwable $e) {
            Log::warning('Admin alert failed: ' . $e->getMessage());
        }
    }
}