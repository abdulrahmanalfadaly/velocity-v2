<?php

namespace App\Http\Controllers;

use App\Domain\Prioritization\AhpCalculator;
use App\Domain\Prioritization\Weights;
use App\Services\StudyLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Your weights (FR9) and how the score is worked out (NFR5). */
class WeightController extends Controller
{
    public function edit(Request $request): Response
    {
        $profile = $request->user()->weights();
        $ahp = (new AhpCalculator())->derive(AhpCalculator::velocityMatrix());

        return Inertia::render('weights/edit', [
            'weights' => $profile->weights()->toPercentages(),
            'isDefault' => $profile->weights()->isDefault(),
            'horizonDays' => $profile->horizon_days,
            'hoursPerDay' => $profile->hours_per_day,
            'ahp' => [
                'matrix' => AhpCalculator::velocityMatrix(),
                'weights' => array_map(fn ($w) => round($w, 4), $ahp['weights']),
                'lambdaMax' => round($ahp['lambdaMax'], 3),
                'ci' => round($ahp['ci'], 4),
                'cr' => round($ahp['cr'], 3),
            ],
        ]);
    }

    public function update(Request $request, StudyLogger $log): RedirectResponse
    {
        $data = $request->validate([
            'weights' => ['required', 'array'],
            'weights.I' => ['required', 'integer', 'between:0,100'],
            'weights.U' => ['required', 'integer', 'between:0,100'],
            'weights.D' => ['required', 'integer', 'between:0,100'],
            'weights.P' => ['required', 'integer', 'between:0,100'],
            'horizonDays' => ['sometimes', 'numeric', 'between:1,30'],
            'hoursPerDay' => ['sometimes', 'numeric', 'between:0.5,16'],
        ]);
        abort_unless(array_sum($data['weights']) === 100, 422, 'The weights must add up to 100%.');
        $profile = $request->user()->weights();
        $before = $profile->weights()->toPercentages();
        $w = Weights::fromPercentages($data['weights']);
        $profile->update([
            'importance' => $w->get('I'), 'urgency' => $w->get('U'), 'deadline' => $w->get('D'), 'pressure' => $w->get('P'),
            'horizon_days' => $data['horizonDays'] ?? $profile->horizon_days,
            'hours_per_day' => $data['hoursPerDay'] ?? $profile->hours_per_day,
        ]);
        $log->weights($request->user(), $before, $data['weights']);

        return back();
    }

    public function reset(Request $request, StudyLogger $log): RedirectResponse
    {
        $profile = $request->user()->weights();
        $before = $profile->weights()->toPercentages();
        $profile->update(['importance' => 0.42, 'urgency' => 0.23, 'deadline' => 0.23, 'pressure' => 0.12, 'horizon_days' => 7, 'hours_per_day' => 4]);
        $log->weights($request->user(), $before, ['I' => 42, 'U' => 23, 'D' => 23, 'P' => 12]);

        return back()->with('toast', 'Weights are back to the report’s defaults.');
    }
}
