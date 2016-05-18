<?php

namespace WikiMedia\Test\RelevanceScoring;

use WikiMedia\RelevanceScoring\Import\ImportedResult;
use WikiMedia\RelevanceScoring\RelevanceScoringTwigExtension;

class RelevanceScoringTwigExtensionTest extends \PHPUnit_Framework_TestCase
{
    public function somethingProvider()
    {
        $start = ImportedResult::START_HIGHLIGHT_MARKER;
        $end = ImportedResult::END_HIGHLIGHT_MARKER;

        return [
            'simple usage of filter' => [
                'foo {{bar|highlight_snippet}} baz',
                ['bar' => "something {$start}otherthing{$end}"],
                'foo something <em>otherthing</em> baz',
            ],
            'snippet appropriately escaped' => [
                'foo {{bar|highlight_snippet}} baz',
                ['bar' => '<a>'],
                'foo &lt;a&gt; baz',
            ],
        ];
    }

    /**
     * @dataProvider somethingProvider
     */
    public function testSomething($template, $params, $expected)
    {
        $twig = new \Twig_Environment(new \Twig_Loader_Array([
            'unittest' => $template,
        ]));
        $twig->addExtension(new RelevanceScoringTwigExtension());
        $this->assertEquals($expected, $twig->render('unittest', $params));
    }
}
