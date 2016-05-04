<?php

namespace WikiMedia\RelevanceScoring\Assert;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Exception\ConstraintDefinitionException;

class MinimumSubmitted extends Constraint
{
    /**
     * @var string|int
     */
    public $minimum;

    /**
     * {@inheritdoc}
     */
    public function getDefaultOption()
    {
        return 'minimum';
    }

    /**
     * {@inheritdoc}
     */
    public function getTargets()
    {
        return self::CLASS_CONSTRAINT;
    }

    public function getMinimumAllowed($have)
    {
        if (ctype_digit($this->minimum)) {
            return $this->minimum;
        }

        $percent = $this->percentageAsFloat();
        if ($percent !== null) {
            return (int) ceil($have * $percent);
        }

        throw new ConstraintDefinitionException('Constraint must be an integer or a percentage between 0% and 100%');
    }

    private function percentageAsFloat()
    {
        if (substr($this->minimum, -1) !== '%') {
            return;
        }
        $percent = substr($this->minimum, 0, -1);
        if (ctype_digit($percent) && $percent > 0 && $percent <= 100) {
            return $percent / 100;
        }

        return;
    }
}
