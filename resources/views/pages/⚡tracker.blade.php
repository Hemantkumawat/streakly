<?php

use App\Models\ActivityLog;
use App\Models\ActivityType;
use App\Services\Ai\AiService;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Activity Tracker')] class extends Component {

    /** Day currently being viewed/edited (Y-m-d). */
    public string $selectedDate;

    /** Custom one-off activity form. */
    #[Validate('required|string|max:80')]
    public string $customName = '';
    #[Validate('required|integer|min:0|max:999')]
    public int $customPoints = 1;

    /** Activity-type editor state. */
    public bool $showTypeForm = false;
    public ?int $typeId = null;
    #[Validate('required|string|max:80')]
    public string $typeName = '';
    #[Validate('required|integer|min:0|max:999')]
    public int $typePoints = 5;
    #[Validate('nullable|string|max:8')]
    public string $typeIcon = '';

    /** AI feature state. */
    public string $aiText = '';
    public ?string $aiInsight = null;
    /** @var array<int,array{name:string,points:int,icon:string}> */
    public array $aiSuggestions = [];

    public function mount(): void
    {
        $this->selectedDate = Carbon::today()->toDateString();
    }

    /** The configured daily target. */
    public function goal(): int
    {
        return (int) config('tracker.daily_goal', 30);
    }

    /** Date => total points for the trailing ~53 weeks. */
    #[Computed]
    public function days(): array
    {
        $start = Carbon::today()->subWeeks(53)->toDateString();

        return DB::table('activity_logs')
            ->where('user_id', Auth::id())
            ->whereDate('log_date', '>=', $start)
            ->groupByRaw('DATE(log_date)')
            ->selectRaw('DATE(log_date) as d, SUM(points) as pts')
            ->pluck('pts', 'd')
            ->map(fn ($p) => (int) $p)
            ->all();
    }

    /** Active activity types for the quick-add row + manager. */
    #[Computed]
    public function types()
    {
        return Auth::user()->activityTypes()
            ->where('archived', false)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /** Logged entries for the selected day. */
    #[Computed]
    public function dayLogs()
    {
        return ActivityLog::where('user_id', Auth::id())
            ->whereDate('log_date', $this->selectedDate)
            ->orderBy('id')
            ->get();
    }

    public function dayTotal(): int
    {
        return (int) $this->dayLogs->sum('points');
    }

    /** Headline numbers for the stat cards. */
    #[Computed]
    public function stats(): array
    {
        $days  = $this->days;
        $today = Carbon::today()->toDateString();
        $month = Carbon::today()->format('Y-m');

        $monthPts = 0; $monthActive = 0;
        foreach ($days as $d => $p) {
            if (str_starts_with($d, $month)) {
                $monthPts += $p;
                if ($p > 0) $monthActive++;
            }
        }

        // Current streak (consecutive days with points, ending today or yesterday).
        $cur = 0;
        $cursor = Carbon::today();
        if (($days[$today] ?? 0) === 0) {
            $cursor->subDay();
        }
        while (($days[$cursor->toDateString()] ?? 0) > 0) {
            $cur++;
            $cursor->subDay();
        }

        // Best streak across the window.
        $best = 0; $run = 0; $prev = null;
        $keys = array_keys($days);
        sort($keys);
        foreach ($keys as $d) {
            if (($days[$d] ?? 0) <= 0) { $run = 0; $prev = $d; continue; }
            $isNext = $prev !== null && ($days[$prev] ?? 0) > 0
                && Carbon::parse($prev)->addDay()->toDateString() === $d;
            $run = $isNext ? $run + 1 : 1;
            $best = max($best, $run);
            $prev = $d;
        }

        return [
            'today'       => $days[$today] ?? 0,
            'streak'      => $cur,
            'best'        => max($best, $cur),
            'month'       => $monthPts,
            'avg'         => $monthActive ? round($monthPts / $monthActive, 1) : 0,
            'active'      => count(array_filter($days, fn ($p) => $p > 0)),
            'total'       => array_sum($days),
        ];
    }

    /**
     * Heatmap data: columns of weeks (each 7 day-cells), month labels aligned
     * to the week a month begins in, and a human range for the card heading.
     *
     * @return array{weeks:array<int,array{month:string,days:array}>,range:string}
     */
    #[Computed]
    public function heatmap(): array
    {
        $days  = $this->days;
        $today = Carbon::today();
        $start = $today->copy()->subWeeks(52)->startOfWeek(Carbon::SUNDAY);

        $weeks = [];
        $week = [];
        $prevMonth = null;

        for ($d = $start->copy(); $d->lte($today); $d->addDay()) {
            $key = $d->toDateString();
            $pts = (int) ($days[$key] ?? 0);
            $week[] = [
                'date'  => $key,
                'pts'   => $pts,
                'level' => $this->levelFor($pts),
                'today' => $key === $today->toDateString(),
                'label' => $d->format('M j, Y'),
            ];

            if (count($week) === 7) {
                $weeks[] = $this->packWeek($week, $prevMonth);
                $prevMonth = Carbon::parse($week[0]['date'])->format('M');
                $week = [];
            }
        }

        if ($week !== []) {
            $weeks[] = $this->packWeek($week, $prevMonth);
        }

        return [
            'weeks' => $weeks,
            'range' => $start->format('M Y').' – '.$today->format('M Y'),
        ];
    }

    /** Attach a month label only on the first week of each new month. */
    private function packWeek(array $week, ?string $prevMonth): array
    {
        $month = Carbon::parse($week[0]['date'])->format('M');

        return [
            'month' => $month !== $prevMonth ? $month : '',
            'days'  => $week,
        ];
    }

    private function levelFor(int $p): int
    {
        if ($p <= 0) return 0;
        $g = max(1, $this->goal());
        if ($p < $g * 0.34) return 1;
        if ($p < $g * 0.67) return 2;
        if ($p < $g)        return 3;
        return 4;
    }

    // ---- Actions ----------------------------------------------------------

    public function addType(int $id): void
    {
        $type = Auth::user()->activityTypes()->whereKey($id)->first();
        if (! $type) {
            return;
        }
        ActivityLog::create([
            'user_id'  => Auth::id(),
            'type_id'  => $type->id,
            'name'     => $type->name,
            'points'   => $type->points,
            'log_date' => $this->selectedDate,
        ]);
        $this->refreshData();
        Flux::toast(text: "{$type->icon} {$type->name} +{$type->points}", duration: 1500);
    }

    public function addCustom(): void
    {
        $this->validateOnly('customName');
        $this->validateOnly('customPoints');

        ActivityLog::create([
            'user_id'  => Auth::id(),
            'type_id'  => null,
            'name'     => trim($this->customName),
            'points'   => $this->customPoints,
            'log_date' => $this->selectedDate,
        ]);
        $this->customName = '';
        $this->customPoints = 1;
        $this->refreshData();
    }

    public function deleteLog(int $id): void
    {
        ActivityLog::where('user_id', Auth::id())->whereKey($id)->delete();
        $this->refreshData();
    }

    public function pickDate(string $date): void
    {
        if (Carbon::hasFormat($date, 'Y-m-d')) {
            $this->selectedDate = $date;
            unset($this->dayLogs);
        }
    }

    // ---- AI features ------------------------------------------------------

    private function ai(): AiService
    {
        return app(AiService::class);
    }

    #[Computed]
    public function aiEnabled(): bool
    {
        return $this->ai()->enabled();
    }

    /** Turn a free-text description into one or more logged activities. */
    public function aiLog(): void
    {
        $text = trim($this->aiText);
        if ($text === '') {
            return;
        }

        try {
            $known = $this->types
                ->map(fn ($t) => ['name' => $t->name, 'points' => $t->points, 'icon' => (string) $t->icon])
                ->all();
            $items = $this->ai()->parseActivities($text, $known);
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: __('AI could not parse that: ').$e->getMessage(), duration: 3500);

            return;
        }

        if ($items === []) {
            Flux::toast(variant: 'warning', text: __('No activities found in that description.'), duration: 2500);

            return;
        }

        foreach ($items as $item) {
            $type = $this->types->firstWhere('name', $item['name']);
            ActivityLog::create([
                'user_id'  => Auth::id(),
                'type_id'  => $type?->id,
                'name'     => $item['name'],
                'points'   => $item['points'],
                'log_date' => $this->selectedDate,
            ]);
        }

        $this->aiText = '';
        $this->refreshData();
        Flux::toast(
            variant: 'success',
            text: trans_choice(':count activity logged.|:count activities logged.', count($items), ['count' => count($items)]),
            duration: 2000,
        );
    }

    /** Generate a short coaching insight from recent activity. */
    public function generateInsight(): void
    {
        try {
            $recent = collect($this->days)
                ->sortKeys()
                ->slice(-14)
                ->all();
            $this->aiInsight = $this->ai()->weeklyInsight($this->stats, $recent);
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: __('Could not generate insights: ').$e->getMessage(), duration: 3500);
        }
    }

    /** Ask the AI for new activity types to track. */
    public function suggestActivities(): void
    {
        try {
            $existing = $this->types->pluck('name')->all();
            $this->aiSuggestions = $this->ai()->suggestActivities($existing);
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: __('Could not get suggestions: ').$e->getMessage(), duration: 3500);
        }
    }

    public function addSuggestion(int $index): void
    {
        $s = $this->aiSuggestions[$index] ?? null;
        if (! $s) {
            return;
        }

        Auth::user()->activityTypes()->create([
            'name'   => $s['name'],
            'points' => $s['points'],
            'icon'   => $s['icon'],
        ]);

        unset($this->aiSuggestions[$index]);
        $this->aiSuggestions = array_values($this->aiSuggestions);
        unset($this->types);
        Flux::toast(variant: 'success', text: __('Activity added.'), duration: 1500);
    }

    // ---- Activity-type manager -------------------------------------------

    public function newType(): void
    {
        $this->reset(['typeId', 'typeName', 'typePoints', 'typeIcon']);
        $this->typePoints = 5;
        $this->showTypeForm = true;
    }

    public function editType(int $id): void
    {
        $t = Auth::user()->activityTypes()->whereKey($id)->firstOrFail();
        $this->typeId     = $t->id;
        $this->typeName   = $t->name;
        $this->typePoints = $t->points;
        $this->typeIcon   = $t->icon;
        $this->showTypeForm = true;
    }

    public function saveType(): void
    {
        $this->validateOnly('typeName');
        $this->validateOnly('typePoints');
        $this->validateOnly('typeIcon');

        Auth::user()->activityTypes()->updateOrCreate(
            ['id' => $this->typeId],
            [
                'name'   => trim($this->typeName),
                'points' => $this->typePoints,
                'icon'   => trim($this->typeIcon),
            ]
        );

        $this->showTypeForm = false;
        $this->reset(['typeId', 'typeName', 'typePoints', 'typeIcon']);
        unset($this->types);
        Flux::toast(variant: 'success', text: __('Activity saved.'), duration: 1500);
    }

    public function deleteType(int $id): void
    {
        // Archive so historical logs keep their name.
        Auth::user()->activityTypes()->whereKey($id)->update(['archived' => true]);
        unset($this->types);
    }

    private function refreshData(): void
    {
        unset($this->days, $this->dayLogs, $this->stats, $this->heatmap);
    }
}; ?>

