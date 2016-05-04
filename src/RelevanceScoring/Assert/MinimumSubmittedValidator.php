<?php

namespace WikiMedia\RelevanceScoring\Assert;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class MinimumSubmittedValidator extends ConstraintValidator
{
    public function validate($values, Constraint $constraint)
    {
        if (!$constraint instanceof MinimumSubmitted) {
            throw new UnexpectedTypeException($constraint, __NAMESPACE__.'\MinimumSubmitted');
        }

        $have = count(array_filter($values, function ($val) { return $val !== null; }));
        $allowed = $constraint->getMinimumAllowed(count($values));

        if ($have < $allowed) {
            $this->context->addViolation(
                'Recieved {{ have }} ratings. At least {{ allowed }} results must be rated',
                array(
                    '{{ allowed }}' => $allowed,
                    '{{ have }}' => $have,
                )
            );
        }
    }
}
