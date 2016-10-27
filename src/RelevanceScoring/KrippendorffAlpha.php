<?php

namespace WikiMedia\RelevanceScoring;

/**
 * Implementation of Krippendorff's alpha, a measure of inter-rater reliability.
 *
 * From "Computing Krippendorff's Alpha-Reliability" by Klaus Krippendorff,
 * http://repository.upenn.edu/cgi/viewcontent.cgi?article=1043&context=asc_papers
 */
class KrippendorffAlpha
{
    public static function ordinal(array $data, $min, $max)
    {
        $metric = function ($c, $k, array $Nc) {
            if ($c === $k) {
                return 0;
            }
            $sum = 0;
            foreach (range($c, $k) as $g) {
                $sum += $Nc[$g];
            }

            return $sum - ($Nc[$c] + $Nc[$k]) / 2;
        };

        return self::calculate($data, $min, $max, $metric);
    }

    /**
     * This is probably not the most efficient way to do all this, in fact the
     * paper seems to suggest a simpler way at the end, in section E, which
     * bypasses the coincidence matrices. But I couldn't get that to work quite
     * right...so we have this more complicated form.
     */
    private static function calculate(array $data, $min, $max, $metric)
    {
        // It is assumed $data is already in the form of the 'reliability data matrix'
        // from C1. Missing data is represented by nulls.

        // Number of observers valuing a unit
        $Mu = array_pad([], count($data[0]), 0);
        foreach ($data as $observer => $grades) {
            foreach ($grades as $u => $grade) {
                if ($grade !== null) {
                    ++$Mu[$u];
                }
            }
        }

        // Count the number of c-k pairs in unit u
        $pairs = array_fill(
            0,
            count($data[0]),
            array_fill(
                $min,
                ($max - $min) + 1,
                array_fill(
                    $min,
                    ($max - $min) + 1,
                    0
                )
            )
        );
        foreach ($data as $observer1 => $units1) {
            foreach ($data as $observer2 => $units2) {
                if ($observer1 === $observer2) {
                    continue;
                }
                for ($u = 0; $u < count($units1); ++$u) {
                    $c = $units1[$u];
                    $k = $units2[$u];
                    if ($c !== null && $k !== null) {
                        ++$pairs[$u][$c][$k];
                    }
                }
            }
        }

        // Calculate the coincidence matrix
        $Ock = [];
        foreach (range($min, $max) as $c) {
            foreach (range($c, $max) as $k) {
                $Ock[$c][$k] = 0;
                for ($u = 0; $u < count($data[0]); ++$u) {
                    if ($pairs[$u][$c][$k]) {
                        $Ock[$c][$k] += $pairs[$u][$c][$k] / ($Mu[$u] - 1);
                    }
                }
                $Ock[$k][$c] = $Ock[$c][$k];
            }
        }

        $Nc = [];
        foreach ($Ock as $c => $row) {
            $Nc[$c] = array_sum($row);
        }
        $N = array_sum($Nc);

        // calculate difference observed
        $Do = 0;
        for ($c = $min; $c <= $max; ++$c) {
            for ($k = $c + 1; $k <= $max; ++$k) {
                $Do += $Ock[$c][$k] * pow($metric($c, $k, $Nc), 2);
            }
        }

        // difference expected by random chance
        $De = 0;
        for ($c = $min; $c <= $max; ++$c) {
            for ($k = $c + 1; $k <= $max; ++$k) {
                $De += $Nc[$c] * $Nc[$k] * pow($metric($c, $k, $Nc), 2);
            }
        }

        return 1 - ($N - 1) * $Do / $De;
    }
}
