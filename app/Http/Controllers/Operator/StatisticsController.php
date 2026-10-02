<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Operator\SupportStatistics;
use App\Support\DurationFormatter;
use Illuminate\View\View;

final class StatisticsController extends Controller
{
    public function __invoke(
        SupportStatistics $statistics,
        DurationFormatter $durationFormatter,
    ): View {
        $snapshot = $statistics->allTime();
        $seconds = $snapshot['average_operator_response_seconds'];

        return view('operator.statistics.index', [
            ...$snapshot,
            'average_operator_response' => $seconds === null
                ? null
                : $durationFormatter->formatSeconds($seconds),
        ]);
    }
}
