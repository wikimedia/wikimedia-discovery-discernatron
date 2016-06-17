<?php

namespace WikiMedia\RelevanceScoring\Import;

class HtmlResultGetterTest extends \PHPUnit_Framework_TestCase
{
    public static function responseHandlingProvider()
    {
        $selectors = [
            'is_valid' => 'body',
            'results' => 'li',
            'url' => 'a',
            'snippet' => ['p'],
        ];

        $genHtml = function (array $results) {
            $content = '';
            foreach ($results as $url => $snippet) {
                $content .= "<li><a href='$url'>some text</a>";
                $content .= "<p>$snippet</p></li>";
            }

            return "<html><head></head><body><ul>$content</ul></body></html>";
        };

        $start = ImportedResult::START_HIGHLIGHT_MARKER;
        $end = ImportedResult::END_HIGHLIGHT_MARKER;

        return [
            'simple wiki article' => [
                $selectors,
                $genHtml(['https://test.wikipedia.org/wiki/Subject' => 'blah blah blah']),
                // expected results
                [new ImportedResult('unittest', 'Subject', 'blah blah blah', 0)],
            ],
            'article in query string' => [
                $selectors,
                $genHtml(['https://test.wikipedia.org/w/index.php?title=Other' => 'foo bar baz']),
                [new ImportedResult('unittest', 'Other', 'foo bar baz', 0)],
            ],
            'multiple articles' => [
                $selectors,
                $genHtml([
                    'https://test.wikipedia.org/wiki/Other' => 'foo bar baz',
                    'https://test.wikipedia.org/w/index.php?title=Thing' => 'bamboozle',
                ]),
                [
                    new ImportedResult('unittest', 'Other', 'foo bar baz', 0),
                    new ImportedResult('unittest', 'Thing', 'bamboozle', 1),
                ],
            ],
            'decodes entities' => [
                $selectors,
                $genHtml(['https://test.wikipedia.org/wiki/This_%26_That' => 'a &gt; b']),
                [new ImportedResult('unittest', 'This & That', 'a > b', 0)],
            ],
            'ignores unexpected urls' => [
                $selectors,
                $genHtml([
                    'https://test.wikipedia.org/?search=stuff' => 'fofofofo',
                    'https://not.us/wiki/Coffee' => 'tea',
                    'https://test.wikipedia.org/wiki/' => 'still wrong',
                ]),
                [],
            ],
            'properly extracts utf8' => [
                $selectors,
                file_get_contents(__DIR__.'/../../../fixtures/utf8_001.html'),
                [new ImportedResult(
                    'unittest',
                    'Katsuhisa Hōki',
                    "Katsuhisa Hōki is a Japanese voice actor and actor from Nagasaki Prefecture. He is affiliated .... (2004) (Great {$start}Devil{$end} King); Pocket Monsters Advanced Generation the Movie: The Pokémon Ranger and Prince of the ... The Specialist (Joe Leon); Stargate SG-1 (George Hammond); {$start}Starsky and Hutch{$end} (Captain Harold Dobey) ...",
                    0
                )],
            ],
            'converts snippet highlighting' => [
                $selectors,
                $genHtml([
                    'https://test.wikipedia.org/wiki/Airplane' => '<a>some <em>bold</em> text</a>',
                ]),
                [new ImportedResult(
                    'unittest',
                    'Airplane',
                    "some {$start}bold{$end} text",
                    0
                )],
            ],
        ];
    }

    /**
     * @dataProvider responseHandlingProvider
     */
    public function testResponseHandling(array $selectors, $html, $expected)
    {
        $client = $this->getMock('GuzzleHTTP\\Client');
        $getter = new HtmlResultGetter(
            $client,
            ['testwiki' => 'https://test.wikipedia.org/w/api.php'],
            'unittest',
            'https://test.wikipedia.org/w/index.php',
            $selectors,
            '<em>',
            '</em>',
            []
        );

        $response = new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
            $html
        );
        $this->assertEquals($expected, $getter->handleResponse($response, 'testwiki', ''));
    }

	/**
	 * Bing can return content a few different ways. In this test bing has
	 * provided a tabbed response for the first result containing different
	 * section from the article.
	 */
	public function testBing()
	{
		$response = new \GuzzleHttp\Psr7\Response(
			200,
			['Content-Type' => 'text/html; charset=UTF-8'],
			file_get_contents( __DIR__ . '/../../../fixtures/bing_001.html' )
		);
			
		$app = include __DIR__ . '/../../../../app.php';
		$bing = $app['search.importer.bing'];
	
		$bing->handleResponse($response, 'enwiki', '');	
	}
}
