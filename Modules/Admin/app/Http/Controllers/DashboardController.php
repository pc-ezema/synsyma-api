<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin dashboard. One call, and each section is included only if this admin holds its permission:
 *   bookings.view  -> attention (bookings), bookings KPIs, charts, board, feed, quotes
 *   contractors.view -> contractor review queue
 *   payouts.view   -> revenue, refunds, owed and paid to contractors
 *   reviews.view   -> ratings
 *   users.view     -> customer totals, new customers, newest customers
 *   contractors.view also gives contractor totals (companies / individuals) and the newest contractors
 * Every admin can open the page itself; a section they may not see is simply absent.
 * Day boundaries follow the admin's own time zone (?tz=Africa/Lagos). Totals are cached for 30 seconds.
 */
class DashboardController extends Controller
{
    private const PAID = ['succeeded', 'partially_refunded', 'refunded'];
    private const RANGES = ['today' => 1, '7d' => 7, '30d' => 30, '90d' => 90];

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'range' => ['sometimes', 'in:today,7d,30d,90d'],
            'tz' => ['sometimes', 'nullable', 'timezone'],
        ]);
        $range = $data['range'] ?? '7d';
        $tz = $data['tz'] ?? config('app.timezone');
        $user = $request->user();

        $can = [
            'bookings' => (bool) $user?->can('bookings.view'),
            'contractors' => (bool) $user?->can('contractors.view'),
            'payouts' => (bool) $user?->can('payouts.view'),
            'reviews' => (bool) $user?->can('reviews.view'),
            'users' => (bool) $user?->can('users.view'),
        ];

        $key = 'admin-dashboard:' . md5(json_encode([$range, $tz, $can]));
        $payload = Cache::remember($key, 30, fn () => $this->build($range, $tz, $can));

        return response()->json(['data' => $payload]);
    }

    private function build(string $range, string $tz, array $can): array
    {
        $now = CarbonImmutable::now($tz);
        $days = self::RANGES[$range];
        $from = $range === 'today' ? $now->startOfDay() : $now->subDays($days - 1)->startOfDay();
        $prevFrom = $from->subDays($days);

        // For "today", compare with the same stretch of yesterday, not all of it.
        $prevTo = $range === 'today' ? $prevFrom->addSeconds((int) abs($from->diffInSeconds($now))) : $from;
        $w = new Window($from, $now, $prevFrom, $prevTo, $tz, $range);

        $out = [
            'range' => $range,
            'tz' => $tz,
            'from' => $from->toIso8601String(),
            'to' => $now->toIso8601String(),
            'generated_at' => $now->toIso8601String(),
            'can' => $can,
            'attention' => [],
            'kpis' => [],
        ];

        if ($can['bookings']) {
            $out['attention'] = array_merge($out['attention'], $this->bookingAttention($now));
            $out['kpis'] = array_merge($out['kpis'], $this->bookingKpis($w));
            $out['charts'] = $this->bookingCharts($w);
            $out['board'] = $this->board($now, $tz);
            $out['feed'] = $this->feed();
        }
        if ($can['contractors']) {
            $out['attention'] = array_merge($out['attention'], $this->contractorAttention());
            $out['kpis'] = array_merge($out['kpis'], $this->contractorKpis());
            $out['people']['contractors'] = $this->latestContractors();
        }
        if ($can['users']) {
            $out['kpis'] = array_merge($out['kpis'], $this->customerKpis($w));
            $out['people']['customers'] = $this->latestCustomers();
        }
        if ($can['payouts']) {
            $out['attention'] = array_merge($out['attention'], $this->payoutAttention($now));
            $out['kpis'] = array_merge($out['kpis'], $this->moneyKpis($w));
            $out['charts']['revenue'] = $this->revenueSeries($w);
        }
        if ($can['reviews']) {
            $out['kpis'] = array_merge($out['kpis'], $this->reviewKpis($w));
            $out['charts']['ratings'] = $this->ratingDistribution($w);
        }

        return $out;
    }

    // ---------------------------------------------------------------- needs attention

    private function bookingAttention(CarbonImmutable $now): array
    {
        $soon = $this->utc($now->addHours(24));
        $near = $this->utc($now->addHours(2));
        $nowU = $this->utc($now);

        $unassigned = DB::table('bookings')->where('status', 'confirmed');
        $items = [
            ['key' => 'unassigned', 'count' => (clone $unassigned)->count(), 'oldest' => $this->iso((clone $unassigned)->min('created_at'))],
            ['key' => 'unassigned_soon', 'count' => (clone $unassigned)->where('starts_at', '<=', $soon)->where('starts_at', '>=', $nowU)->count()],
            ['key' => 'not_on_the_way', 'count' => DB::table('bookings')->where('status', 'assigned')->where('starts_at', '<=', $near)->count()],
            ['key' => 'problems', 'count' => DB::table('bookings')->whereNotNull('problem_reported_at')->whereIn('status', ['assigned', 'en_route', 'in_progress'])->count()],
            ['key' => 'quotes_to_price', 'count' => DB::table('bookings')->where('status', 'quote_requested')->count()],
            ['key' => 'quotes_expiring', 'count' => DB::table('bookings')->where('status', 'quote_sent')->whereBetween('quote_expires_at', [$nowU, $soon])->count()],
            ['key' => 'payment_failed', 'count' => DB::table('payments')->join('bookings', 'bookings.id', '=', 'payments.booking_id')
                ->where('payments.status', 'failed')->where('bookings.status', 'pending_payment')
                ->where('payments.created_at', '>=', $this->utc($now->subDays(7)))->distinct()->count('payments.booking_id')],
        ];

        return $items;
    }

    private function contractorAttention(): array
    {
        $pending = DB::table('contractors')->whereNull('deleted_at')->where('status', 'pending');
        $noId = (clone $pending)->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('contractor_documents')
            ->whereColumn('contractor_documents.contractor_id', 'contractors.id')->where('kind', 'id'))->count();

        $bg = Schema::hasColumn('contractors', 'background_status')
            ? (clone $pending)->where('background_status', '!=', 'passed')->count()
            : 0;

        return [
            ['key' => 'contractors_pending', 'count' => (clone $pending)->count(), 'no_id' => $noId, 'awaiting_background' => $bg],
        ];
    }

    private function payoutAttention(CarbonImmutable $now): array
    {
        $rows = DB::table('payout_entries')->whereNull('payout_id')->where('payable_at', '<=', $this->utc($now))
            ->selectRaw('contractor_id, sum(amount) as total')->groupBy('contractor_id')->get()->filter(fn ($r) => (float) $r->total > 0.004);

        return [
            ['key' => 'payouts_due', 'count' => $rows->count(), 'amount' => round((float) $rows->sum('total'), 2)],
        ];
    }

    // ---------------------------------------------------------------- numbers

    private function bookingKpis(Window $w): array
    {
        $paidBookings = fn ($a, $b) => (int) DB::table('payments')->whereIn('status', self::PAID)->where('purpose', 'booking')
            ->whereBetween('paid_at', [$this->utc($a), $this->utc($b)])->distinct()->count('booking_id');
        $completed = fn ($a, $b) => (int) DB::table('bookings')->whereBetween('completed_at', [$this->utc($a), $this->utc($b)])->count();
        $cancelled = fn ($a, $b) => (int) DB::table('bookings')->whereBetween('cancelled_at', [$this->utc($a), $this->utc($b)])->count();

        return [
            $this->kpi('bookings', 'New bookings', $paidBookings($w->from, $w->to), $paidBookings($w->prevFrom, $w->prevTo), 'count'),
            $this->kpi('completed', 'Completed jobs', $completed($w->from, $w->to), $completed($w->prevFrom, $w->prevTo), 'count'),
            $this->kpi('cancelled', 'Cancellations', $cancelled($w->from, $w->to), $cancelled($w->prevFrom, $w->prevTo), 'count', true),
        ];
    }

    private function moneyKpis(Window $w): array
    {
        $sum = function ($a, $b) {
            $r = DB::table('payments')->whereIn('status', self::PAID)->whereBetween('paid_at', [$this->utc($a), $this->utc($b)])
                ->selectRaw('coalesce(sum(amount),0) as gross, coalesce(sum(refunded_total),0) as refunded')->first();

            return [(float) $r->gross, (float) $r->refunded];
        };
        [$gross, $refunded] = $sum($w->from, $w->to);
        [$pGross, $pRefunded] = $sum($w->prevFrom, $w->prevTo);

        // Money kept from bookings cancelled in the period (what the cancellation fee left us with).
        $kept = function ($a, $b) {
            return (float) DB::table('payments')->join('bookings', 'bookings.id', '=', 'payments.booking_id')
                ->whereIn('payments.status', self::PAID)->whereBetween('bookings.cancelled_at', [$this->utc($a), $this->utc($b)])
                ->selectRaw('coalesce(sum(payments.amount - payments.refunded_total),0) as k')->value('k');
        };

        $owed = (float) DB::table('payout_entries')->whereNull('payout_id')->sum('amount');
        $paid = fn ($a, $b) => (float) DB::table('payouts')->whereBetween('paid_at', [$this->utc($a), $this->utc($b)])->sum('amount');

        return [
            $this->kpi('revenue', 'Revenue taken', $gross, $pGross, 'money'),
            $this->kpi('refunds', 'Refunded', $refunded, $pRefunded, 'money', true),
            $this->kpi('net', 'Net after refunds', $gross - $refunded, $pGross - $pRefunded, 'money'),
            $this->kpi('fees_kept', 'Cancellation fees kept', $kept($w->from, $w->to), $kept($w->prevFrom, $w->prevTo), 'money'),
            $this->kpi('paid_out', 'Paid to contractors', $paid($w->from, $w->to), $paid($w->prevFrom, $w->prevTo), 'money'),
            ['key' => 'owed', 'label' => 'Owed to contractors now', 'value' => round($owed, 2), 'previous' => null, 'change' => null, 'format' => 'money', 'invert' => false],
        ];
    }

    /** Totals as they stand now, split into individuals and companies. */
    private function contractorKpis(): array
    {
        $rows = DB::table('contractors')->whereNull('deleted_at')->selectRaw('type, status, count(*) as n')->groupBy('type', 'status')->get();
        $count = fn (?string $type, ?string $status = null) => (int) $rows->when($type, fn ($r) => $r->where('type', $type))
            ->when($status, fn ($r) => $r->where('status', $status))->sum('n');

        $make = fn (string $key, string $label, ?string $type) => [
            'key' => $key, 'label' => $label, 'value' => $count($type), 'previous' => null, 'change' => null, 'format' => 'count', 'invert' => false,
            'sub' => $count($type, 'approved') . ' approved · ' . $count($type, 'pending') . ' pending',
        ];

        return [
            $make('contractors_total', 'Total contractors', null),
            $make('contractors_individual', 'Individual contractors', 'individual'),
            $make('contractors_company', 'Company contractors', 'company'),
        ];
    }

    private function customerKpis(Window $w): array
    {
        $users = fn () => ($this->userModel())::query();
        $total = (int) $users()->count();
        $active = Schema::hasColumn('users', 'is_active') ? (int) $users()->where('is_active', true)->count() : null;
        $new = fn ($a, $b) => (int) $users()->whereBetween('created_at', [$this->utc($a), $this->utc($b)])->count();

        return [
            ['key' => 'customers_total', 'label' => 'Total customers', 'value' => $total, 'previous' => null, 'change' => null, 'format' => 'count', 'invert' => false,
                'sub' => $active !== null ? "{$active} active" : null],
            $this->kpi('customers_new', 'New customers', $new($w->from, $w->to), $new($w->prevFrom, $w->prevTo), 'count'),
        ];
    }

    private function latestCustomers(): array
    {
        $cols = ['id', 'first_name', 'last_name', 'email', 'created_at'];
        if (Schema::hasColumn('users', 'is_active')) {
            $cols[] = 'is_active';
        }

        return ($this->userModel())::query()->orderByDesc('created_at')->limit(10)->get($cols)->map(fn ($u) => [
            'id' => $u->id,
            'name' => trim($u->first_name . ' ' . $u->last_name) ?: $u->email,
            'email' => $u->email,
            'is_active' => $u->is_active ?? true,
            'created_at' => $this->iso((string) $u->created_at),
        ])->all();
    }

    private function latestContractors(): array
    {
        return DB::table('contractors')->whereNull('deleted_at')->orderByDesc('created_at')->limit(10)
            ->get(['id', 'name', 'type', 'city', 'region', 'status', 'created_at'])->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'type' => $c->type, 'location' => "{$c->city}, {$c->region}", 'status' => $c->status,
                'created_at' => $this->iso($c->created_at),
            ])->all();
    }

    /** The customers' model (the app's own users). */
    private function userModel(): string
    {
        return (string) config('auth.providers.users.model');
    }

    private function reviewKpis(Window $w): array
    {
        $stats = function ($a, $b) {
            $r = DB::table('reviews')->where('is_hidden', false)->whereBetween('created_at', [$this->utc($a), $this->utc($b)])
                ->selectRaw('count(*) as n, avg(rating) as avg')->first();

            return [(int) $r->n, $r->avg !== null ? round((float) $r->avg, 2) : null];
        };
        [$n, $avg] = $stats($w->from, $w->to);
        [$pn, $pavg] = $stats($w->prevFrom, $w->prevTo);

        return [
            $this->kpi('rating', 'Average rating', $avg, $pavg, 'rating'),
            $this->kpi('reviews', 'New reviews', $n, $pn, 'count'),
        ];
    }

    // ---------------------------------------------------------------- charts

    private function bookingCharts(Window $w): array
    {
        $byStatus = DB::table('bookings')->whereBetween('created_at', [$this->utc($w->from), $this->utc($w->to)])
            ->selectRaw('status, count(*) as n')->groupBy('status')->orderByDesc('n')->get()
            ->map(fn ($r) => ['status' => $r->status, 'count' => (int) $r->n])->values();

        $live = ['confirmed', 'assigned', 'en_route', 'in_progress', 'completed'];
        $services = DB::table('bookings')->whereBetween('created_at', [$this->utc($w->from), $this->utc($w->to)])->whereIn('status', $live)
            ->selectRaw('service_name as name, count(*) as n')->groupBy('service_name')->orderByDesc('n')->limit(6)->get()
            ->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->n])->values();
        $areas = DB::table('bookings')->whereBetween('created_at', [$this->utc($w->from), $this->utc($w->to)])->whereIn('status', $live)
            ->selectRaw('city, region, count(*) as n')->groupBy('city', 'region')->orderByDesc('n')->limit(6)->get()
            ->map(fn ($r) => ['name' => "{$r->city}, {$r->region}", 'count' => (int) $r->n])->values();

        // Bookings by day (or hour, for today): counted on the day they were paid.
        $rows = DB::table('payments')->whereIn('status', self::PAID)->where('purpose', 'booking')
            ->whereBetween('paid_at', [$this->utc($w->from), $this->utc($w->to)])->get(['booking_id', 'paid_at']);
        $series = $this->bucket($w, $rows->unique('booking_id'), 'paid_at', fn () => 1);

        return ['by_status' => $byStatus, 'services' => $services, 'areas' => $areas, 'bookings' => $series];
    }

    private function revenueSeries(Window $w): array
    {
        $rows = DB::table('payments')->whereIn('status', self::PAID)
            ->whereBetween('paid_at', [$this->utc($w->from), $this->utc($w->to)])->get(['amount', 'refunded_total', 'paid_at']);

        return $this->bucket($w, $rows, 'paid_at', fn ($r) => (float) $r->amount - (float) $r->refunded_total, true);
    }

    private function ratingDistribution(Window $w): array
    {
        $dist = DB::table('reviews')->where('is_hidden', false)->whereBetween('created_at', [$this->utc($w->from), $this->utc($w->to)])
            ->selectRaw('rating, count(*) as n')->groupBy('rating')->pluck('n', 'rating');

        return collect([5, 4, 3, 2, 1])->map(fn ($r) => ['rating' => $r, 'count' => (int) ($dist[$r] ?? 0)])->all();
    }

    /** Sums values into one bucket per day (or per hour for "today"), in the admin's time zone. */
    private function bucket(Window $w, Collection $rows, string $col, callable $value, bool $money = false): array
    {
        $hourly = $w->range === 'today';
        $labels = [];
        $cursor = $hourly ? $w->from : $w->from->startOfDay();
        $end = $w->to;
        while ($cursor <= $end) {
            $labels[$cursor->format($hourly ? 'Y-m-d H' : 'Y-m-d')] = 0.0;
            $cursor = $hourly ? $cursor->addHour() : $cursor->addDay();
        }
        $appTz = config('app.timezone');
        foreach ($rows as $r) {
            $k = CarbonImmutable::parse($r->{$col}, $appTz)->setTimezone($w->tz)->format($hourly ? 'Y-m-d H' : 'Y-m-d');
            if (array_key_exists($k, $labels)) {
                $labels[$k] += $value($r);
            }
        }

        $out = [];
        foreach ($labels as $k => $v) {
            $out[] = ['t' => $k, 'v' => $money ? round($v, 2) : (int) $v];
        }

        return $out;
    }

    // ---------------------------------------------------------------- today's board and the feed

    private function board(CarbonImmutable $now, string $tz): array
    {
        $start = $now->startOfDay();
        $rows = DB::table('bookings')->leftJoin('contractors', 'contractors.id', '=', 'bookings.contractor_id')
            ->whereBetween('bookings.starts_at', [$this->utc($start), $this->utc($start->addDay())])
            ->whereIn('bookings.status', ['confirmed', 'assigned', 'en_route', 'in_progress', 'completed'])
            ->orderBy('bookings.starts_at')->limit(80)
            ->get(['bookings.id', 'bookings.reference', 'bookings.status', 'bookings.starts_at', 'bookings.service_name', 'bookings.city', 'bookings.problem_reported_at', 'contractors.name as contractor']);
        $appTz = config('app.timezone');

        $cols = ['confirmed' => [], 'assigned' => [], 'en_route' => [], 'in_progress' => [], 'completed' => []];
        foreach ($rows as $r) {
            $cols[$r->status][] = [
                'id' => $r->id,
                'reference' => $r->reference,
                'starts_at' => CarbonImmutable::parse($r->starts_at, $appTz)->setTimezone($tz)->toIso8601String(),
                'service' => $r->service_name,
                'city' => $r->city,
                'contractor' => $r->contractor,
                'problem' => $r->problem_reported_at !== null,
            ];
        }

        return $cols;
    }

    private function feed(): array
    {
        return DB::table('booking_events')->join('bookings', 'bookings.id', '=', 'booking_events.booking_id')
            ->orderByDesc('booking_events.created_at')->limit(15)
            ->get(['booking_events.id', 'booking_events.type', 'booking_events.actor', 'booking_events.from_status', 'booking_events.to_status', 'booking_events.note',
                'booking_events.created_at', 'bookings.id as booking_id', 'bookings.reference', 'bookings.service_name'])
            ->map(fn ($e) => [
                'id' => $e->id, 'type' => $e->type, 'actor' => $e->actor, 'from' => $e->from_status, 'to' => $e->to_status,
                'note' => $e->note, 'at' => $this->iso($e->created_at), 'booking_id' => $e->booking_id, 'reference' => $e->reference, 'service' => $e->service_name,
            ])->all();
    }

    // ---------------------------------------------------------------- helpers

    private function kpi(string $key, string $label, float|int|null $value, float|int|null $previous, string $format, bool $invert = false): array
    {
        $change = null;
        if ($value !== null && $previous !== null) {
            $change = $previous == 0 ? ($value == 0 ? 0.0 : null) : round((($value - $previous) / abs($previous)) * 100, 1);
        }

        return [
            'key' => $key, 'label' => $label,
            'value' => is_float($value) ? round($value, 2) : $value,
            'previous' => is_float($previous) ? round($previous, 2) : $previous,
            'change' => $change, // percent against the previous period; null when there is nothing to compare
            'format' => $format,
            'invert' => $invert, // true when "up" is bad news (cancellations, refunds)
            'sub' => null,
        ];
    }

    private function utc(CarbonImmutable $t): string
    {
        return $t->setTimezone(config('app.timezone'))->toDateTimeString();
    }

    private function iso(?string $dbTime): ?string
    {
        return $dbTime ? CarbonImmutable::parse($dbTime, config('app.timezone'))->toIso8601String() : null;
    }

    private function db(CarbonImmutable $now): CarbonImmutable
    {
        return $now;
    }
}

/** The period being shown, and the one before it. */
final class Window
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public CarbonImmutable $prevFrom,
        public CarbonImmutable $prevTo,
        public string $tz,
        public string $range,
    ) {
    }
}