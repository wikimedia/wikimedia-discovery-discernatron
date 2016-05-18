<?php

namespace WikiMedia\RelevanceScoring;

// This class name sucks
class RelevanceScoringTwigExtension extends \Twig_Extension
{
    public function getName()
    {
        return 'relevaneScoring';
    }

    public function getFilters()
    {
        return [
            new \Twig_SimpleFilter(
                'highlight_snippet',
                function ($snippet) {
                    // @todo 
                    return strtr($snippet, [
                        Import\ImportedResult::START_HIGHLIGHT_MARKER => '<em>',
                        Import\ImportedResult::END_HIGHLIGHT_MARKER => '</em>',
                    ]);
                },
                ['pre_escape' => 'html', 'is_safe' => ['html']]
            ),
        ];
    }
}
