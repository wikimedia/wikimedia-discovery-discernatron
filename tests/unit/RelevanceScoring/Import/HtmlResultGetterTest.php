<?php

namespace WikiMedia\RelevanceScoring\Import;

class HtmlResultGetterTest extends \PHPUnit_Framework_TestCase
{
    public static function somethingProvider()
    {
        $selectors = [
            'is_valid' => 'body',
            'results' => 'li',
            'url' => 'a',
            'snippet' => 'p',
        ];

        $genHtml = function (array $results) {
            $content = '';
            foreach ($results as $url => $snippet) {
                $content .= "<li><a href='$url'>some text</a>";
                $content .= "<p>$snippet</p></li>";
            }

            return "<html><head></head><body><ul>$content</ul></body></html>";
        };

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
                    'Katsuhisa Hōki is a Japanese voice actor and actor from Nagasaki Prefecture. He is affiliated .... (2004) (Great Devil King); Pocket Monsters Advanced Generation the Movie: The Pokémon Ranger and Prince of the ... The Specialist (Joe Leon); Stargate SG-1 (George Hammond); Starsky and Hutch (Captain Harold Dobey) ...',
                    0
                )],
            ],
        ];
    }

    /**
     * @dataProvider somethingProvider
     */
    public function testSomething(array $selectors, $html, $expected)
    {
        $client = $this->getMock('GuzzleHTTP\\Client');
        $response = $this->getMock('Psr\\Http\\Message\\ResponseInterface');
        $response->expects($this->any())
            ->method('getStatusCode')
            ->will($this->returnValue(200));
        $response->expects($this->any())
            ->method('getBody')
            ->will($this->returnValue($html));
        $response->expects($this->any())
            ->method('hasHeader')
            ->with('Content-Type')
            ->will($this->returnValue(true));
        $response->expects($this->any())
            ->method('getHeader')
            ->with('Content-Type')
            ->will($this->returnValue(['text/html; charset=UTF-8']));

        $getter = new HtmlResultGetter(
            $client,
            ['testwiki' => 'https://test.wikipedia.org/w/api.php'],
            'unittest',
            'https://test.wikipedia.org/w/index.php',
            $selectors,
            []
        );

        $this->assertEquals($expected, $getter->handleResponse($response, 'testwiki', ''));
    }
}
