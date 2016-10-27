<?php

namespace WikiMedia\Test\RelevanceScoring;

use WikiMedia\RelevanceScoring\KrippendorffAlpha;

class KrippendorffAlphaTest extends \PHPUnit_Framework_TestCase
{
    public function ordinalProvider()
    {
        return [
            "example from krippendorff's paper" => [
                0.815,
                1, 5,
                [
                    [1,    2, 3, 3, 2, 1, 4, 1, 2, null, null, null],
                    [1,    2, 3, 3, 2, 2, 4, 1, 2, 5,    null, 3],
                    [null, 3, 3, 3, 2, 3, 4, 2, 2, 5,    1,    null],
                    [1,    2, 3, 3, 2, 4, 4, 1, 2, 5,    1,    null],
                ],
            ],
            'calculated at http://dfreelon.org/utils/recalfront/recal-oir/' => [
                0.743,
                0, 3,
                [
                    [1, 0, 1, 1, 3, 2, 1, 2, 2, 2],
                    [2, 0, 1, 0, 2, 3, 2, 1, 2, 2],
                    [1, 0, 1, 0, 3, 3, 2, 2, 2, 2],
                    [2, 1, 1, 0, 3, 3, 2, 2, 1, 2],
                ],
            ],
        ];
    }

    /**
     * @dataProvider ordinalProvider
     */
    public function testOrdinal($expected, $min, $max, $data)
    {
        $this->assertEquals(
            round($expected, 3),
            round(KrippendorffAlpha::ordinal($data, $min, $max), 3)
        );
    }
}
