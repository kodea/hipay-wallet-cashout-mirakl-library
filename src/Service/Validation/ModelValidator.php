<?php

namespace HiPay\Wallet\Mirakl\Service\Validation;

use HiPay\Wallet\Mirakl\Exception\UnauthorizedModificationException;
use HiPay\Wallet\Mirakl\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Validate models using the annotation in the interfaces and concrete classes
 * Used when validating the vendor and operation interface implementation
 * and the soap models before sending.
 *
 * @author    Ivanis Kouamé <ivanis.kouame@smile.fr>
 * @copyright 2015 Smile
 */
abstract class ModelValidator
{
    /** @var ValidatorInterface */
    protected static $validator;

    /**
     * Validate an object (Basic check).
     *
     * @param mixed $object the object to validate
     * @param bool|null|string|string[] $groups
     *
     * @throws ValidationFailedException
     */
    public static function validate($object, $groups = null)
    {
        static::initialize();
        $errors = static::$validator->validate($object, null, $groups);
        if ($errors->count() != 0) {
            //Throw new exception containing the errors
            throw new ValidationFailedException($errors, $object);
        }
    }

    /**
     * Check an object data against old values.
     *
     * @param $object
     * @param array $array
     *
     * @throws UnauthorizedModificationException
     */
    public static function checkImmutability($object, array $array)
    {
        $exception = new UnauthorizedModificationException($object);
        foreach ($array as $key => $previousValue) {
            $methodName = 'get'.ucfirst($key);
            if ($previousValue != $object->$methodName()) {
                $exception->addModifiedProperty($key);
            }
        }

        if ($exception->hasModifiedProperty()) {
            throw $exception;
        }
    }

    /**
     * Initialize the validator.
     */
    public static function initialize()
    {
        if (!static::$validator) {
            static::$validator = Validation::createValidatorBuilder()
                ->enableAnnotationMapping()
                ->getValidator();
        }
    }
}
