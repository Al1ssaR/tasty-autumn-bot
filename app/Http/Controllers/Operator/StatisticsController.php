<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Operator\SupportStatistics;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

final class StatisticsController extends Controller
{
    public function __invoke(SupportStatistics $statistics): View
    {
        $snapshot = $statistics->allTime();
        $seconds = $snapshot['average_operator_response_seconds'];

        return view('operator.statistics.index', [
            ...$snapshot,
            'average_operator_response' => $seconds === null
                ? null
                : Carbon::now()->subSeconds((int) round($seconds))->diffForHumans(
                    Carbon::now(),
                    syntax: Carbon::DIFF_ABSOLUTE,
                    short: true,
                    parts: 3,
                ),
        ]);
    }
}