<div class="mx-auto flex w-full max-w-3xl flex-col gap-5" wire:key="tracker-root">
    @php($s = $this->stats)

        {{-- Stat cards --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            @foreach ([
                ['🎯', $this->dayTotal(), 'Today'],
                ['🔥', $s['streak'], 'Day streak'],
                ['🏆', $s['best'], 'Best streak'],
                ['📅', $s['month'], 'This month'],
                ['📊', $s['avg'], 'Avg / active day'],
                ['✅', $s['active'], 'Active days'],
            ] as [$icon, $val, $lbl])
                <div class="rounded-xl border border-neutral-200 bg-white p-4 text-center dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-2xl font-bold">{{ $val }}</div>
                    <div class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{{ $icon }} {{ $lbl }}</div>
                </div>
            @endforeach
        </div>

        {{-- AI insight --}}
        @if ($this->aiEnabled)
            <div class="rounded-xl border border-orange-200 bg-gradient-to-br from-orange-50 to-rose-50 p-5 dark:border-orange-900/40 dark:from-orange-950/30 dark:to-rose-950/20">
                <div class="flex items-center justify-between gap-3">
                    <flux:heading size="lg">✨ {{ __('AI insights') }}</flux:heading>
                    <flux:button size="sm" variant="ghost" wire:click="generateInsight" wire:loading.attr="disabled" wire:target="generateInsight">
                        <span wire:loading.remove wire:target="generateInsight">{{ $aiInsight ? __('Refresh') : __('Generate') }}</span>
                        <span wire:loading wire:target="generateInsight">{{ __('Thinking…') }}</span>
                    </flux:button>
                </div>
                <p class="mt-2 text-sm text-neutral-700 dark:text-neutral-300">
                    {{ $aiInsight ?: __('Tap Generate for a quick, personalised read on your streaks and momentum.') }}
                </p>
            </div>
        @endif

        {{-- Day editor --}}
        <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading size="lg">
                    {{ \Illuminate\Support\Carbon::parse($selectedDate)->isToday()
                        ? __('Today')
                        : \Illuminate\Support\Carbon::parse($selectedDate)->format('D, M j') }}
                </flux:heading>
                <input type="date" wire:model.live="selectedDate" max="{{ now()->toDateString() }}"
                    class="rounded-lg border border-neutral-300 bg-transparent px-2 py-1 text-sm dark:border-neutral-600">
            </div>

            {{-- Progress bar --}}
            @php($goal = $this->goal())
            @php($pct = min(100, $goal ? round($this->dayTotal() / $goal * 100) : 0))
            <div class="mt-4 flex items-center gap-3">
                <div class="h-2.5 flex-1 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-800">
                    <div class="h-full rounded-full bg-gradient-to-r from-green-500 to-green-400 transition-all"
                         style="width: {{ $pct }}%"></div>
                </div>
                <div class="whitespace-nowrap text-sm text-neutral-500 dark:text-neutral-400">
                    <b class="text-neutral-800 dark:text-neutral-100">{{ $this->dayTotal() }}</b> / {{ $goal }} pts
                </div>
            </div>

            {{-- Logged entries --}}
            <div class="mt-4 flex flex-col gap-2">
                @forelse ($this->dayLogs as $log)
                    <div wire:key="day-log-{{ $log->id }}"
                         class="flex items-center gap-3 rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2 dark:border-neutral-700 dark:bg-neutral-800/60">
                        <span class="text-lg">{{ $log->type?->icon ?: '•' }}</span>
                        <span class="flex-1">{{ $log->name }}</span>
                        <span class="rounded-md bg-green-100 px-2 py-0.5 text-sm font-semibold text-green-700 dark:bg-green-900/40 dark:text-green-300">
                            +{{ $log->points }}
                        </span>
                        <button wire:click="deleteLog({{ $log->id }})"
                                wire:confirm="Remove this entry?"
                                class="text-neutral-400 hover:text-red-500" title="Remove">✕</button>
                    </div>
                @empty
                    <p class="py-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('No activities logged for this day yet.') }}</p>
                @endforelse
            </div>

            {{-- Quick add --}}
            <h3 class="mt-5 mb-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Quick add') }}</h3>
            <div class="flex flex-wrap gap-2">
                @foreach ($this->types as $type)
                    <button wire:key="quick-{{ $type->id }}" wire:click="addType({{ $type->id }})"
                            class="inline-flex items-center gap-1.5 rounded-full border border-neutral-300 bg-white px-3 py-1.5 text-sm hover:border-green-500 dark:border-neutral-600 dark:bg-neutral-800">
                        <span>{{ $type->icon }}</span>
                        <span>{{ $type->name }}</span>
                        <span class="text-xs text-neutral-400">+{{ $type->points }}</span>
                    </button>
                @endforeach
            </div>

            {{-- AI natural-language logging --}}
            @if ($this->aiEnabled)
                <h3 class="mt-5 mb-2 text-sm text-neutral-500 dark:text-neutral-400">✨ {{ __('Log with AI') }}</h3>
                <form wire:submit="aiLog" class="flex flex-wrap items-start gap-2">
                    <flux:input wire:model="aiText"
                        placeholder="{{ __('e.g. walked 30 min, read 20 pages, hit the gym') }}"
                        class="min-w-40 flex-1" />
                    <flux:button type="submit" variant="primary"
                        wire:loading.attr="disabled" wire:target="aiLog">
                        <span wire:loading.remove wire:target="aiLog">{{ __('Add') }}</span>
                        <span wire:loading wire:target="aiLog">{{ __('Parsing…') }}</span>
                    </flux:button>
                </form>
            @endif

            {{-- Custom one-off --}}
            <h3 class="mt-5 mb-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Custom entry') }}</h3>
            <form wire:submit="addCustom" class="flex flex-wrap items-start gap-2">
                <flux:input wire:model="customName" placeholder="{{ __('Custom activity') }}" class="min-w-40 flex-1" />
                <flux:input type="number" wire:model="customPoints" class="w-20" min="0" max="999" />
                <flux:button type="submit" variant="primary">{{ __('Add') }}</flux:button>
            </form>
        </div>

        {{-- Heatmap --}}
        <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg">{{ __('Activity') }}</flux:heading>
                <span class="text-sm text-neutral-500 dark:text-neutral-400">
                    {{ $this->heatmap['range'] }} · {{ $s['total'] }} {{ __('pts') }}
                </span>
            </div>

            @php($palette = [
                0 => 'bg-[#ebedf0] dark:bg-[#161b22]',
                1 => 'bg-[#9be9a8] dark:bg-[#0e4429]',
                2 => 'bg-[#40c463] dark:bg-[#006d32]',
                3 => 'bg-[#30a14e] dark:bg-[#26a641]',
                4 => 'bg-[#216e39] dark:bg-[#39d353]',
            ])
            @php($weekdays = ['', 'Mon', '', 'Wed', '', 'Fri', ''])

            <div class="overflow-x-auto pb-2">
                <div class="inline-flex flex-col gap-1">
                    {{-- Month labels, aligned over the grid (offset past the weekday column) --}}
                    <div class="flex gap-[3px] pl-8">
                        @foreach ($this->heatmap['weeks'] as $week)
                            <div class="w-3.5 shrink-0 whitespace-nowrap text-[10px] leading-none text-neutral-500 dark:text-neutral-400">{{ $week['month'] }}</div>
                        @endforeach
                    </div>

                    <div class="flex gap-[3px]">
                        {{-- Weekday labels --}}
                        <div class="mr-1 flex w-7 flex-col gap-[3px] pt-px">
                            @foreach ($weekdays as $wd)
                                <div class="h-3.5 text-[10px] leading-[14px] text-neutral-500 dark:text-neutral-400">{{ $wd }}</div>
                            @endforeach
                        </div>

                        {{-- Week columns --}}
                        @foreach ($this->heatmap['weeks'] as $week)
                            <div wire:key="week-{{ $loop->index }}" class="flex flex-col gap-[3px]">
                                @foreach ($week['days'] as $cell)
                                    <button type="button"
                                        wire:key="cell-{{ $cell['date'] }}"
                                        wire:click="pickDate('{{ $cell['date'] }}')"
                                        title="{{ $cell['label'] }} — {{ $cell['pts'] }} pts"
                                        class="h-3.5 w-3.5 rounded-[3px] {{ $palette[$cell['level']] }} {{ $cell['today'] ? 'ring-1 ring-neutral-900 dark:ring-white' : '' }} {{ $cell['date'] === $selectedDate ? 'ring-2 ring-blue-500' : '' }}">
                                    </button>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="mt-3 flex items-center justify-end gap-1 text-xs text-neutral-500 dark:text-neutral-400">
                {{ __('Less') }}
                @foreach ($palette as $cls)
                    <span class="h-3 w-3 rounded-[3px] {{ $cls }}"></span>
                @endforeach
                {{ __('More') }}
            </div>
        </div>

        {{-- Activity manager --}}
        <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg">{{ __('My activities') }}</flux:heading>
                <div class="flex gap-2">
                    @if ($this->aiEnabled)
                        <flux:button size="sm" variant="ghost" wire:click="suggestActivities"
                            wire:loading.attr="disabled" wire:target="suggestActivities">
                            <span wire:loading.remove wire:target="suggestActivities">{{ __('Suggest') }}</span>
                            <span wire:loading wire:target="suggestActivities">{{ __('Thinking…') }}</span>
                        </flux:button>
                    @endif
                    <flux:button size="sm" wire:click="newType">+ {{ __('New') }}</flux:button>
                </div>
            </div>

            {{-- AI suggestions --}}
            @if ($this->aiSuggestions)
                <div class="mb-4 flex flex-wrap gap-2">
                    @foreach ($this->aiSuggestions as $i => $sug)
                        <button wire:key="sug-{{ $i }}" wire:click="addSuggestion({{ $i }})"
                                class="inline-flex items-center gap-1.5 rounded-full border border-dashed border-orange-400 bg-orange-50 px-3 py-1.5 text-sm hover:bg-orange-100 dark:border-orange-700 dark:bg-orange-950/30 dark:hover:bg-orange-950/50">
                            <span>{{ $sug['icon'] }}</span>
                            <span>{{ $sug['name'] }}</span>
                            <span class="text-xs text-neutral-400">+{{ $sug['points'] }}</span>
                            <span class="text-orange-500">＋</span>
                        </button>
                    @endforeach
                </div>
            @endif

            @if ($showTypeForm)
                <form wire:submit="saveType" class="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-neutral-200 p-3 dark:border-neutral-700">
                    <flux:input wire:model="typeIcon" label="{{ __('Icon') }}" class="w-16" placeholder="🏃" />
                    <flux:input wire:model="typeName" label="{{ __('Name') }}" class="min-w-40 flex-1" />
                    <flux:input type="number" wire:model="typePoints" label="{{ __('Points') }}" class="w-24" min="0" max="999" />
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                    <flux:button type="button" variant="ghost" wire:click="$set('showTypeForm', false)">{{ __('Cancel') }}</flux:button>
                </form>
            @endif

            <div class="flex flex-col gap-2">
                @foreach ($this->types as $type)
                    <div wire:key="type-{{ $type->id }}"
                         class="flex items-center gap-3 rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2 dark:border-neutral-700 dark:bg-neutral-800/60">
                        <span class="text-lg">{{ $type->icon ?: '•' }}</span>
                        <span class="flex-1">{{ $type->name }}</span>
                        <span class="text-sm text-neutral-500">+{{ $type->points }}</span>
                        <button wire:click="editType({{ $type->id }})" class="text-neutral-400 hover:text-blue-500" title="Edit">✎</button>
                        <button wire:click="deleteType({{ $type->id }})" wire:confirm="Archive this activity?" class="text-neutral-400 hover:text-red-500" title="Archive">🗑</button>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Backup --}}
        <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
            <flux:heading size="lg" class="mb-2">{{ __('Backup') }}</flux:heading>
            <flux:button size="sm" variant="ghost" href="{{ route('tracker.export') }}">⬇ {{ __('Export JSON') }}</flux:button>
            <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">
                {{ __('Your data lives in your database and syncs across every device you log in from.') }}
            </p>
        </div>

    </div>
</div>
